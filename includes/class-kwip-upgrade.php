<?php
/**
 * One-time data upgrades, tracked with the kwip_db_version option.
 *
 * Version 1: the plugin was renamed from "Guanzon WebP Image" (gwip_*) to
 * "KWIP Image" (kwip_*). Stored names are moved to the new prefix.
 *
 * Every step is safe to repeat. The version is stored only when all steps
 * worked, so a failed upgrade is retried on the next admin page load.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Upgrade {

	public const DB_VERSION = 2;

	private const OPTION      = 'kwip_db_version';
	private const LOCK        = 'kwip_lock_upgrade';
	private const LOCK_TTL    = 300;
	private const MISSING     = '__kwip_missing__';
	private const OLD_PREFIX  = 'gwip_';
	private const NEW_PREFIX  = 'kwip_';
	private const STATUSES    = array( 'converted', 'partial', 'skipped', 'failed' );

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ), 5 );
	}

	public static function maybe_run(): void {
		$from = (int) get_option( self::OPTION, 0 );
		if ( $from >= self::DB_VERSION || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			if ( $from < 1 ) {
				if ( ! self::to_v1() ) {
					return;
				}
				update_option( self::OPTION, 1 );
			}
			if ( $from < 2 ) {
				if ( ! self::to_v2() ) {
					return;
				}
				update_option( self::OPTION, 2 );
			}
		} finally {
			delete_option( self::LOCK );
		}
	}

	private static function to_v1(): bool {
		global $wpdb;

		self::move_option( 'gwip_settings', KWIP_Settings::OPTION, true );
		self::move_option( 'gwip_scan_history', 'kwip_scan_history', false );

		delete_option( 'gwip_queue_run_lock' );
		foreach ( array( 'gwip_savings', 'gwip_routes_2', 'gwip_problem_count' ) as $transient ) {
			delete_transient( $transient );
		}
		foreach ( array( 'gwip_lock_', '_transient_gwip_', '_transient_timeout_gwip_' ) as $prefix ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}

		$changed = 0;
		foreach ( array( 'data', 'queued', 'attempts' ) as $suffix ) {
			$old = '_' . self::OLD_PREFIX . $suffix;
			$new = '_' . self::NEW_PREFIX . $suffix;

			$moved = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} AS o
					LEFT JOIN {$wpdb->postmeta} AS n ON n.post_id = o.post_id AND n.meta_key = %s
					SET o.meta_key = %s
					WHERE o.meta_key = %s AND n.meta_id IS NULL",
					$new,
					$new,
					$old
				)
			);
			if ( false === $moved ) {
				return false;
			}

			$removed = $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $old ) );
			if ( false === $removed ) {
				return false;
			}
			$changed += (int) $moved + (int) $removed;
		}

		if ( $changed > 0 ) {
			wp_cache_flush();
		}

		self::fix_record_codes();

		wp_clear_scheduled_hook( 'gwip_process_queue' );
		if ( KWIP_Queue::pending_count() > 0 ) {
			KWIP_Queue::schedule( 10 );
		}

		if ( KWIP_Delivery::remove_legacy_rules() ) {
			KWIP_Delivery::apply();
		}

		KWIP_Stats::flush();
		KWIP_Scan::flush_routes();

		return true;
	}

	/** Mirror each record's status into its own meta, so queries need no search inside serialized text. */
	private static function to_v2(): bool {
		global $wpdb;
		foreach ( self::STATUSES as $status ) {
			$like = '%' . $wpdb->esc_like( 'i:1;s:6:"status";s:' . strlen( $status ) . ':"' . $status . '";' ) . '%';

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
					SELECT DISTINCT d.post_id, %s, %s
					FROM {$wpdb->postmeta} AS d
					LEFT JOIN {$wpdb->postmeta} AS s ON s.post_id = d.post_id AND s.meta_key = %s
					WHERE d.meta_key = %s AND d.meta_value LIKE %s AND s.meta_id IS NULL",
					KWIP_Converter::META_STATUS,
					$status,
					KWIP_Converter::META_STATUS,
					KWIP_Converter::META_KEY,
					$like
				)
			);
		}

		if ( ! self::sweep_status( microtime( true ) + 8 ) ) {
			return false;
		}

		wp_cache_flush();
		KWIP_Stats::flush();
		return true;
	}

	/**
	 * Give every record that still has no status one, reading it properly.
	 * Records that are damaged or unrecognised get "skipped", so they count
	 * as neither optimized nor a problem.
	 *
	 * @return bool True when nothing is left to do.
	 */
	private static function sweep_status( float $deadline ): bool {
		while ( microtime( true ) < $deadline ) {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'     => KWIP_Converter::META_KEY,
							'compare' => 'EXISTS',
						),
						array(
							'key'     => KWIP_Converter::META_STATUS,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
			if ( empty( $ids ) ) {
				return true;
			}

			foreach ( $ids as $id ) {
				$record = get_post_meta( (int) $id, KWIP_Converter::META_KEY, true );
				$status = ( is_array( $record ) && isset( $record['status'] ) && is_string( $record['status'] ) && in_array( $record['status'], self::STATUSES, true ) )
					? $record['status']
					: 'skipped';
				update_post_meta( (int) $id, KWIP_Converter::META_STATUS, $status );
			}
		}
		return false;
	}

	/** Copy an option to its new name unless the new one exists, then drop the old one. */
	private static function move_option( string $old, string $new, bool $autoload ): void {
		$value = get_option( $old, self::MISSING );
		if ( self::MISSING === $value ) {
			return;
		}
		if ( self::MISSING === get_option( $new, self::MISSING ) ) {
			add_option( $new, $value, '', $autoload );
		}
		delete_option( $old );
	}

	private static function fix_record_codes(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => KWIP_Converter::META_KEY,
						'value'   => self::OLD_PREFIX,
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			$record = get_post_meta( (int) $id, KWIP_Converter::META_KEY, true );
			if ( ! is_array( $record ) || empty( $record['files'] ) || ! is_array( $record['files'] ) ) {
				continue;
			}

			$dirty = false;
			foreach ( $record['files'] as $name => $file ) {
				if ( is_array( $file ) && isset( $file['code'] ) && is_string( $file['code'] ) && str_starts_with( $file['code'], self::OLD_PREFIX ) ) {
					$record['files'][ $name ]['code'] = self::NEW_PREFIX . substr( $file['code'], strlen( self::OLD_PREFIX ) );
					$dirty                            = true;
				}
			}
			if ( $dirty ) {
				update_post_meta( (int) $id, KWIP_Converter::META_KEY, $record );
			}
		}
	}

	private static function acquire_lock(): bool {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		$started = (int) get_option( self::LOCK, 0 );
		if ( $started > 0 && ( time() - $started ) > self::LOCK_TTL ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time(), '', false );
		}
		return false;
	}
}