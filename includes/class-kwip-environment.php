<?php
/**
 * Environment and image-processing capability detection.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Environment {

	/**
	 * Collect a snapshot of what this server can do.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_report(): array {
		$gd_info = ( extension_loaded( 'gd' ) && function_exists( 'gd_info' ) ) ? gd_info() : array();

		$gd_loaded  = ! empty( $gd_info );
		$gd_webp    = $gd_loaded && ! empty( $gd_info['WebP Support'] ) && function_exists( 'imagewebp' );
		$gd_lossles = $gd_webp && defined( 'IMG_WEBP_LOSSLESS' );

		$imagick_loaded = class_exists( 'Imagick' );
		$imagick_webp   = false;
		if ( $imagick_loaded ) {
			try {
				$imagick_webp = ! empty( Imagick::queryFormats( 'WEBP' ) );
			} catch ( Throwable $e ) {
				$imagick_webp = false;
			}
		}

		$uploads  = wp_get_upload_dir();
		$basedir  = $uploads['basedir'] ?? '';
		$writable = '' !== $basedir && wp_is_writable( $basedir );

		$php_ok = version_compare( PHP_VERSION, KWIP_MIN_PHP, '>=' );
		$wp_ok  = version_compare( get_bloginfo( 'version' ), KWIP_MIN_WP, '>=' );

		$wp_webp = (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );

		return array(
			'php_version'    => PHP_VERSION,
			'php_ok'         => $php_ok,
			'wp_version'     => get_bloginfo( 'version' ),
			'wp_ok'          => $wp_ok,
			'gd_loaded'      => $gd_loaded,
			'gd_webp'        => $gd_webp,
			'gd_lossless'    => $gd_lossles,
			'imagick_loaded' => $imagick_loaded,
			'imagick_webp'   => $imagick_webp,
			'wp_webp'        => $wp_webp,
			'uploads_dir'    => $basedir,
			'uploads_ok'     => $writable,
			'memory_limit'   => (string) ini_get( 'memory_limit' ),
			'max_execution'  => (string) ini_get( 'max_execution_time' ),
			'ready'          => $php_ok && $wp_ok && $wp_webp && $writable,
		);
	}
}
