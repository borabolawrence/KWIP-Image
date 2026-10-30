<?php
/**
 * Background conversion queue (WP-Cron based).
 *
 * Queue entries are attachment meta: _kwip_queued holds the earliest time
 * (unix timestamp) the image may be processed. add_post_meta() with the
 * "unique" flag makes duplicate jobs impossible.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Queue {

	public const HOOK = 'kwip_process_queue';

	private const META_QUEUED   = '_kwip_queued';
	private const META_ATTEMPTS = '_kwip_attempts';
	private const MAX_ATTEMPTS  = 3;
	private const BATCH_SECONDS = 20;
	private const RUN_LOCK      = 'kwip_queue_run_lock';
	private const RUN_LOCK_TTL  = 300;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'process_cron' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate' ), 99, 2 );
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'on_update' ), 99, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------ */

	/**
	 * Runs after WordPress generates sizes: new uploads and regenerated thumbnails.
	 *
	 * @param mixed $metadata      Attachment metadata (returned unchanged).
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function on_generate( $metadata, $attachment_id ) {
		$id = (int) $attachment_id;
		if ( ! self::is_candidate( $id ) ) {
			return $metadata;
		}

		$had_record = is_array( get_post_meta( $id, KWIP_Converter::META_KEY, true ) );
		if ( $had_record ) {
			KWIP_Converter::remove_attachment_webp( $id );
		}

		$settings = KWIP_Settings::get();
		if ( ! empty( $settings['auto_convert'] ) || $had_record ) {
			self::enqueue( $id );
		}

		return $metadata;
	}

	/**
	 * Runs whenever attachment metadata is saved. If the set of generated
	 * files no longer matches what was converted (for example after an edit
	 * in WordPress's image editor), remove the stale WebPs and re-queue.
	 *
	 * @param mixed $data          New metadata (returned unchanged).
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function on_update( $data, $attachment_id ) {
		$id     = (int) $attachment_id;
		$record = get_post_meta( $id, KWIP_Converter::META_KEY, true );

		if ( ! is_array( $data ) || ! is_array( $record ) || empty( $record['files'] ) || ! is_array( $record['files'] ) ) {
			return $data;
		}

		$current = array();
		if ( ! empty( $data['file'] ) && is_string( $data['file'] ) ) {
			$current[] = wp_basename( $data['file'] );
		}
		if ( ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
			foreach ( $data['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$current[] = wp_basename( $size['file'] );
				}
			}
		}
		$current  = array_values( array_unique( $current ) );
		$recorded = array_map( 'strval', array_keys( $record['files'] ) );
		sort( $current );
		sort( $recorded );

		if ( $current !== $recorded ) {
			KWIP_Converter::remove_attachment_webp( $id );
			$settings = KWIP_Settings::get();
			if ( ! empty( $settings['auto_convert'] ) ) {
				self::enqueue( $id );
			}
		}

		return $data;
	}

	public static function enqueue( int $attachment_id, int $delay = 0 ): bool {
		if ( ! self::is_candidate( $attachment_id ) ) {
			return false;
		}
		$added = add_post_meta( $attachment_id, self::META_QUEUED, time() + $delay, true );
		if ( $added ) {
			self::schedule( 10 );
		}
		return (bool) $added;
	}

	public static function dequeue( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::META_QUEUED );
		delete_post_meta( $attachment_id, self::META_ATTEMPTS );
	}

	public static function is_queued( int $attachment_id ): bool {
		return '' !== (string) get_post_meta( $attachment_id, self::META_QUEUED, true );
	}

	public static function pending_count(): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => self::META_QUEUED,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}


	public static function due_count(): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => self::META_QUEUED,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	public static function clear(): void {
		delete_post_meta_by_key( self::META_QUEUED );
		delete_post_meta_by_key( self::META_ATTEMPTS );
		wp_clear_scheduled_hook( self::HOOK );
	}


	public static function process_cron(): void {
		self::process( self::BATCH_SECONDS );
	}


	/**
	 * Process due images until the time or memory budget runs out.
	 *
	 * @return array{processed:int,remaining:int,stats:array{converted:int,skipped:int,failed:int}}
	 */
	public static function process( int $max_seconds = self::BATCH_SECONDS ): array {
		$processed = 0;
		$stats     = array(
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);

		if ( self::acquire_run_lock() ) {
			try {
				$start = microtime( true );
				$seen  = array();

				while ( ( microtime( true ) - $start ) < $max_seconds && ! self::low_memory() ) {
					$ids = self::due_ids( 5, $seen );
					if ( empty( $ids ) ) {
						break;
					}
					foreach ( $ids as $id ) {
						if ( ( microtime( true ) - $start ) >= $max_seconds || self::low_memory() ) {
							break 2;
						}
						$seen[]  = (int) $id;
						$outcome = self::process_one( (int) $id );
						++$processed;
						if ( isset( $stats[ $outcome ] ) ) {
							++$stats[ $outcome ];
						}
					}
				}
			} finally {
				self::release_run_lock();
			}
		}

		$remaining = self::pending_count();
		if ( $remaining > 0 ) {
			self::schedule_next();
		}

		return array(
			'processed' => $processed,
			'remaining' => $remaining,
			'stats'     => $stats,
		);
	}


	/**
	 * @return string converted | skipped | failed | retry | busy
	 */
	private static function process_one( int $id ): string {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		$result = KWIP_Converter::convert_attachment( $id );

		if ( is_wp_error( $result ) ) {
			if ( 'kwip_locked' === $result->get_error_code() ) {
				update_post_meta( $id, self::META_QUEUED, time() + 60 );
				return 'busy';
			}
			KWIP_Converter::record_error( $id, $result );
			self::dequeue( $id );
			return 'failed';
		}

		if ( in_array( $result['status'], array( 'failed', 'partial' ), true ) ) {
			$attempts = (int) get_post_meta( $id, self::META_ATTEMPTS, true ) + 1;
			if ( $attempts < self::MAX_ATTEMPTS ) {
				update_post_meta( $id, self::META_ATTEMPTS, $attempts );
				update_post_meta( $id, self::META_QUEUED, time() + ( $attempts * 300 ) );
				return 'retry';
			}
		}

		self::dequeue( $id );

		return match ( $result['status'] ) {
			'converted', 'partial' => 'converted',
			'skipped'              => 'skipped',
			default                => 'failed',
		};
	}

	/**
	 * @param int[] $exclude IDs already handled in this run.
	 * @return int[]
	 */
	private static function due_ids( int $limit, array $exclude ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'post__not_in'   => $exclude,
				'meta_query'     => array(
					'due' => array(
						'key'     => self::META_QUEUED,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
				'orderby'        => 'due',
				'order'          => 'ASC',
			)
		);
		return array_map( 'intval', $ids );
	}


	public static function schedule( int $delay = 10 ): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK );
		}
	}

	/** Schedule the next run for when the earliest queued image is due. */
	private static function schedule_next(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'next' => array(
						'key'     => self::META_QUEUED,
						'compare' => 'EXISTS',
						'type'    => 'NUMERIC',
					),
				),
				'orderby'        => 'next',
				'order'          => 'ASC',
			)
		);
		if ( empty( $ids ) ) {
			return;
		}
		$when = (int) get_post_meta( (int) $ids[0], self::META_QUEUED, true );
		self::schedule( max( 10, $when - time() ) );
	}

	private static function is_candidate( int $id ): bool {
		return in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true );
	}

	private static function low_memory(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit > 0 && memory_get_usage( true ) > ( $limit * 0.8 );
	}

	/** Only one queue runner at a time. Stale locks expire. */
	private static function acquire_run_lock(): bool {
		if ( add_option( self::RUN_LOCK, time(), '', false ) ) {
			return true;
		}
		$started = (int) get_option( self::RUN_LOCK, 0 );
		if ( $started > 0 && ( time() - $started ) > self::RUN_LOCK_TTL ) {
			delete_option( self::RUN_LOCK );
			return add_option( self::RUN_LOCK, time(), '', false );
		}
		return false;
	}

	private static function release_run_lock(): void {
		delete_option( self::RUN_LOCK );
	}
}