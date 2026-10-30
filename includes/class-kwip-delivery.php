<?php
/**
 * WebP delivery through Apache/LiteSpeed content negotiation (.htaccess).
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Delivery {

	private const MARKER = 'KWIP Image';

	public static function init(): void {
		// Keep .htaccess in step with the delivery setting.
		add_action( 'update_option_' . KWIP_Settings::OPTION, array( __CLASS__, 'on_settings_saved' ) );
		add_action( 'add_option_' . KWIP_Settings::OPTION, array( __CLASS__, 'on_settings_saved' ) );
	}

	public static function on_settings_saved(): void {
		$result = self::apply();
		if ( is_wp_error( $result ) ) {
			add_settings_error( KWIP_Settings::OPTION, 'gwip_delivery', $result->get_error_message(), 'warning' );
		}
	}


	/**
	 * @return string[] Apache rules, one per line.
	 */
	public static function rules(): array {
		$base = trailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		return array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteBase ' . $base,
			'RewriteCond %{HTTP_ACCEPT} image/webp',
			'RewriteCond %{REQUEST_FILENAME} -f',
			'RewriteCond %{REQUEST_FILENAME}.webp -f',
			'RewriteRule ^(.+\.(?:jpe?g|png))$ $1.webp [NC,T=image/webp,L]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'<FilesMatch "(?i)\.(?:jpe?g|png)(?:\.webp)?$">',
			'Header append Vary Accept',
			'</FilesMatch>',
			'</IfModule>',
		);
	}

	/** Nginx cannot be configured from a plugin; shown for manual setup. Currently untested. so please use it accordingly*/
	public static function nginx_rules(): string {
		return "# In the http { } block:\n"
			. "map \$http_accept \$gwip_webp_suffix {\n"
			. "    default \"\";\n"
			. "    \"~*image/webp\" \".webp\";\n"
			. "}\n\n"
			. "# In your server { } block (merge with any existing image rules):\n"
			. "location ~* \\.(?:jpe?g|png)\$ {\n"
			. "    add_header Vary Accept;\n"
			. "    try_files \$uri\$gwip_webp_suffix \$uri =404;\n"
			. "}\n";
	}


	/** Write or remove the server rules to match the chosen delivery method. */
	public static function apply(): true|WP_Error {
		return 'server' === self::active_method() ? self::write_rules() : self::remove_rules();
	}

	private static function write_rules(): true|WP_Error {
		if ( ! KWIP_Platform::supports_server_rules() ) {
			return new WP_Error( 'gwip_server', __( 'Automatic WebP delivery works on Apache and LiteSpeed. This server looks different, so the rules must be added manually. See Server status for the rules.', 'kwip-image-plugin' ) );
		}

		$file   = self::htaccess_path();
		$exists = file_exists( $file );

		if ( ( $exists && ! wp_is_writable( $file ) ) || ( ! $exists && ! wp_is_writable( dirname( $file ) ) ) ) {
			return new WP_Error( 'gwip_unwritable', __( 'The .htaccess file is not writable, so WebP delivery could not be switched on. See Server status for the rules to add manually.', 'kwip-image-plugin' ) );
		}

		if ( true !== insert_with_markers( $file, self::MARKER, self::rules() ) ) {
			return new WP_Error( 'gwip_write_failed', __( 'The .htaccess file could not be updated. See Server status for the rules to add manually.', 'kwip-image-plugin' ) );
		}
		return true;
	}

	public static function remove_rules(): true|WP_Error {
		$file = self::htaccess_path();
		if ( ! is_file( $file ) || ! self::has_rules( extract_from_markers( $file, self::MARKER ) ) ) {
			return true;
		}
		if ( ! wp_is_writable( $file ) ) {
			return new WP_Error( 'gwip_unwritable', __( 'The .htaccess file is not writable, so the WebP rules could not be removed. Delete the block between "BEGIN KWIP Image" and "END KWIP Image" by hand.', 'kwip-image-plugin' ) );
		}
		insert_with_markers( $file, self::MARKER, array() );
		return true;
	}

	/**
	 * @return array{key:string,file:string}
	 */
	public static function state(): array {
		$method  = self::active_method();
		$file    = self::htaccess_path();
		$present = is_file( $file ) && self::has_rules( extract_from_markers( $file, self::MARKER ) );

		if ( 'server' === $method ) {
			$key = $present ? 'active' : ( KWIP_Platform::supports_server_rules() ? 'missing' : 'manual' );
		} elseif ( 'page' === $method ) {
			$key = 'page';
		} else {
			$key = $present ? 'stale' : 'off';
		}

		return array(
			'key'  => $key,
			'file' => $file,
		);
	}

	// private static function is_apache(): bool {
	// 	global $is_apache; 
	// 	return ! empty( $is_apache );
	// }

	private static function htaccess_path(): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		return get_home_path() . '.htaccess';
	}

	/** @param string[] $lines Lines found between the markers. */
	private static function has_rules( array $lines ): bool {
		return str_contains( implode( "\n", $lines ), 'RewriteRule' );
	}

	/**
	 * Request a converted image twice (with and without WebP in Accept) and
	 * report what the server actually sends.
	 *
	 * @return array<string, mixed> type, message, optional rows.
	 */
	private static function test_server(): array {
	// public static function test(): array {
		$url = self::find_test_url();
		if ( null === $url ) {
			return array(
				'type'    => 'info',
				'message' => __( 'Convert at least one image first, then run the test.', 'kwip-image-plugin' ),
			);
		}

		$args = array(
			'timeout'     => 10,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		);
		$url  = add_query_arg( 'gwip_test', (string) time(), $url );

		$with    = wp_remote_head( $url, $args + array( 'headers' => array( 'Accept' => 'image/webp,image/*;q=0.8,*/*;q=0.5' ) ) );
		$without = wp_remote_head( $url, $args + array( 'headers' => array( 'Accept' => 'image/png,image/jpeg;q=0.9,*/*;q=0.5' ) ) );

		foreach ( array( $with, $without ) as $response ) {
			if ( is_wp_error( $response ) ) {
				return array(
					'type'    => 'error',
					'message' => sprintf(
						__( 'The site could not request its own test image (%s). Check an image in your browser\'s developer tools instead: the Network tab should show Content-Type image/webp.', 'kwip-image-plugin' ),
						$response->get_error_message()
					),
				);
			}
		}

		$code = (int) wp_remote_retrieve_response_code( $with );
		if ( 200 !== $code ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					__( 'The test image returned HTTP %d.', 'kwip-image-plugin' ),
					$code
				),
			);
		}

		$type_with    = (string) wp_remote_retrieve_header( $with, 'content-type' );
		$type_without = (string) wp_remote_retrieve_header( $without, 'content-type' );
		$vary         = wp_remote_retrieve_header( $with, 'vary' );
		$vary         = is_array( $vary ) ? implode( ', ', $vary ) : (string) $vary;

		$ok_webp  = str_contains( $type_with, 'image/webp' );
		$ok_plain = str_contains( $type_without, 'image/jpeg' ) || str_contains( $type_without, 'image/png' );
		$ok_vary  = false !== stripos( $vary, 'accept' );

		$rows = array(
			array( __( 'Browser that accepts WebP receives', 'kwip-image-plugin' ), '' !== $type_with ? $type_with : '-', $ok_webp ),
			array( __( 'Other browsers receive the original', 'kwip-image-plugin' ), '' !== $type_without ? $type_without : '-', $ok_plain ),
			array( __( 'Response header "Vary"', 'kwip-image-plugin' ), '' !== $vary ? $vary : '-', $ok_vary ),
		);

		if ( $ok_webp && $ok_plain ) {
			$type    = $ok_vary ? 'success' : 'warning';
			$message = $ok_vary
				? __( 'WebP delivery is working.', 'kwip-image-plugin' )
				: __( 'WebP delivery is working, but the "Vary: Accept" header was not detected. Caches and CDNs may serve the wrong version.', 'kwip-image-plugin' );

			$cdn = KWIP_Platform::detect_cdn( $with );
			if ( null !== $cdn ) {
				$type     = 'warning';
				$message .= ' ' . sprintf(
					__( 'A CDN (%s) was detected. CDNs often ignore the "Vary" header, so some visitors could receive the wrong version. The page method is the safer choice.', 'kwip-image-plugin' ),
					$cdn
				);
			}

			return array(
				'type'    => $type,
				'message' => $message,
				'rows'    => $rows,
			);
		}

		return array(
			'type'    => 'error',
			'message' => __( 'WebP is not being served yet. Check that the server rules are active, that Apache has mod_rewrite enabled and allows .htaccess overrides (AllowOverride All), and that no cache or CDN answers before the server does.', 'kwip-image-plugin' ),
			'rows'    => $rows,
		);
	}



	/**
	 * @return array<string, mixed>
	 */
	public static function test(): array {
		return 'page' === self::active_method() ? self::test_page() : self::test_server();
	}

	/**
	 * Page method: load the home page and look for wrapped images.
	 *
	 * @return array<string, mixed>
	 */
	private static function test_page(): array {
		$response = wp_remote_get(
			add_query_arg( 'gwip_test', (string) time(), home_url( '/' ) ),
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => 3 * MB_IN_BYTES,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					__( 'The home page could not be loaded (%s).', 'guanzon-webp-image-plugin' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					__( 'The home page returned HTTP %d.', 'kwip-image-plugin' ),
					$code
				),
			);
		}

		$html    = (string) wp_remote_retrieve_body( $response );
		$wrapped = (int) preg_match_all( '/<picture\b[^>]*class=["\'][^"\']*gwip-picture/i', $html );
		$images  = (int) preg_match_all( '/<img\b/i', $html );

		if ( $wrapped > 0 ) {
			return array(
				'type'    => 'success',
				'message' => sprintf(
					_n( 'The page method is working. %d image on your home page is delivered through it.', 'The page method is working. %d images on your home page are delivered through it.', $wrapped, 'kwip-image-plugin' ),
					$wrapped
				),
			);
		}
		if ( 0 === $images ) {
			return array(
				'type'    => 'info',
				'message' => __( 'No images were found on your home page, so there was nothing to check. Use "Check a page" on a page that has images.', 'kwip-image-plugin' ),
			);
		}
		return array(
			'type'    => 'warning',
			'message' => __( 'The page method is on, but no image on your home page was wrapped. Either none of them have optimized copies yet, or a cache is showing an older version of the page. Clear your cache and test again.', 'kwip-image-plugin' ),
		);
	}





	private static function find_test_url(): ?string {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array( KWIP_Media::optimized_meta_query() ),
			)
		);
		if ( empty( $ids ) ) {
			return null;
		}

		$id     = (int) $ids[0];
		$record = get_post_meta( $id, KWIP_Converter::META_KEY, true );
		$url    = wp_get_attachment_url( $id );
		if ( ! $url || ! is_array( $record ) || empty( $record['files'] ) || ! is_array( $record['files'] ) ) {
			return null;
		}

		$dir = trailingslashit( dirname( $url ) );
		foreach ( $record['files'] as $name => $file ) {
			if ( is_array( $file ) && 'converted' === ( $file['status'] ?? '' ) ) {
				return $dir . rawurlencode( (string) $name );
			}
		}
		return null;
	}

	/** @param array<string, mixed> $data Message data. */
	public static function flash( array $data ): void {
		set_transient( self::flash_key(), $data, 120 );
	}

	/** @return array<string, mixed>|null */
	public static function take_flash(): ?array {
		$data = get_transient( self::flash_key() );
		if ( ! is_array( $data ) ) {
			return null;
		}
		delete_transient( self::flash_key() );
		return $data;
	}

	private static function flash_key(): string {
		return 'gwip_flash_' . get_current_user_id();
	}


	/** @return string server | page | none */
	public static function active_method(): string {
		$settings = KWIP_Settings::get();
		if ( 'webp_fallback' !== $settings['delivery'] ) {
			return 'none';
		}

		$method = (string) $settings['method'];
		if ( 'page' === $method ) {
			return 'page';
		}
		if ( 'server' === $method ) {
			return 'server';
		}
		return KWIP_Platform::recommended_method();
	}

	/** True when visitors are being served optimized images by some method. */
	public static function is_delivering(): bool {
		return in_array( self::state()['key'], array( 'active', 'page' ), true );
	}

	public static function method_label(): string {
		return match ( self::active_method() ) {
			'server' => __( 'server method', 'kwip-image-plugin' ),
			'page'   => __( 'page method', 'kwip-image-plugin' ),
			default  => __( 'none (turned off)', 'kwip-image-plugin' ),
		};
	}
}