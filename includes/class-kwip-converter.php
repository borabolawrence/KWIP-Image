<?php
/**
 * Single-attachment conversion engine.
 *
 * WebP files are written next to the originals as "<file>.webp"
 * (photo.jpg -> photo.jpg.webp). Originals are never modified, so restoring
 * means removing the WebP files and the stored record.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Converter {

	/** Post meta key holding the conversion record. */
	public const META_KEY = '_kwip_data';

	/** Seconds before a lock from a crashed run is considered stale. */
	private const LOCK_TTL = 300;

	public static function init(): void {
		// Do not leave orphaned .webp files behind when an attachment is deleted.
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
	}

	public static function on_delete_attachment( $attachment_id ): void {
		self::purge( (int) $attachment_id );
	}

	/**
	 * Convert one attachment (main file plus all generated sizes).
	 *
	 * Record format (also stored in post meta):
	 * status: converted | partial | skipped | failed
	 * files:  basename => status (converted|skipped|failed), code, message,
	 *         original, webp, saved_percent
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function convert_attachment( int $attachment_id ): array|WP_Error {
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return new WP_Error( 'kwip_not_supported', __( 'Only JPEG and PNG images can be converted.', 'kwip-image-plugin' ) );
		}

		$report = KWIP_Environment::get_report();
		if ( ! $report['wp_webp'] ) {
			return new WP_Error( 'kwip_no_webp', __( 'This server cannot write WebP images.', 'kwip-image-plugin' ) );
		}

		$files = self::collect_files( $attachment_id );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		if ( ! self::acquire_lock( $attachment_id ) ) {
			return new WP_Error( 'kwip_locked', __( 'This image is already being processed.', 'kwip-image-plugin' ) );
		}

		try {
			wp_raise_memory_limit( 'image' );

			$settings       = KWIP_Settings::get();
			$previous       = get_post_meta( $attachment_id, self::META_KEY, true );
			$previous_files = ( is_array( $previous ) && isset( $previous['files'] ) && is_array( $previous['files'] ) ) ? $previous['files'] : array();

			$results        = array();
			$counts         = array(
				'converted' => 0,
				'skipped'   => 0,
				'failed'    => 0,
			);
			$original_total = 0;
			$webp_total     = 0;

			foreach ( $files as $name => $path ) {
				$result = self::convert_file( $path, $settings );

				if ( 'converted' !== $result['status']
					&& isset( $previous_files[ $name ]['status'] )
					&& 'converted' === $previous_files[ $name ]['status'] ) {
					self::delete_webp( KWIP_Files::webp_path( $path ) );
				}

				++$counts[ $result['status'] ];
				if ( 'converted' === $result['status'] ) {
					$original_total += $result['original'];
					$webp_total     += $result['webp'];
				}
				$results[ $name ] = $result;
			}

			if ( $counts['converted'] > 0 ) {
				$status = $counts['failed'] > 0 ? 'partial' : 'converted';
			} else {
				$status = $counts['failed'] > 0 ? 'failed' : 'skipped';
			}

			$data = array(
				'version'        => 1,
				'status'         => $status,
				'time'           => time(),
				'quality'        => $settings['quality'],
				'original_total' => $original_total,
				'webp_total'     => $webp_total,
				'files'          => $results,
			);
			update_post_meta( $attachment_id, self::META_KEY, $data );
			KWIP_Stats::flush();

			return $data;
		} finally {
			self::release_lock( $attachment_id );
		}
	}

	/**
	 * Restore: remove the generated WebP files and the record. The original
	 * files were never touched, so nothing else needs to be put back.
	 *
	 * @return int|WP_Error Number of WebP files removed.
	 */
	public static function remove_attachment_webp( int $attachment_id ): int|WP_Error {
		if ( ! self::acquire_lock( $attachment_id ) ) {
			return new WP_Error( 'kwip_locked', __( 'This image is already being processed.', 'kwip-image-plugin' ) );
		}
		try {
			return self::purge( $attachment_id );
		} finally {
			self::release_lock( $attachment_id );
		}
	}



	 /**
	 * Store a failure record for errors raised before any file was processed.
	 * An existing record that tracks generated files is left alone so those
	 * files stay known and removable.
	 */
	public static function record_error( int $attachment_id, WP_Error $error ): void {
		$existing = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( is_array( $existing ) && ! empty( $existing['files'] ) ) {
			return;
		}
		update_post_meta(
			$attachment_id,
			self::META_KEY,
			array(
				'version'        => 1,
				'status'         => 'failed',
				'time'           => time(),
				'original_total' => 0,
				'webp_total'     => 0,
				'error'          => $error->get_error_message(),
				'files'          => array(),
			)
		);
	}

	/**
	 * @return array<string, string>|WP_Error basename => absolute path (de-duplicated).
	 */
	private static function collect_files( int $attachment_id ): array|WP_Error {
		$main = get_attached_file( $attachment_id );
		if ( ! $main ) {
			return new WP_Error( 'kwip_no_file', __( 'The attachment has no file.', 'kwip-image-plugin' ) );
		}

		$files = array( wp_basename( $main ) => $main );
		$meta  = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = trailingslashit( dirname( $main ) );
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$name           = wp_basename( $size['file'] );
					$files[ $name ] = $dir . $name;
				}
			}
		}

		return $files;
	}

	/**
	 * Convert one file. Never throws; always returns a result array.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>
	 */
	private static function convert_file( string $source, array $settings ): array {
		$valid = KWIP_Files::validate_source( $source, $settings['formats'] );
		if ( is_wp_error( $valid ) ) {
			$skip_codes = array( 'kwip_format_disabled', 'kwip_unsupported', 'kwip_animated' );
			$status     = in_array( $valid->get_error_code(), $skip_codes, true ) ? 'skipped' : 'failed';
			return self::result( $status, $valid->get_error_code(), $valid->get_error_message() );
		}

		$original = $valid['size'];
		$dest     = KWIP_Files::webp_path( $source );

		if ( ! KWIP_Files::is_valid_destination( $dest ) ) {
			return self::result( 'failed', 'kwip_publish_invalid', __( 'The output location is not allowed.', 'kwip-image-plugin' ), $original );
		}

		$space = KWIP_Files::ensure_disk_space( dirname( $source ), $original );
		if ( is_wp_error( $space ) ) {
			return self::result( 'failed', $space->get_error_code(), $space->get_error_message(), $original );
		}

		$temp    = KWIP_Files::temp_path( $dest );
		$encoded = self::encode( $source, $valid['format'], $temp, (int) $settings['quality'] );
		if ( is_wp_error( $encoded ) ) {
			KWIP_Files::discard_temp( $temp );
			return self::result( 'failed', $encoded->get_error_code(), $encoded->get_error_message(), $original );
		}

		clearstatcache( true, $temp );
		$webp_size = (int) filesize( $temp );
		$cmp       = KWIP_Files::compare_sizes( $original, $webp_size, (int) $settings['min_savings'], ! empty( $settings['allow_larger'] ) );

		if ( ! $cmp['use_webp'] ) {
			KWIP_Files::discard_temp( $temp );
			return self::result( 'skipped', $cmp['reason'], '', $original, $webp_size, $cmp['saved_percent'] );
		}

		$published = KWIP_Files::publish( $temp, $dest );
		if ( is_wp_error( $published ) ) {
			return self::result( 'failed', $published->get_error_code(), $published->get_error_message(), $original );
		}

		return self::result( 'converted', $cmp['reason'], '', $original, $webp_size, $cmp['saved_percent'] );
	}

	/**
	 * Encode the source to a temp WebP and verify it.
	 */
	private static function encode( string $source, string $format, string $temp, int $quality ): true|WP_Error {
		try {
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}

			if ( 'jpeg' === $format ) {
				$rotated = $editor->maybe_exif_rotate();
				if ( is_wp_error( $rotated ) ) {
					return $rotated;
				}
			}

			$set = $editor->set_quality( $quality );
			if ( is_wp_error( $set ) ) {
				return $set;
			}

			$saved = $editor->save( $temp, 'image/webp' );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$size = $editor->get_size();
			return KWIP_Files::verify_webp( $temp, (int) $size['width'], (int) $size['height'] );
		} catch ( Throwable $e ) {
			return new WP_Error( 'kwip_exception', $e->getMessage() );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function result( string $status, string $code, string $message = '', int $original = 0, int $webp = 0, float $percent = 0.0 ): array {
		return array(
			'status'        => $status,
			'code'          => $code,
			'message'       => $message,
			'original'      => $original,
			'webp'          => $webp,
			'saved_percent' => $percent,
		);
	}

	/** Remove every WebP recorded for the attachment, then the record. No lock. */
	private static function purge( int $attachment_id ): int {
		$data    = get_post_meta( $attachment_id, self::META_KEY, true );
		$main    = get_attached_file( $attachment_id );
		$removed = 0;

		if ( $main && is_array( $data ) && ! empty( $data['files'] ) && is_array( $data['files'] ) ) {
			$dir = trailingslashit( dirname( $main ) );
			foreach ( array_keys( $data['files'] ) as $name ) {
				if ( self::delete_webp( $dir . wp_basename( (string) $name ) . '.webp' ) ) {
					++$removed;
				}
			}
		}

		delete_post_meta( $attachment_id, self::META_KEY );
		KWIP_Stats::flush();
		return $removed;
	}

	private static function delete_webp( string $webp ): bool {
		if ( 'webp' !== strtolower( pathinfo( $webp, PATHINFO_EXTENSION ) )
			|| ! is_file( $webp )
			|| is_link( $webp )
			|| ! KWIP_Files::is_inside_uploads( $webp ) ) {
			return false;
		}
		wp_delete_file( $webp );
		return ! file_exists( $webp );
	}

	private static function acquire_lock( int $attachment_id ): bool {
		$key = 'kwip_lock_' . $attachment_id;

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		$started = (int) get_option( $key, 0 );
		if ( $started > 0 && ( time() - $started ) > self::LOCK_TTL ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}
		return false;
	}

	private static function release_lock( int $attachment_id ): void {
		delete_option( 'kwip_lock_' . $attachment_id );
	}
}