<?php
/**
 * Helpers for the KWIP Image test suite. Loaded by tests/run.php.
 *
 * @package kwip-image-plugin
 */

final class KWIP_Test_Skip extends Exception {
}

final class KT {

	public static int $tests        = 0;
	public static int $passed       = 0;
	public static int $failed       = 0;
	public static int $skipped      = 0;
	public static int $assertions   = 0;
	public static bool $verbose     = false;
	public static bool $test_failed = false;
	public static string $current   = '';
	public static int $admin_id     = 0;

	/** @var string[] */
	public static array $failures = array();
	/** @var string[] */
	public static array $notices = array();
	/** @var int[] */
	public static array $ids = array();

	/** @var array<string, mixed> */
	private static array $backup = array();
	private static bool $finished = false;

	/* ---------------------------------------------------------------------
	 * Setup and teardown
	 * ------------------------------------------------------------------ */

	public static function boot(): void {
		$admins         = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		self::$admin_id = $admins ? (int) $admins[0] : 0;
		self::as_admin();
		self::snapshot();
		self::cleanup();
	}

	public static function as_admin(): void {
		if ( self::$admin_id > 0 ) {
			wp_set_current_user( self::$admin_id );
		}
	}

	private static function snapshot(): void {
		self::$backup = array(
			KWIP_Settings::OPTION => get_option( KWIP_Settings::OPTION, null ),
			'kwip_db_version'     => get_option( 'kwip_db_version', null ),
		);
	}

	/** Put back the options a test may have changed, and the current user. */
	public static function restore(): void {
		foreach ( self::$backup as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		self::as_admin();
	}

	/** Runs at the end, and again as a shutdown function in case a test crashes PHP. */
	public static function finish(): void {
		if ( self::$finished ) {
			return;
		}
		self::$finished = true;
		self::cleanup();
		self::restore();
		if ( 0 === KWIP_Queue::pending_count() ) {
			wp_clear_scheduled_hook( KWIP_Queue::HOOK );
		}
	}

	public static function cleanup(): void {
		$left = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
				'numberposts' => -1,
				'meta_key'    => '_kwip_test',
			)
		);
		foreach ( array_unique( array_merge( self::$ids, array_map( 'intval', $left ) ) ) as $id ) {
			if ( get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
		}
		self::$ids = array();
		self::rrmdir( self::scratch_path() );
		KWIP_Stats::flush();
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	public static function ok( bool $condition, string $message ): void {
		++self::$assertions;
		if ( ! $condition ) {
			self::fail( $message );
		}
	}

	public static function fail( string $message ): void {
		self::$test_failed = true;
		self::$failures[]  = self::$current . ': ' . $message;
	}

	/** @param mixed $expected @param mixed $actual */
	public static function same( $expected, $actual, string $message ): void {
		self::ok( $expected === $actual, sprintf( '%s (expected %s, got %s)', $message, self::show( $expected ), self::show( $actual ) ) );
	}

	public static function has( string $needle, string $haystack, string $message ): void {
		self::ok( str_contains( $haystack, $needle ), $message . ' (missing ' . self::show( $needle ) . ')' );
	}

	public static function not_has( string $needle, string $haystack, string $message ): void {
		self::ok( ! str_contains( $haystack, $needle ), $message . ' (found ' . self::show( $needle ) . ')' );
	}

	/** @param mixed $value */
	public static function is_error( $value, string $code, string $message ): void {
		if ( ! is_wp_error( $value ) ) {
			self::ok( false, sprintf( '%s (expected WP_Error %s, got %s)', $message, $code, self::show( $value ) ) );
			return;
		}
		self::same( $code, $value->get_error_code(), $message );
	}

	public static function skip( string $why ): void {
		throw new KWIP_Test_Skip( $why );
	}

	/** @param mixed $value */
	private static function show( $value ): string {
		if ( is_wp_error( $value ) ) {
			return 'WP_Error(' . $value->get_error_code() . ')';
		}
		$text = (string) preg_replace( '/\s+/', ' ', var_export( $value, true ) );
		return strlen( $text ) > 140 ? substr( $text, 0, 140 ) . '...' : $text;
	}

	/* ---------------------------------------------------------------------
	 * Settings and conversion setup
	 * ------------------------------------------------------------------ */

	/** Change some settings for the current test (restored automatically afterwards). */
	public static function settings( array $overrides ): void {
		update_option( KWIP_Settings::OPTION, array_merge( KWIP_Settings::get(), $overrides ) );
	}

	/** Skip the test unless this server can convert, then use predictable settings. */
	public static function prepare_conversion( array $overrides = array() ): void {
		$report = KWIP_Environment::get_report();
		if ( empty( $report['ready'] ) ) {
			self::skip( 'This server cannot write WebP (see the Health check).' );
		}
		if ( ! function_exists( 'imagejpeg' ) || ! function_exists( 'imagepng' ) || ! function_exists( 'imagewebp' ) ) {
			self::skip( 'GD with WebP support is needed to build the test images.' );
		}
		self::settings(
			array_merge(
				array(
					'quality'      => 75,
					'min_savings'  => 0,
					'allow_larger' => true,
					'formats'      => array( 'jpeg', 'png' ),
					'auto_convert' => false,
				),
				$overrides
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Files and test images
	 * ------------------------------------------------------------------ */

	public static function scratch_path(): string {
		return trailingslashit( KWIP_Files::uploads_base() ) . 'kwip-tests';
	}

	/** A path in the scratch folder inside uploads (the folder is created on demand). */
	public static function scratch( string $name = '' ): string {
		$dir = self::scratch_path();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return '' === $name ? $dir : $dir . '/' . $name;
	}

	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/** Draw a test image. $kind: jpeg | png | png-alpha (transparent background, red disc). */
	public static function write_image( string $kind, string $path, int $width = 1200, int $height = 800 ): void {
		$image = imagecreatetruecolor( $width, $height );

		if ( 'png-alpha' === $kind ) {
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
			imagealphablending( $image, true );
		} else {
			for ( $y = 0; $y < $height; $y += 4 ) {
				imagefilledrectangle( $image, 0, $y, $width, $y + 3, imagecolorallocate( $image, (int) ( 255 * $y / $height ), 120, 200 - (int) ( 150 * $y / $height ) ) );
			}
		}
		imagefilledellipse( $image, (int) ( $width / 2 ), (int) ( $height / 2 ), (int) ( $width / 2 ), (int) ( $height / 2 ), imagecolorallocate( $image, 200, 30, 30 ) );

		if ( 'jpeg' === $kind ) {
			imagejpeg( $image, $path, 95 );
		} else {
			imagepng( $image, $path );
		}
	}

	/** A canvas for tests that need a GD image of their own. @return GdImage */
	public static function canvas( int $width, int $height ) {
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width, $height, imagecolorallocate( $image, 40, 120, 200 ) );
		return $image;
	}

	/** A real image attachment with all its thumbnail sizes. Removed at the end. */
	public static function attachment( string $kind = 'jpeg', int $width = 1200, int $height = 800 ): int {
		$dir  = wp_upload_dir();
		$name = wp_unique_filename( $dir['path'], 'kwip-test-' . wp_generate_password( 6, false ) . ( 'jpeg' === $kind ? '.jpg' : '.png' ) );
		$path = trailingslashit( $dir['path'] ) . $name;
		self::write_image( $kind, $path, $width, $height );

		$id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'jpeg' === $kind ? 'image/jpeg' : 'image/png',
				'post_title'     => 'KWIP TEST ' . $name,
				'post_status'    => 'inherit',
			),
			$path
		);
		self::$ids[] = $id;
		update_post_meta( $id, '_kwip_test', 1 );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		return $id;
	}

	/** An attachment with the given contents, for non-images and damaged images. */
	public static function raw_attachment( string $stem, string $extension, string $contents, string $mime ): int {
		$dir  = wp_upload_dir();
		$name = wp_unique_filename( $dir['path'], 'kwip-test-' . $stem . '-' . wp_generate_password( 6, false ) . '.' . $extension );
		$path = trailingslashit( $dir['path'] ) . $name;
		file_put_contents( $path, $contents );

		$id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'KWIP TEST ' . $name,
				'post_status'    => 'inherit',
			),
			$path
		);
		self::$ids[] = $id;
		update_post_meta( $id, '_kwip_test', 1 );
		return $id;
	}

	public static function text_attachment(): int {
		return self::raw_attachment( 'note', 'txt', 'hello', 'text/plain' );
	}

	/** @return array<string, string> basename => path, main file plus every generated size. */
	public static function files_of( int $id ): array {
		$main  = (string) get_attached_file( $id );
		$files = array( wp_basename( $main ) => $main );
		$meta  = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				$files[ $size['file'] ] = dirname( $main ) . '/' . $size['file'];
			}
		}
		return $files;
	}

	/** @return array<string, string> basename => md5 of the original files. */
	public static function hashes( int $id ): array {
		$hashes = array();
		foreach ( self::files_of( $id ) as $name => $path ) {
			$hashes[ $name ] = is_file( $path ) ? (string) md5_file( $path ) : 'missing';
		}
		return $hashes;
	}

	/** @return string[] Paths of the .webp files that exist for this attachment. */
	public static function webps( int $id ): array {
		$found = array();
		foreach ( self::files_of( $id ) as $path ) {
			if ( is_file( $path . '.webp' ) ) {
				$found[] = $path . '.webp';
			}
		}
		return $found;
	}

	/** Run a program without a shell. @return array{code:int,output:string}|null */
	public static function run( array $command ): ?array {
		if ( ! function_exists( 'proc_open' ) ) {
			return null;
		}
		$pipes   = array();
		$process = @proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			return null;
		}
		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array(
			'code'   => proc_close( $process ),
			'output' => trim( $output ),
		);
	}
}