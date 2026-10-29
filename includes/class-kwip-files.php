<?php
/**
 * Safe filesystem helpers: path validation, source validation, output
 * verification, size comparison and atomic publishing.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Files {

	private const SOURCE_FORMATS = array(
		'image/jpeg' => 'jpeg',
		'image/png'  => 'png',
	);

	public static function uploads_base(): string {
		$uploads = wp_get_upload_dir();
		return (string) ( $uploads['basedir'] ?? '' );
	}

	/**
	 * True only if the path exists and, after resolving symlinks and "..",
	 * lives inside the uploads directory.
	 */
	public static function is_inside_uploads( string $path ): bool {
		$base_raw = self::uploads_base();
		if ( '' === $path || '' === $base_raw || str_contains( $path, "\0" ) ) {
			return false;
		}

		$base = realpath( $base_raw );
		$real = realpath( $path );
		if ( false === $base || false === $real ) {
			return false;
		}

		$base = untrailingslashit( wp_normalize_path( $base ) );
		$real = untrailingslashit( wp_normalize_path( $real ) );

		return $real === $base || str_starts_with( $real, $base . '/' );
	}

	/**
	 * A file we may create: its directory must be inside uploads and the
	 * path itself must not be a symlink.
	 */
	public static function is_valid_destination( string $path ): bool {
		if ( '' === $path || str_contains( $path, "\0" ) || '' === wp_basename( $path ) ) {
			return false;
		}
		if ( is_link( $path ) ) {
			return false;
		}
		return self::is_inside_uploads( dirname( $path ) );
	}

	/** image.jpg becomes image.jpg.webp, so image.jpg and image.png can never collide. */
	public static function webp_path( string $source ): string {
		return $source . '.webp';
	}

	/** Temporary file in the same directory as the destination (keeps rename atomic). */
	public static function temp_path( string $destination ): string {
		return trailingslashit( dirname( $destination ) ) . '.kwip-' . wp_generate_password( 10, false ) . '.tmp.webp';
	}

	/**
	 * Check that a file is a real, readable, convertible JPEG or PNG.
	 *
	 * @param string   $path            Absolute path to the source.
	 * @param string[] $allowed_formats Setting keys, e.g. array( 'jpeg', 'png' ).
	 * @return array{mime:string,format:string,size:int}|WP_Error
	 */
	public static function validate_source( string $path, array $allowed_formats ): array|WP_Error {
		if ( ! self::is_inside_uploads( $path ) ) {
			return new WP_Error( 'gwip_path', __( 'The file is not inside the uploads directory.', 'kwip-image-plugin' ) );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'gwip_unreadable', __( 'The file is missing or cannot be read.', 'kwip-image-plugin' ) );
		}

		$mime = wp_get_image_mime( $path );
		if ( false === $mime || ! isset( self::SOURCE_FORMATS[ $mime ] ) ) {
			return new WP_Error( 'gwip_unsupported', __( 'Not a supported JPEG or PNG image.', 'kwip-image-plugin' ) );
		}

		$format = self::SOURCE_FORMATS[ $mime ];
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			return new WP_Error( 'gwip_format_disabled', __( 'Conversion of this image type is turned off in the settings.', 'kwip-image-plugin' ) );
		}

		if ( 'png' === $format && self::is_animated_png( $path ) ) {
			return new WP_Error( 'gwip_animated', __( 'Animated PNG files are skipped to preserve the animation.', 'kwip-image-plugin' ) );
		}

		$size = filesize( $path );
		if ( false === $size || $size < 1 ) {
			return new WP_Error( 'gwip_empty', __( 'The file is empty.', 'kwip-image-plugin' ) );
		}

		return array(
			'mime'   => $mime,
			'format' => $format,
			'size'   => (int) $size,
		);
	}

	/**
	 * Detect animated PNG (APNG) by looking for the "acTL" chunk, which must
	 * appear before the image data. Only the first 64 KB are scanned, so an
	 * APNG with an unusually large header could be missed.
	 */
	private static function is_animated_png( string $path ): bool {
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		$chunk = fread( $handle, 65536 );
		fclose( $handle );

		return is_string( $chunk ) && str_contains( $chunk, 'acTL' );
	}

	/**
	 * Refuse to start when the disk is clearly too full. Needs twice the
	 * source size plus 1 MB (a conservative estimate, not an exact figure).
	 */
	public static function ensure_disk_space( string $directory, int $source_size ): true|WP_Error {
		$free = @disk_free_space( $directory );
		if ( false === $free ) {
			return true;
		}

		$needed = ( $source_size * 2 ) + MB_IN_BYTES;
		if ( $free < $needed ) {
			return new WP_Error(
				'gwip_disk_space',
				sprintf(
					__( 'Not enough free disk space (%1$s free, %2$s needed).', 'kwip-image-plugin' ),
					(string) size_format( (int) $free ),
					(string) size_format( $needed )
				)
			);
		}
		return true;
	}

	/**
	 * Check that a generated file really is a decodable WebP.
	 * Dimensions are compared when supplied (use the size the image editor
	 * reports after any rotation).
	 */
	public static function verify_webp( string $path, ?int $width = null, ?int $height = null ): true|WP_Error {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'gwip_verify_missing', __( 'The generated WebP file does not exist.', 'kwip-image-plugin' ) );
		}
		if ( (int) filesize( $path ) < 1 ) {
			return new WP_Error( 'gwip_verify_empty', __( 'The generated WebP file is empty.', 'kwip-image-plugin' ) );
		}

		$handle = @fopen( $path, 'rb' );
		$head   = $handle ? fread( $handle, 12 ) : false;
		if ( $handle ) {
			fclose( $handle );
		}
		if ( ! is_string( $head ) || 12 !== strlen( $head ) || 'RIFF' !== substr( $head, 0, 4 ) || 'WEBP' !== substr( $head, 8, 4 ) ) {
			return new WP_Error( 'gwip_verify_header', __( 'The generated file is not a valid WebP container.', 'kwip-image-plugin' ) );
		}
		if ( 'image/webp' !== wp_get_image_mime( $path ) ) {
			return new WP_Error( 'gwip_verify_mime', __( 'The generated file is not recognised as WebP.', 'kwip-image-plugin' ) );
		}

		$dims = wp_getimagesize( $path );
		if ( ! is_array( $dims ) || (int) $dims[0] < 1 || (int) $dims[1] < 1 ) {
			return new WP_Error( 'gwip_verify_dimensions', __( 'The generated WebP dimensions could not be read.', 'kwip-image-plugin' ) );
		}
		if ( null !== $width && null !== $height && ( (int) $dims[0] !== $width || (int) $dims[1] !== $height ) ) {
			return new WP_Error( 'gwip_verify_mismatch', __( 'The generated WebP has different dimensions than expected.', 'kwip-image-plugin' ) );
		}

		return true;
	}

	/**
	 * Decide whether the WebP is worth using.
	 *
	 * Reasons: ok | override | not_smaller | below_threshold
	 *
	 * @return array{use_webp:bool,saved_bytes:int,saved_percent:float,reason:string}
	 */
	public static function compare_sizes( int $original, int $webp, int $min_savings_percent, bool $allow_larger ): array {
		$saved   = $original - $webp;
		$percent = $original > 0 ? round( ( $saved / $original ) * 100, 1 ) : 0.0;

		if ( $allow_larger ) {
			$use    = true;
			$reason = 'override';
		} elseif ( $webp >= $original ) {
			$use    = false;
			$reason = 'not_smaller';
		} elseif ( $percent < $min_savings_percent ) {
			$use    = false;
			$reason = 'below_threshold';
		} else {
			$use    = true;
			$reason = 'ok';
		}

		return array(
			'use_webp'      => $use,
			'saved_bytes'   => $saved,
			'saved_percent' => (float) $percent,
			'reason'        => $reason,
		);
	}

	/**
	 * Move a verified temp file into place. Only ever creates or replaces a
	 * ".webp" file, so an original JPEG/PNG can never be overwritten.
	 */
	public static function publish( string $temp, string $destination ): true|WP_Error {
		$valid = is_file( $temp )
			&& 'webp' === strtolower( pathinfo( $destination, PATHINFO_EXTENSION ) )
			&& self::is_valid_destination( $destination )
			&& self::is_valid_destination( $temp )
			&& wp_normalize_path( dirname( $temp ) ) === wp_normalize_path( dirname( $destination ) );

		if ( ! $valid ) {
			self::discard_temp( $temp );
			return new WP_Error( 'gwip_publish_invalid', __( 'The generated file could not be placed safely.', 'kwip-image-plugin' ) );
		}

		if ( ! @rename( $temp, $destination ) ) {
			self::discard_temp( $temp );
			return new WP_Error( 'gwip_publish_failed', __( 'The generated file could not be saved. Check folder permissions.', 'kwip-image-plugin' ) );
		}

		$stat = @stat( dirname( $destination ) );
		if ( false !== $stat ) {
			@chmod( $destination, $stat['mode'] & 0000666 );
		}

		return true;
	}

	public static function discard_temp( string $temp ): void {
		$name = wp_basename( $temp );
		if ( str_starts_with( $name, '.kwip-' ) && str_ends_with( $name, '.tmp.webp' ) && self::is_valid_destination( $temp ) && is_file( $temp ) ) {
			wp_delete_file( $temp );
		}
	}
}