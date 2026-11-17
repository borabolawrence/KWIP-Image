<?php
/**
 * KWIP_Converter: real conversions with real GD and WordPress thumbnails.
 */

return array(

	'converts a JPEG and every thumbnail size' => static function () {
		KT::prepare_conversion();
		$id    = KT::attachment( 'jpeg' );
		$files = KT::files_of( $id );
		KT::ok( count( $files ) > 1, 'the test image has thumbnail sizes (got ' . count( $files ) . ')' );
		$before = KT::hashes( $id );

		$r = KWIP_Converter::convert_attachment( $id );
		if ( is_wp_error( $r ) ) {
			KT::ok( false, 'conversion returned ' . $r->get_error_code() . ': ' . $r->get_error_message() );
			return;
		}
		KT::same( 'converted', $r['status'], 'record status' );

		foreach ( $files as $name => $path ) {
			KT::ok( is_file( $path . '.webp' ), "a WebP exists for $name" );
			KT::same( true, KWIP_Files::verify_webp( $path . '.webp' ), "the WebP for $name is valid" );
		}
		KT::same( $before, KT::hashes( $id ), 'the originals are untouched' );
		KT::same( 'converted', get_post_meta( $id, '_kwip_status', true ), 'the status meta is set' );

		$record = get_post_meta( $id, KWIP_Converter::META_KEY, true );
		KT::same( 'converted', $record['status'] ?? null, 'the record is stored' );

		$main = (string) get_attached_file( $id );
		KT::ok( filesize( $main . '.webp' ) < filesize( $main ), 'the main WebP is smaller than the JPEG' );
		KT::same( array(), glob( dirname( $main ) . '/.kwip-*' ) ?: array(), 'no temp files are left behind' );
	},

	'keeps transparency when converting a PNG' => static function () {
		KT::prepare_conversion();
		$id = KT::attachment( 'png-alpha', 600, 600 );
		$r  = KWIP_Converter::convert_attachment( $id );
		if ( is_wp_error( $r ) ) {
			KT::ok( false, 'conversion returned ' . $r->get_error_code() );
			return;
		}
		$webp = (string) get_attached_file( $id ) . '.webp';
		KT::ok( is_file( $webp ), 'a WebP was written' );

		$image = is_file( $webp ) ? @imagecreatefromwebp( $webp ) : false;
		if ( false === $image ) {
			KT::ok( false, 'the WebP can be decoded' );
			return;
		}
		$corner = ( imagecolorat( $image, 2, 2 ) >> 24 ) & 0x7F;
		$centre = ( imagecolorat( $image, 300, 300 ) >> 24 ) & 0x7F;
		KT::ok( $corner > 100, "the transparent corner is still transparent (alpha $corner of 127)" );
		KT::ok( $centre < 20, "the disc is still opaque (alpha $centre of 127)" );
	},

	'skips an image when the saving is below the minimum' => static function () {
		KT::prepare_conversion(
			array(
				'allow_larger' => false,
				'min_savings'  => 90,
			)
		);
		$id = KT::attachment( 'jpeg', 600, 400 );
		$r  = KWIP_Converter::convert_attachment( $id );
		if ( is_wp_error( $r ) ) {
			KT::ok( false, 'conversion returned ' . $r->get_error_code() );
			return;
		}
		KT::same( 'skipped', $r['status'], 'record status' );
		KT::same( array(), KT::webps( $id ), 'no WebP is kept' );
		KT::same( 'skipped', get_post_meta( $id, '_kwip_status', true ), 'status meta' );
		foreach ( $r['files'] as $name => $file ) {
			KT::ok( in_array( $file['code'], array( 'below_threshold', 'not_smaller' ), true ), "reason for $name is a size reason (got {$file['code']})" );
		}
		$main = (string) get_attached_file( $id );
		KT::same( array(), glob( dirname( $main ) . '/.kwip-*' ) ?: array(), 'no temp files are left behind' );
	},

	'restore removes the WebP files and nothing else' => static function () {
		KT::prepare_conversion();
		$id     = KT::attachment( 'jpeg', 800, 600 );
		$files  = KT::files_of( $id );
		$before = KT::hashes( $id );
		KWIP_Converter::convert_attachment( $id );
		KT::ok( count( KT::webps( $id ) ) > 0, 'there is something to restore' );

		$removed = KWIP_Converter::remove_attachment_webp( $id );
		KT::same( count( $files ), $removed, 'every WebP was removed' );
		KT::same( array(), KT::webps( $id ), 'no WebP is left' );
		KT::same( '', get_post_meta( $id, KWIP_Converter::META_KEY, true ), 'the record is gone' );
		KT::same( '', get_post_meta( $id, '_kwip_status', true ), 'the status meta is gone' );
		KT::same( $before, KT::hashes( $id ), 'the originals are untouched' );
	},

	'converting twice is safe' => static function () {
		KT::prepare_conversion();
		$id = KT::attachment( 'jpeg', 800, 600 );
		KWIP_Converter::convert_attachment( $id );
		$first = count( KT::webps( $id ) );
		$r     = KWIP_Converter::convert_attachment( $id );
		KT::same( 'converted', is_wp_error( $r ) ? $r->get_error_code() : $r['status'], 'second run status' );
		KT::same( $first, count( KT::webps( $id ) ), 'the same number of WebP files' );
	},

	'a disabled image type is skipped' => static function () {
		KT::prepare_conversion( array( 'formats' => array( 'png' ) ) );
		$id = KT::attachment( 'jpeg', 600, 400 );
		$r  = KWIP_Converter::convert_attachment( $id );
		if ( is_wp_error( $r ) ) {
			KT::ok( false, 'conversion returned ' . $r->get_error_code() );
			return;
		}
		KT::same( 'skipped', $r['status'], 'status' );
		KT::same( 'kwip_format_disabled', $r['files'][ wp_basename( (string) get_attached_file( $id ) ) ]['code'] ?? null, 'reason' );
		KT::same( array(), KT::webps( $id ), 'no WebP' );
	},

	'a non-image attachment is refused' => static function () {
		KT::prepare_conversion();
		KT::is_error( KWIP_Converter::convert_attachment( KT::text_attachment() ), 'kwip_not_supported', 'a text file' );
	},

	'a damaged JPEG never produces a WebP' => static function () {
		KT::prepare_conversion();
		$id   = KT::raw_attachment( 'bad', 'jpg', "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 300 ), 'image/jpeg' );
		$path = (string) get_attached_file( $id );
		$hash = md5_file( $path );

		$r = KWIP_Converter::convert_attachment( $id );
		KT::ok( is_wp_error( $r ) || in_array( $r['status'], array( 'failed', 'skipped' ), true ), 'the conversion fails or is skipped without crashing' );
		KT::ok( ! file_exists( $path . '.webp' ), 'no WebP was written' );
		KT::same( $hash, md5_file( $path ), 'the file is unchanged' );
	},

	'a size that stops converting loses its WebP' => static function () {
		KT::prepare_conversion();
		$id = KT::attachment( 'jpeg', 800, 600 );
		KWIP_Converter::convert_attachment( $id );
		KT::ok( count( KT::webps( $id ) ) > 0, 'WebP files exist first' );

		KT::settings(
			array(
				'allow_larger' => false,
				'min_savings'  => 90,
			)
		);
		$r = KWIP_Converter::convert_attachment( $id );
		KT::same( 'skipped', is_wp_error( $r ) ? $r->get_error_code() : $r['status'], 'the second run skips everything' );
		KT::same( array(), KT::webps( $id ), 'the stale WebP files were removed' );
	},

	'deleting an attachment removes its WebP files' => static function () {
		KT::prepare_conversion();
		$id = KT::attachment( 'jpeg', 800, 600 );
		KWIP_Converter::convert_attachment( $id );
		$webps = KT::webps( $id );
		KT::ok( count( $webps ) > 0, 'there are WebP files to remove' );

		wp_delete_attachment( $id, true );
		foreach ( $webps as $webp ) {
			KT::ok( ! file_exists( $webp ), basename( $webp ) . ' was removed with the attachment' );
		}
	},

	'a busy image is not processed twice at once' => static function () {
		KT::prepare_conversion();
		$id = KT::attachment( 'jpeg', 400, 300 );

		add_option( 'kwip_lock_' . $id, time(), '', false );
		KT::is_error( KWIP_Converter::convert_attachment( $id ), 'kwip_locked', 'a fresh lock blocks conversion' );
		delete_option( 'kwip_lock_' . $id );

		add_option( 'kwip_lock_' . $id, time() - 1000, '', false );
		$r = KWIP_Converter::convert_attachment( $id );
		KT::ok( ! is_wp_error( $r ), 'a stale lock is ignored' );
		KT::same( false, get_option( 'kwip_lock_' . $id, false ), 'the lock is released afterwards' );
	},

	'record_error stores a failure but keeps a real record' => static function () {
		KT::prepare_conversion();
		$fresh = KT::attachment( 'jpeg', 300, 200 );
		KWIP_Converter::record_error( $fresh, new WP_Error( 'kwip_test', 'boom' ) );
		KT::same( 'failed', get_post_meta( $fresh, '_kwip_status', true ), 'status meta' );
		KT::same( 'boom', get_post_meta( $fresh, KWIP_Converter::META_KEY, true )['error'] ?? null, 'error message stored' );

		$done = KT::attachment( 'jpeg', 300, 200 );
		KWIP_Converter::convert_attachment( $done );
		KWIP_Converter::record_error( $done, new WP_Error( 'kwip_test', 'boom' ) );
		KT::same( 'converted', get_post_meta( $done, '_kwip_status', true ), 'an existing record is left alone' );
	},

	'editing an image removes stale WebP files and re-queues it' => static function () {
		KT::prepare_conversion( array( 'auto_convert' => true ) );
		$id = KT::attachment( 'jpeg', 800, 600 );
		KWIP_Converter::convert_attachment( $id );
		KT::ok( count( KT::webps( $id ) ) > 0, 'WebP files exist first' );

		$meta = wp_get_attachment_metadata( $id );
		KT::ok( ! empty( $meta['sizes'] ), 'the image has sizes to remove' );
		array_shift( $meta['sizes'] );
		wp_update_attachment_metadata( $id, $meta );

		KT::same( '', get_post_meta( $id, KWIP_Converter::META_KEY, true ), 'the old record was removed' );
		KT::same( array(), KT::webps( $id ), 'the stale WebP files were removed' );
		KT::ok( KWIP_Queue::is_queued( $id ), 'the image is queued again' );
	},
		'the kwip_convert_file filter can exclude single files' => static function () {
		KT::prepare_conversion();
		$id    = KT::attachment( 'jpeg', 800, 600 );
		$files = KT::files_of( $id );
		$thumb = array_values(
			array_filter(
				$files,
				static function ( string $path ): bool {
					return str_contains( $path, '-150x150' );
				}
			)
		);
		if ( 1 !== count( $thumb ) ) {
			KT::skip( 'The test image has no 150x150 size.' );
		}

		$seen     = array();
		$callback = static function ( $convert, $path, $attachment_id ) use ( &$seen ) {
			$seen[ wp_basename( $path ) ] = $attachment_id;
			return str_contains( $path, '-150x150' ) ? false : $convert;
		};
		add_filter( 'kwip_convert_file', $callback, 10, 3 );
		$r = KWIP_Converter::convert_attachment( $id );
		remove_filter( 'kwip_convert_file', $callback, 10 );

		if ( is_wp_error( $r ) ) {
			KT::ok( false, 'conversion returned ' . $r->get_error_code() );
			return;
		}
		KT::same( 'converted', $r['status'], 'the other sizes were still converted' );
		KT::ok( ! is_file( $thumb[0] . '.webp' ), 'the excluded file has no WebP' );
		KT::same( count( $files ) - 1, count( KT::webps( $id ) ), 'every other file has one' );
		KT::same( 'kwip_excluded', $r['files'][ wp_basename( $thumb[0] ) ]['code'] ?? null, 'the reason is recorded' );
		KT::same( 'skipped', $r['files'][ wp_basename( $thumb[0] ) ]['status'] ?? null, 'it counts as skipped, not failed' );
		KT::same( count( $files ), count( $seen ), 'the filter saw every file' );
		KT::same( array( $id ), array_values( array_unique( $seen ) ), 'and always got the attachment ID' );
	},

	'excluding every file skips the image and removes old WebP files' => static function () {
		KT::prepare_conversion();
		$id = KT::attachment( 'jpeg', 800, 600 );
		KWIP_Converter::convert_attachment( $id );
		KT::ok( count( KT::webps( $id ) ) > 0, 'WebP files exist first' );

		add_filter( 'kwip_convert_file', '__return_false' );
		$r = KWIP_Converter::convert_attachment( $id );
		remove_filter( 'kwip_convert_file', '__return_false' );

		KT::same( 'skipped', is_wp_error( $r ) ? $r->get_error_code() : $r['status'], 'the image is skipped' );
		KT::same( array(), KT::webps( $id ), 'the old WebP files were removed' );
		KT::same( 'skipped', get_post_meta( $id, '_kwip_status', true ), 'the status meta follows' );
	},
	'actions fire after processing and after restoring' => static function () {
		KT::prepare_conversion();
		$id        = KT::attachment( 'jpeg', 600, 400 );
		$processed = array();
		$restored  = array();

		$on_processed = static function ( $attachment_id, $record ) use ( &$processed ) {
			$processed[] = array( $attachment_id, $record['status'] ?? null, false === get_option( 'kwip_lock_' . $attachment_id, false ) );
		};
		$on_restored  = static function ( $attachment_id, $removed ) use ( &$restored ) {
			$restored[] = array( $attachment_id, $removed, false === get_option( 'kwip_lock_' . $attachment_id, false ) );
		};
		add_action( 'kwip_attachment_processed', $on_processed, 10, 2 );
		add_action( 'kwip_attachment_restored', $on_restored, 10, 2 );

		KWIP_Converter::convert_attachment( $id );
		$removed = KWIP_Converter::remove_attachment_webp( $id );

		remove_action( 'kwip_attachment_processed', $on_processed, 10 );
		remove_action( 'kwip_attachment_restored', $on_restored, 10 );

		KT::ok( $removed > 0, 'there was something to remove' );
		KT::same( array( array( $id, 'converted', true ) ), $processed, 'processed fired once, after the lock was released' );
		KT::same( array( array( $id, $removed, true ) ), $restored, 'restored fired once, after the lock was released' );
	},
		'the Quality setting changes the size of the WebP' => static function () {
		KT::prepare_conversion();
		$id   = KT::attachment( 'jpeg-noise', 800, 600 );
		$main = (string) get_attached_file( $id ) . '.webp';

		$sizes = array();
		foreach ( array( 10, 75, 100 ) as $quality ) {
			KT::settings( array( 'quality' => $quality ) );
			$r = KWIP_Converter::convert_attachment( $id );
			if ( is_wp_error( $r ) ) {
				KT::ok( false, 'conversion returned ' . $r->get_error_code() );
				return;
			}
			clearstatcache( true, $main );
			$sizes[ $quality ] = is_file( $main ) ? filesize( $main ) : 0;
		}

		KT::ok(
			$sizes[10] > 0 && $sizes[10] < $sizes[75] && $sizes[75] < $sizes[100],
			'a higher quality gives a larger file: ' . wp_json_encode( $sizes )
		);
	},

	'the kwip_webp_quality filter overrides the Settings quality' => static function () {
		KT::prepare_conversion();
		$id   = KT::attachment( 'jpeg-noise', 800, 600 );
		$main = (string) get_attached_file( $id ) . '.webp';

		// What Settings quality 10 produces.
		KT::settings( array( 'quality' => 10 ) );
		KWIP_Converter::convert_attachment( $id );
		clearstatcache( true, $main );
		$at_ten = is_file( $main ) ? filesize( $main ) : 0;

		// Settings say 75, the filter says 10.
		KT::settings( array( 'quality' => 75 ) );
		$seen = array();
		$spy  = static function ( $quality, $file ) use ( &$seen ) {
			$seen[ wp_basename( $file ) ] = $quality;
			return 10;
		};
		add_filter( 'kwip_webp_quality', $spy, 10, 2 );
		$r = KWIP_Converter::convert_attachment( $id );
		remove_filter( 'kwip_webp_quality', $spy, 10 );

		KT::ok( ! is_wp_error( $r ) && 'converted' === $r['status'], 'the conversion succeeded' );
		KT::ok( count( $seen ) > 0, 'the filter ran for every file' );
		KT::same( 75, reset( $seen ) ?: null, 'the filter received the quality from Settings' );
		clearstatcache( true, $main );
		$filtered = is_file( $main ) ? filesize( $main ) : 0;
		KT::ok( $at_ten > 0 && $at_ten === $filtered, "the filter's quality was used ($at_ten bytes at quality 10, $filtered through the filter)" );
	},

	'a quality outside 1 to 100 is clamped, not rejected' => static function () {
		KT::prepare_conversion();
		$id   = KT::attachment( 'jpeg-noise', 800, 600 );
		$main = (string) get_attached_file( $id ) . '.webp';

		KT::settings( array( 'quality' => 100 ) );
		KWIP_Converter::convert_attachment( $id );
		clearstatcache( true, $main );
		$at_hundred = is_file( $main ) ? filesize( $main ) : 0;

		KT::settings( array( 'quality' => 75 ) );
		$wild = static function () {
			return 500;
		};
		add_filter( 'kwip_webp_quality', $wild );
		$r = KWIP_Converter::convert_attachment( $id );
		remove_filter( 'kwip_webp_quality', $wild );

		KT::ok( ! is_wp_error( $r ) && 'converted' === $r['status'], 'the conversion succeeded' );
		clearstatcache( true, $main );
		KT::same( $at_hundred, is_file( $main ) ? filesize( $main ) : 0, 'a quality of 500 behaves like 100' );
	},
);