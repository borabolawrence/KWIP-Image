<?php
/**
 * KWIP_Files: path safety, source validation, WebP verification, publishing.
 */

return array(

	'compare_sizes applies the size rules' => static function () {
		$r = KWIP_Files::compare_sizes( 1000, 900, 5, false );
		KT::same( true, $r['use_webp'], '10% smaller is used' );
		KT::same( 'ok', $r['reason'], 'reason for a good saving' );
		KT::same( 10.0, $r['saved_percent'], 'saved percent' );

		$r = KWIP_Files::compare_sizes( 1000, 1000, 5, false );
		KT::same( false, $r['use_webp'], 'same size is not used' );
		KT::same( 'not_smaller', $r['reason'], 'reason for the same size' );

		$r = KWIP_Files::compare_sizes( 1000, 990, 5, false );
		KT::same( false, $r['use_webp'], '1% smaller is below the 5% minimum' );
		KT::same( 'below_threshold', $r['reason'], 'reason for a small saving' );

		$r = KWIP_Files::compare_sizes( 1000, 1200, 5, true );
		KT::same( true, $r['use_webp'], 'a larger file is used when the override is on' );
		KT::same( 'override', $r['reason'], 'reason for the override' );
	},

	'webp_path appends .webp to the full file name' => static function () {
		KT::same( '/a/b.jpg.webp', KWIP_Files::webp_path( '/a/b.jpg' ), 'jpg' );
		KT::same( '/a/b.png.webp', KWIP_Files::webp_path( '/a/b.png' ), 'png' );
	},

	'is_inside_uploads accepts only existing paths inside uploads' => static function () {
		$base = KWIP_Files::uploads_base();
		KT::ok( KWIP_Files::is_inside_uploads( $base ), 'the uploads folder itself' );
		KT::ok( KWIP_Files::is_inside_uploads( KT::scratch() ), 'a sub folder' );
		KT::ok( ! KWIP_Files::is_inside_uploads( dirname( $base ) ), 'the parent of uploads' );
		KT::ok( ! KWIP_Files::is_inside_uploads( $base . '/../' ), 'a path that climbs out with ..' );
		KT::ok( ! KWIP_Files::is_inside_uploads( KT::scratch() . '/missing.jpg' ), 'a file that does not exist' );
		KT::ok( ! KWIP_Files::is_inside_uploads( '' ), 'an empty path' );
		KT::ok( ! KWIP_Files::is_inside_uploads( $base . "/a\0b" ), 'a path with a null byte' );

		$twin = dirname( $base ) . '/' . basename( $base ) . '-kwip-test';
		if ( @mkdir( $twin ) ) {
			KT::ok( ! KWIP_Files::is_inside_uploads( $twin ), 'a sibling folder that only shares the name prefix' );
			@rmdir( $twin );
		}
	},

	'is_valid_destination only allows new files inside uploads' => static function () {
		KT::ok( KWIP_Files::is_valid_destination( KT::scratch( 'new.webp' ) ), 'a new file in an uploads folder' );
		KT::ok( ! KWIP_Files::is_valid_destination( dirname( KWIP_Files::uploads_base() ) . '/x.webp' ), 'a file outside uploads' );
		KT::ok( ! KWIP_Files::is_valid_destination( '' ), 'an empty path' );
	},

	'file_for_url maps uploads URLs to files and rejects the rest' => static function () {
		$uploads = wp_get_upload_dir();
		$expect  = trailingslashit( $uploads['basedir'] ) . '2030/01/a b.jpg';

		KT::same( $expect, KWIP_Files::file_for_url( $uploads['baseurl'] . '/2030/01/a%20b.jpg?ver=1' ), 'decodes the name and ignores the query' );

		$other_scheme = str_starts_with( $uploads['baseurl'], 'https:' ) ? preg_replace( '#^https:#', 'http:', $uploads['baseurl'] ) : preg_replace( '#^http:#', 'https:', $uploads['baseurl'] );
		KT::same( $expect, KWIP_Files::file_for_url( $other_scheme . '/2030/01/a%20b.jpg' ), 'http and https are treated alike' );

		KT::same( null, KWIP_Files::file_for_url( 'https://example.com/wp-content/uploads/a.jpg' ), 'another site' );
		KT::same( null, KWIP_Files::file_for_url( $uploads['baseurl'] . '/../wp-config.php' ), 'a URL that climbs out of uploads' );
		KT::same( null, KWIP_Files::file_for_url( home_url( '/wp-content/themes/x/a.jpg' ) ), 'a theme image' );
	},

	'validate_source accepts real images and rejects the rest' => static function () {
		$jpg = KT::scratch( 'a.jpg' );
		KT::write_image( 'jpeg', $jpg, 40, 30 );
		$r = KWIP_Files::validate_source( $jpg, array( 'jpeg', 'png' ) );
		if ( is_wp_error( $r ) ) {
			KT::ok( false, 'a real JPEG was rejected: ' . $r->get_error_code() );
		} else {
			KT::same( 'jpeg', $r['format'], 'format' );
			KT::same( filesize( $jpg ), $r['size'], 'size' );
		}

		KT::is_error( KWIP_Files::validate_source( $jpg, array( 'png' ) ), 'kwip_format_disabled', 'a disabled type' );

		$fake = KT::scratch( 'fake.jpg' );
		file_put_contents( $fake, 'this is not an image' );
		KT::is_error( KWIP_Files::validate_source( $fake, array( 'jpeg', 'png' ) ), 'kwip_unsupported', 'text named .jpg' );

		$empty = KT::scratch( 'empty.jpg' );
		file_put_contents( $empty, '' );
		KT::ok( is_wp_error( KWIP_Files::validate_source( $empty, array( 'jpeg', 'png' ) ) ), 'an empty file' );

		KT::is_error( KWIP_Files::validate_source( KT::scratch( 'missing.jpg' ), array( 'jpeg', 'png' ) ), 'kwip_path', 'a missing file' );
		KT::is_error( KWIP_Files::validate_source( KWIP_FILE, array( 'jpeg', 'png' ) ), 'kwip_path', 'a file outside uploads' );

		$apng = KT::scratch( 'a.png' );
		KT::write_image( 'png', $apng, 10, 10 );
		file_put_contents( $apng, 'acTL', FILE_APPEND );
		KT::is_error( KWIP_Files::validate_source( $apng, array( 'jpeg', 'png' ) ), 'kwip_animated', 'an animated PNG' );
	},

	'verify_webp accepts a real WebP and rejects everything else' => static function () {
		if ( ! function_exists( 'imagewebp' ) ) {
			KT::skip( 'GD has no WebP support.' );
		}
		$webp = KT::scratch( 'v.webp' );
		imagewebp( KT::canvas( 40, 30 ), $webp, 80 );

		KT::same( true, KWIP_Files::verify_webp( $webp ), 'a real WebP' );
		KT::same( true, KWIP_Files::verify_webp( $webp, 40, 30 ), 'matching dimensions' );
		KT::is_error( KWIP_Files::verify_webp( $webp, 41, 30 ), 'kwip_verify_mismatch', 'different dimensions' );
		KT::is_error( KWIP_Files::verify_webp( KT::scratch( 'none.webp' ) ), 'kwip_verify_missing', 'a missing file' );

		$empty = KT::scratch( 'empty.webp' );
		file_put_contents( $empty, '' );
		KT::is_error( KWIP_Files::verify_webp( $empty ), 'kwip_verify_empty', 'an empty file' );

		$jpeg = KT::scratch( 'really-a-jpeg.webp' );
		KT::write_image( 'jpeg', $jpeg, 20, 20 );
		KT::is_error( KWIP_Files::verify_webp( $jpeg ), 'kwip_verify_header', 'a JPEG named .webp' );
	},

	'publish places a verified file and never touches an original' => static function () {
		if ( ! function_exists( 'imagewebp' ) ) {
			KT::skip( 'GD has no WebP support.' );
		}
		$dest = KT::scratch( 'p.jpg.webp' );
		$temp = KWIP_Files::temp_path( $dest );
		imagewebp( KT::canvas( 20, 20 ), $temp, 80 );

		KT::same( true, KWIP_Files::publish( $temp, $dest ), 'publish succeeds' );
		KT::ok( is_file( $dest ), 'the WebP is in place' );
		KT::ok( ! file_exists( $temp ), 'the temp file is gone' );

		$original = KT::scratch( 'orig.jpg' );
		KT::write_image( 'jpeg', $original, 30, 30 );
		$hash  = md5_file( $original );
		$temp2 = KWIP_Files::temp_path( $original );
		imagewebp( KT::canvas( 20, 20 ), $temp2, 80 );

		KT::is_error( KWIP_Files::publish( $temp2, $original ), 'kwip_publish_invalid', 'publishing over a JPEG is refused' );
		KT::same( $hash, md5_file( $original ), 'the original is unchanged' );
		KT::ok( ! file_exists( $temp2 ), 'the refused temp file is discarded' );
	},

	'discard_temp only deletes the plugin\'s own temp files' => static function () {
		if ( ! function_exists( 'imagewebp' ) ) {
			KT::skip( 'GD has no WebP support.' );
		}
		$keep = KT::scratch( 'keep.webp' );
		imagewebp( KT::canvas( 10, 10 ), $keep, 80 );
		KWIP_Files::discard_temp( $keep );
		KT::ok( is_file( $keep ), 'an ordinary WebP is not deleted' );

		$temp = KWIP_Files::temp_path( KT::scratch( 'x.jpg.webp' ) );
		imagewebp( KT::canvas( 10, 10 ), $temp, 80 );
		KWIP_Files::discard_temp( $temp );
		KT::ok( ! file_exists( $temp ), 'a plugin temp file is deleted' );
	},

	'disk space check refuses an impossible size' => static function () {
		$base = KWIP_Files::uploads_base();
		if ( false === @disk_free_space( $base ) ) {
			KT::skip( 'Free disk space cannot be read here.' );
		}
		KT::same( true, KWIP_Files::ensure_disk_space( $base, 1024 ), 'a small file fits' );
		KT::is_error( KWIP_Files::ensure_disk_space( $base, PHP_INT_MAX >> 2 ), 'kwip_disk_space', 'an impossible size is refused' );
	},
);