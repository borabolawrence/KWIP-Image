<?php
/**
 * "Check a page": finds the images on a page of this site and reports whether
 * visitors really receive them as WebP.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Scan {

	private const NONCE_ACTION = 'kwip_scan';
	private const MAX_TESTED   = 40;
	private const TIME_BUDGET  = 20;

	public static function init(): void {
		add_action( 'wp_ajax_kwip_scan', array( __CLASS__, 'handle_ajax' ) );
	}

	public static function enqueue(): void {
		wp_enqueue_script( 'kwip-scan', KWIP_URL . 'assets/js/scan.js', array( 'jquery', 'wp-a11y' ), KWIP_VERSION, true );
		wp_localize_script(
			'kwip-scan',
			'kwipScan',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'bulkUrl' => add_query_arg(
					array(
						'page' => 'KWIP-webp',
						'tab'  => 'bulk',
					),
					admin_url( 'admin.php' )
				),
				'i18n'    => array(
					'scan'              => __( 'Scan page', 'kwip-image-plugin' ),
					'scanning'          => __( 'Scanning…', 'kwip-image-plugin' ),
					'error'             => __( 'Something went wrong. Please try again.', 'kwip-image-plugin' ),
					'foundNone'         => __( 'No JPG or PNG images were found on this page', 'kwip-image-plugin' ),
					'foundNoneDetail'   => __( 'Images added by scripts after the page loads, or by external stylesheets, cannot be detected by this check.', 'kwip-image-plugin' ),
					'blocked'           => __( 'Some optimized images are not reaching visitors', 'kwip-image-plugin' ),
					'blockedDetail'     => __( 'An optimized copy exists, but the server still sends the original. A cache, CDN or server setting may be in the way. Use "Check that it works" above.', 'kwip-image-plugin' ),
					'noneOptimized'     => __( 'None of the images on this page are optimized yet', 'kwip-image-plugin' ),
					'some'              => __( 'Some images on this page are not optimized yet', 'kwip-image-plugin' ),
					'pendingDetail'     => __( 'Run "Optimize images" to convert them. Images that were converted but came out no smaller stay as originals on purpose.', 'kwip-image-plugin' ),
					'allGood'           => __( 'Every image we could check is delivered as WebP', 'kwip-image-plugin' ),
					'allGoodDetail'     => __( 'Visitors with a modern browser are getting the lighter images on this page.', 'kwip-image-plugin' ),
					'nothingToDo'       => __( 'There is nothing this plugin can optimize on this page', 'kwip-image-plugin' ),
					'nothingToDoDetail' => __( 'The images found are already web-friendly formats, come from another website, or are not in your uploads folder.', 'kwip-image-plugin' ),
					'savings'           => __( 'On this page, optimized images save about %1$s (%2$s percent less image data).', 'kwip-image-plugin' ),
					'chipFound'         => __( 'Images found: %s', 'kwip-image-plugin' ),
					'chipWebp'          => __( 'Delivered as WebP: %s', 'kwip-image-plugin' ),
					'chipPending'       => __( 'Not optimized yet: %s', 'kwip-image-plugin' ),
					'chipBlocked'       => __( 'Not delivered: %s', 'kwip-image-plugin' ),
					'chipOutside'       => __( 'Outside your uploads folder: %s', 'kwip-image-plugin' ),
					'chipExternal'      => __( 'From other websites: %s', 'kwip-image-plugin' ),
					'chipOther'         => __( 'Already web-friendly: %s', 'kwip-image-plugin' ),
					'chipError'         => __( 'Could not be checked: %s', 'kwip-image-plugin' ),
					'chipUntested'      => __( 'Not checked (limit reached): %s', 'kwip-image-plugin' ),
					'optimize'          => __( 'Optimize my images', 'kwip-image-plugin' ),
				),
			)
		);
	}

	public static function render_card(): void {
		?>
		<section class="kwip-card kwip-scan">
			<h2><?php esc_html_e( 'Check a page', 'kwip-image-plugin' ); ?></h2>
			<p class="kwip-lead"><?php esc_html_e( 'Wondering whether a page really loads optimized images? Enter its address and every image on it is checked. It also shows which images this plugin cannot optimize, such as images from another website.', 'kwip-image-plugin' ); ?></p>

			<form id="kwip-scan-form" class="kwip-scan__form" novalidate>
				<label class="screen-reader-text" for="kwip-scan-url"><?php esc_html_e( 'Page address', 'kwip-image-plugin' ); ?></label>
				<input type="text" id="kwip-scan-url" class="regular-text" value="<?php echo esc_attr( home_url( '/' ) ); ?>" autocomplete="off">
				<button type="submit" class="button button-primary" id="kwip-scan-btn"><?php esc_html_e( 'Scan page', 'kwip-image-plugin' ); ?></button>
				<span class="spinner"></span>
			</form>
			<p class="description"><?php esc_html_e( 'Try your home page, then a page built with Flatsome UX Builder and a WooCommerce product page.', 'kwip-image-plugin' ); ?></p>

			<p id="kwip-scan-message" role="alert"></p>
			<div id="kwip-scan-result" aria-live="polite"></div>
		</section>
		<?php
	}


	public static function handle_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'kwip-image-plugin' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		$input = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$url   = self::normalize_page_url( trim( $input ) );
		if ( is_wp_error( $url ) ) {
			wp_send_json_error( array( 'message' => $url->get_error_message() ) );
		}

		$result = self::scan( $url );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/** Only pages of this website may be scanned. */
	private static function normalize_page_url( string $input ): string|WP_Error {
		$home      = home_url();
		$home_host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );

		if ( '' === $input ) {
			return esc_url_raw( home_url( '/' ) );
		}
		if ( ! preg_match( '#^https?://#i', $input ) ) {
			return esc_url_raw( untrailingslashit( $home ) . '/' . ltrim( $input, '/' ) );
		}

		$host = strtolower( (string) wp_parse_url( $input, PHP_URL_HOST ) );
		if ( '' === $host || $host !== $home_host ) {
			return new WP_Error( 'kwip_scan_host', __( 'Only pages of this website can be checked.', 'kwip-image-plugin' ) );
		}
		return esc_url_raw( $input );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private static function scan( string $page_url ): array|WP_Error {
		$response = wp_remote_get(
			add_query_arg( 'kwip_scan', (string) time(), $page_url ),
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => 3 * MB_IN_BYTES,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
				'headers'             => array(
					'Accept'        => 'text/html,application/xhtml+xml',
					'Cache-Control' => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'kwip_scan_fetch',
				sprintf(
					__( 'The page could not be loaded (%s).', 'kwip-image-plugin' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'kwip_scan_status',
				sprintf(
					__( 'The page returned HTTP %d, so it could not be checked.', 'kwip-image-plugin' ),
					$code
				)
			);
		}

		$html = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $html ) ) {
			return new WP_Error( 'kwip_scan_empty', __( 'The page was empty.', 'kwip-image-plugin' ) );
		}

		$urls = self::extract_urls( $html, $page_url );
		if ( is_wp_error( $urls ) ) {
			return $urls;
		}

		return array( 'page' => $page_url ) + self::inspect( $urls );
	}

	/**
	 * Collect candidate image URLs: <img>/<source> (including common lazy-load
	 * attributes and srcset), inline style="" backgrounds, <style> blocks and
	 * data-bg attributes. Images added by JavaScript later or by external
	 * stylesheet files cannot be seen here.
	 *
	 * @return string[]|WP_Error Absolute, de-duplicated URLs.
	 */
	private static function extract_urls( string $html, string $base ): array|WP_Error {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'kwip_scan_dom', __( 'The page check needs the PHP "dom" extension, which is not available on this server.', 'kwip-image-plugin' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$raw = array();

		foreach ( $dom->getElementsByTagName( '*' ) as $el ) {
			$tag = strtolower( $el->nodeName );

			if ( 'script' === $tag ) {
				continue;
			}
			if ( 'style' === $tag ) {
				self::css_urls( (string) $el->textContent, $raw );
				continue;
			}

			$is_media = in_array( $tag, array( 'img', 'source' ), true );

			if ( $is_media ) {
				foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original' ) as $attr ) {
					if ( $el->hasAttribute( $attr ) ) {
						$raw[] = $el->getAttribute( $attr );
					}
				}
				foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
					if ( ! $el->hasAttribute( $attr ) ) {
						continue;
					}
					foreach ( explode( ',', $el->getAttribute( $attr ) ) as $part ) {
						$bits = preg_split( '/\s+/', trim( $part ) );
						if ( ! empty( $bits[0] ) ) {
							$raw[] = $bits[0];
						}
					}
				}
			}

			foreach ( array( 'data-bg', 'data-background-image' ) as $attr ) {
				if ( $el->hasAttribute( $attr ) ) {
					$value = $el->getAttribute( $attr );
					$raw[] = $value;
					self::css_urls( $value, $raw );
				}
			}

			if ( $el->hasAttribute( 'style' ) ) {
				self::css_urls( $el->getAttribute( 'style' ), $raw );
			}
		}

		$urls = array();
		foreach ( $raw as $candidate ) {
			$candidate = trim( html_entity_decode( (string) $candidate ) );
			if ( '' === $candidate || preg_match( '#^(data|blob|javascript|about):#i', $candidate ) ) {
				continue;
			}
			$resolved = self::resolve( $candidate, $base );
			if ( null !== $resolved ) {
				$urls[ $resolved ] = true;
			}
		}

		return array_map( 'strval', array_keys( $urls ) );
	}

	/** @param string[] $out Collected values (by reference). */
	private static function css_urls( string $css, array &$out ): void {
		if ( preg_match_all( '/url\(\s*(["\']?)(.*?)\1\s*\)/i', $css, $matches ) ) {
			foreach ( $matches[2] as $found ) {
				$out[] = trim( (string) $found );
			}
		}
	}

	private static function resolve( string $url, string $base ): ?string {
		$url   = explode( '#', $url, 2 )[0];
		$parts = wp_parse_url( $base );
		if ( '' === $url || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( str_starts_with( $url, '//' ) ) {
			return $parts['scheme'] . ':' . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return $origin . $url;
		}

		$dir = isset( $parts['path'] ) ? (string) preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
		return $origin . $dir . $url;
	}

	/**
	 * Ask the server how each image would be delivered to a WebP-capable browser.
	 *
	 * @param string[] $urls Absolute image URLs.
	 * @return array<string, mixed>
	 */
	private static function inspect( array $urls ): array {
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$labels    = self::labels();
		$counts    = array(
			'found'    => 0,
			'webp'     => 0,
			'pending'  => 0,
			'blocked'  => 0,
			'outside'  => 0,
			'external' => 0,
			'other'    => 0,
			'error'    => 0,
			'untested' => 0,
		);
		$items     = array();
		$from      = 0;
		$to        = 0;
		$tested    = 0;
		$start     = microtime( true );
		$request   = array(
			'timeout'     => 8,
			'redirection' => 2,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		);

		$page_webp = array();
		foreach ( $urls as $candidate ) {
			if ( preg_match( '/\.(?:jpe?g|png)\.webp$/i', (string) wp_parse_url( $candidate, PHP_URL_PATH ) ) ) {
				$page_webp[ explode( '?', $candidate, 2 )[0] ] = true;
			}
		}

		foreach ( $urls as $url ) {
			$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif' ), true ) ) {
				continue;
			}
			if ( 'webp' === $ext && isset( $page_webp[ explode( '?', $url, 2 )[0] ] ) ) {
				continue;
			}
			++$counts['found'];

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host !== $site_host ) {
				++$counts['external'];
				$items[] = self::item( 'external', $url, __( 'Hosted on another website, so it cannot be optimized.', 'kwip-image-plugin' ), $labels['external'] );
				continue;
			}
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
				++$counts['other'];
				continue;
			}
			if ( isset( $page_webp[ explode( '?', $url, 2 )[0] . '.webp' ] ) ) {
				++$counts['webp'];
				$items[] = self::item( 'webp', $url, __( 'Delivered as WebP through the page.', 'kwip-image-plugin' ), $labels['webp'] );
				continue;
			}
			if ( $tested >= self::MAX_TESTED || ( microtime( true ) - $start ) > self::TIME_BUDGET ) {
				++$counts['untested'];
				continue;
			}
			++$tested;

			$bust = add_query_arg( 'kwip_scan', (string) time(), $url );
			$with = wp_remote_head( $bust, $request + array( 'headers' => array( 'Accept' => 'image/webp,image/*;q=0.8,*/*;q=0.5' ) ) );

			if ( is_wp_error( $with ) ) {
				++$counts['error'];
				$items[] = self::item(
					'error',
					$url,
					sprintf(
						__( 'Could not be checked (%s).', 'kwip-image-plugin' ),
						$with->get_error_message()
					),
					$labels['error']
				);
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $with );
			if ( 200 !== $code ) {
				++$counts['error'];
				$items[] = self::item(
					'error',
					$url,
					sprintf(
						__( 'Could not be checked (the server answered HTTP %d).', 'kwip-image-plugin' ),
						$code
					),
					$labels['error']
				);
				continue;
			}

			if ( str_contains( self::header_value( $with, 'content-type' ), 'image/webp' ) ) {
				++$counts['webp'];
				$webp_len = (int) self::header_value( $with, 'content-length' );
				$plain    = wp_remote_head( $bust, $request + array( 'headers' => array( 'Accept' => 'image/png,image/jpeg;q=0.9,*/*;q=0.5' ) ) );
				$orig_len = is_wp_error( $plain ) ? 0 : (int) self::header_value( $plain, 'content-length' );
				$note     = __( 'Delivered as WebP.', 'kwip-image-plugin' );

				if ( $webp_len > 0 && $orig_len > 0 ) {
					$from += $orig_len;
					$to   += $webp_len;
					$note  = sprintf(
						__( 'Delivered as WebP: %1$s instead of %2$s.', 'kwip-image-plugin' ),
						(string) size_format( $webp_len ),
						(string) size_format( $orig_len )
					);
				}
				$items[] = self::item( 'webp', $url, $note, $labels['webp'] );
				continue;
			}

			// The original was sent. Work out why.
			$file = self::file_for_url( $url );
			if ( null === $file ) {
				++$counts['outside'];
				$items[] = self::item( 'outside', $url, __( 'Not in your uploads folder (for example a theme or plugin image), so it cannot be optimized.', 'kwip-image-plugin' ), $labels['outside'] );
			} elseif ( is_file( KWIP_Files::webp_path( $file ) ) ) {
				++$counts['blocked'];
				$items[] = self::item( 'blocked', $url, __( 'An optimized copy exists, but the original is being sent. A cache, CDN or server rule may be in the way.', 'kwip-image-plugin' ), $labels['blocked'] );
			} else {
				++$counts['pending'];
				$items[] = self::item( 'pending', $url, __( 'Still the original. No optimized copy exists yet: it was not converted, or the WebP was not smaller.', 'kwip-image-plugin' ), $labels['pending'] );
			}
		}

		$order = array(
			'blocked'  => 0,
			'error'    => 1,
			'pending'  => 2,
			'outside'  => 3,
			'external' => 4,
			'webp'     => 5,
		);
		usort(
			$items,
			static function ( array $a, array $b ) use ( $order ): int {
				return $order[ $a['status'] ] <=> $order[ $b['status'] ];
			}
		);

		return array(
			'summary' => $counts,
			'items'   => array_slice( $items, 0, 80 ),
			'savings' => array(
				'original' => $from,
				'webp'     => $to,
			),
		);
	}

	private static function file_for_url( string $url ): ?string {
		return KWIP_Files::file_for_url( $url );
	}

	/** @return array<string, string> */
	private static function item( string $status, string $url, string $note, string $label ): array {
		return array(
			'status' => $status,
			'url'    => $url,
			'name'   => rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ),
			'label'  => $label,
			'note'   => $note,
		);
	}

	/** @return array<string, string> */
	private static function labels(): array {
		return array(
			'webp'     => __( 'Delivered as WebP', 'kwip-image-plugin' ),
			'pending'  => __( 'Not optimized yet', 'kwip-image-plugin' ),
			'blocked'  => __( 'Not delivered', 'kwip-image-plugin' ),
			'outside'  => __( 'Outside your uploads folder', 'kwip-image-plugin' ),
			'external' => __( 'Other website', 'kwip-image-plugin' ),
			'error'    => __( 'Could not be checked', 'kwip-image-plugin' ),
		);
	}

	/** @param array<string, mixed>|WP_Error $response HTTP response. */
	private static function header_value( $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}
}