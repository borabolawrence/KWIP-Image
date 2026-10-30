<?php
/**
 * Hosting platform detection.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Platform {

	/** @return string apache | litespeed | nginx | iis | other */
	public static function server(): string {
		global $is_apache, $is_nginx, $is_IIS, $is_iis7;

		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		if ( str_contains( $software, 'litespeed' ) ) {
			return 'litespeed';
		}
		if ( ! empty( $is_apache ) ) {
			return 'apache';
		}
		if ( ! empty( $is_nginx ) ) {
			return 'nginx';
		}
		if ( ! empty( $is_IIS ) || ! empty( $is_iis7 ) ) {
			return 'iis';
		}
		return 'other';
	}

	public static function label(): string {
		return match ( self::server() ) {
			'apache'    => 'Apache',
			'litespeed' => 'LiteSpeed',
			'nginx'     => 'Nginx',
			'iis'       => 'Microsoft IIS',
			default     => __( 'an unrecognized server', 'kwip-image-plugin' ),
		};
	}

	/** The server method writes .htaccess rules, which only Apache and LiteSpeed read. */
	public static function supports_server_rules(): bool {
		return in_array( self::server(), array( 'apache', 'litespeed' ), true );
	}

	public static function recommended_method(): string {
		return self::supports_server_rules() ? 'server' : 'page';
	}

	/**
	 * Best-effort CDN detection from response headers.
	 *
	 * @param array<string, mixed>|WP_Error $response HTTP response.
	 */
	public static function detect_cdn( $response ): ?string {
		$server = strtolower( self::header_value( $response, 'server' ) );

		if ( '' !== self::header_value( $response, 'cf-ray' ) || str_contains( $server, 'cloudflare' ) ) {
			return 'Cloudflare';
		}
		if ( '' !== self::header_value( $response, 'x-amz-cf-id' ) ) {
			return 'Amazon CloudFront';
		}
		if ( str_contains( $server, 'bunnycdn' ) ) {
			return 'BunnyCDN';
		}
		return null;
	}

	/** @param array<string, mixed>|WP_Error $response HTTP response. */
	private static function header_value( $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}
}