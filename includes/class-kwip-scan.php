<?php
/**
 * Page image report: scans a page of this site and reports image weight,
 * WebP delivery, sizing, loading and accessibility, with a score and a fix list.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Scan {

	private const NONCE_ACTION   = 'kwip_scan';
	private const HISTORY_OPTION = 'kwip_scan_history';
	private const MAX_TESTED     = 40;
	private const MAX_LISTED     = 120;
	private const TIME_BUDGET    = 20;
	private const HEAVY_BYTES    = 512000;

	public static function init(): void {
		add_action( 'wp_ajax_kwip_scan', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_ajax_kwip_scan_fix', array( __CLASS__, 'handle_fix' ) );
	}

	public static function enqueue(): void {
		wp_enqueue_script( 'kwip-scan', KWIP_URL . 'assets/js/scan.js', array( 'jquery', 'wp-a11y', 'kwip-admin' ), KWIP_VERSION, true );
		wp_localize_script(
			'kwip-scan',
			'kwipScan',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => self::strings(),
			)
		);
	}

	/** @return array<string, string> */
	private static function strings(): array {
		return array(
			'scan'                 => __( 'Scan page', 'kwip-image-plugin' ),
			'scanning'             => __( 'Scanning…', 'kwip-image-plugin' ),
			'scanningNote'         => __( 'Loading the page and checking every image. This can take up to half a minute.', 'kwip-image-plugin' ),
			'error'                => __( 'Something went wrong. Please try again.', 'kwip-image-plugin' ),
			'noImages'             => __( 'No JPG or PNG images were found on this page', 'kwip-image-plugin' ),
			'noImagesDetail'       => __( 'Images added by scripts after the page loads, or by external stylesheets, cannot be detected by this check.', 'kwip-image-plugin' ),
			'score'                => __( 'Image score', 'kwip-image-plugin' ),
			'weightNow'            => __( 'Image weight now', 'kwip-image-plugin' ),
			'weightBefore'         => __( 'Without optimization', 'kwip-image-plugin' ),
			'weightNowShort'       => __( 'With optimization', 'kwip-image-plugin' ),
			'weightSaved'          => __( 'Optimization saves %1$s on this page (%2$s percent less).', 'kwip-image-plugin' ),
			'weightPotential'      => __( 'Optimizing the remaining images could save about %s more.', 'kwip-image-plugin' ),
			'weightUnknown'        => __( 'The size of these images could not be measured.', 'kwip-image-plugin' ),
			'partOptimization'     => __( 'Optimization', 'kwip-image-plugin' ),
			'partSizing'           => __( 'Sizing', 'kwip-image-plugin' ),
			'partLoading'          => __( 'Loading', 'kwip-image-plugin' ),
			'partAccess'           => __( 'Accessibility', 'kwip-image-plugin' ),
			'partOptimizationHint' => __( 'Share of image weight delivered as WebP', 'kwip-image-plugin' ),
			'partSizingHint'       => __( 'Images no bigger than they need to be', 'kwip-image-plugin' ),
			'partLoadingHint'      => __( 'Lazy loading and reserved space', 'kwip-image-plugin' ),
			'partAccessHint'       => __( 'Images that have alt text', 'kwip-image-plugin' ),
			'since'                => __( 'Since your last scan, %s ago', 'kwip-image-plugin' ),
			'scoreChange'          => __( 'Score: %1$s to %2$s', 'kwip-image-plugin' ),
			'weightChange'         => __( 'Image weight: %1$s to %2$s', 'kwip-image-plugin' ),
			'fixTitle'             => __( 'What to fix first', 'kwip-image-plugin' ),
			'allClear'             => __( 'Nothing needs fixing on this page.', 'kwip-image-plugin' ),
			'listTitle'            => __( 'Every image', 'kwip-image-plugin' ),
			'filterAll'            => __( 'All', 'kwip-image-plugin' ),
			'filterAttention'      => __( 'Needs attention', 'kwip-image-plugin' ),
			'filterOptimized'      => __( 'Optimized', 'kwip-image-plugin' ),
			'filterOther'          => __( 'Cannot be optimized', 'kwip-image-plugin' ),
			'sortLabel'            => __( 'Sort images', 'kwip-image-plugin' ),
			'sortImpact'           => __( 'Biggest problems first', 'kwip-image-plugin' ),
			'sortSize'             => __( 'Largest first', 'kwip-image-plugin' ),
			'sortOrder'            => __( 'Order on the page', 'kwip-image-plugin' ),
			'emptyFilter'          => __( 'No images in this view.', 'kwip-image-plugin' ),
			'shownAt'              => __( '%1$s px wide, shown at %2$s px', 'kwip-image-plugin' ),
			'naturalSize'          => __( '%1$s × %2$s px', 'kwip-image-plugin' ),
			'mainImage'            => __( 'Main image', 'kwip-image-plugin' ),
			'background'           => __( 'Background image', 'kwip-image-plugin' ),
			'usedTimes'            => __( 'Used %s times', 'kwip-image-plugin' ),
			'optimizeNow'          => __( 'Optimize now', 'kwip-image-plugin' ),
			'optimizing'           => __( 'Optimizing…', 'kwip-image-plugin' ),
			'optimizedDone'        => __( 'Optimized: %1$s to %2$s. Scan again to confirm.', 'kwip-image-plugin' ),
			'optimizedNothing'     => __( 'Checked. This image did not get smaller, so the original stays.', 'kwip-image-plugin' ),
			'optimizeAll'          => __( 'Optimize these %s images now', 'kwip-image-plugin' ),
			'optimizeProgress'     => __( 'Optimizing %1$s of %2$s…', 'kwip-image-plugin' ),
			'optimizeAllDone'      => __( 'Done. Scan the page again to see the new score.', 'kwip-image-plugin' ),
			'rescan'               => __( 'Scan again', 'kwip-image-plugin' ),
			'exportCsv'            => __( 'Download CSV', 'kwip-image-plugin' ),
			'copySummary'          => __( 'Copy summary', 'kwip-image-plugin' ),
			'copied'               => __( 'Copied', 'kwip-image-plugin' ),
			'howTitle'             => __( 'How is this scored?', 'kwip-image-plugin' ),
			'how1'                 => __( 'Optimization (40%): how much of the page\'s image weight is delivered as WebP.', 'kwip-image-plugin' ),
			'how2'                 => __( 'Sizing (20%): images far larger than the space they are shown in count against the page.', 'kwip-image-plugin' ),
			'how3'                 => __( 'Loading (25%): the main image should load right away, images further down should be lazy-loaded, and every image should have its width and height set.', 'kwip-image-plugin' ),
			'how4'                 => __( 'Accessibility (15%): the share of images that have alt text.', 'kwip-image-plugin' ),
			'how5'                 => __( 'This is KWIP Image\'s own measure, not an official Google score. Sizes are measured for each image\'s default address, and images that only appear through scripts or external stylesheets cannot be seen.', 'kwip-image-plugin' ),
			'scannedPage'          => __( 'Page', 'kwip-image-plugin' ),
		);
	}

	public static function render_card(): void {
		?>
		<section class="kwip-card kwip-scan">
			<h2><?php esc_html_e( 'Page image report', 'kwip-image-plugin' ); ?></h2>
			<p class="kwip-lead"><?php esc_html_e( 'Enter any page of your site to get an image report: how much the images weigh, which are delivered as WebP, which are larger than they need to be, and what to fix first. Scan again after fixing to see the improvement.', 'kwip-image-plugin' ); ?></p>

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

	public static function handle_fix(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'kwip-image-plugin' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		$result = KWIP_Converter::convert_attachment( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		KWIP_Queue::dequeue( $id );

		wp_send_json_success(
			array(
				'status'   => (string) $result['status'],
				'original' => (int) $result['original_total'],
				'webp'     => (int) $result['webp_total'],
			)
		);
	}

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

		$images = self::extract_images( $html, $page_url );
		if ( is_wp_error( $images ) ) {
			return $images;
		}

		$report         = self::analyze( $images );
		$report['page'] = $page_url;
		$report['time'] = time();

		$report['previous'] = null;
		$report['trend']    = array();
		if ( null !== $report['score'] ) {
			$memory             = self::remember( $page_url, $report );
			$report['previous'] = $memory['previous'];
			$report['trend']    = $memory['trend'];
		}

		return $report;
	}

	/**
	 * One record per image address, in page order. <img> elements carry their
	 * attributes (size, alt, lazy loading); backgrounds from inline styles,
	 * <style> blocks and data-bg attributes are listed as plain records.
	 * Images added by JavaScript later or by external stylesheet files cannot be seen.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function extract_images( string $html, string $base ): array|WP_Error {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'kwip_scan_dom', __( 'The page report needs the PHP "dom" extension, which is not available on this server.', 'kwip-image-plugin' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$records = array();
		$order   = 0;

		foreach ( $dom->getElementsByTagName( '*' ) as $el ) {
			$tag = strtolower( $el->nodeName );

			if ( 'script' === $tag ) {
				continue;
			}
			if ( 'style' === $tag ) {
				foreach ( self::css_urls( (string) $el->textContent ) as $css_url ) {
					self::push_background( $records, $css_url, $base, ++$order );
				}
				continue;
			}
			if ( 'img' === $tag ) {
				if ( ! self::inside( $el, 'noscript' ) ) {
					$record = self::img_record( $el, $base );
					if ( null !== $record ) {
						self::push( $records, $record, ++$order );
					}
				}
				continue;
			}

			foreach ( array( 'data-bg', 'data-background-image' ) as $attr ) {
				if ( $el->hasAttribute( $attr ) ) {
					$value = $el->getAttribute( $attr );
					self::push_background( $records, $value, $base, ++$order );
					foreach ( self::css_urls( $value ) as $css_url ) {
						self::push_background( $records, $css_url, $base, ++$order );
					}
				}
			}
			if ( $el->hasAttribute( 'style' ) ) {
				foreach ( self::css_urls( $el->getAttribute( 'style' ) ) as $css_url ) {
					self::push_background( $records, $css_url, $base, ++$order );
				}
			}
		}

		return array_values( $records );
	}

	/** @return array<string, mixed>|null */
	private static function img_record( DOMElement $el, string $base ): ?array {
		$src         = trim( $el->getAttribute( 'src' ) );
		$script_lazy = false;

		if ( '' === $src || preg_match( '#^data:#i', $src ) ) {
			foreach ( array( 'data-src', 'data-lazy-src', 'data-original' ) as $attr ) {
				if ( $el->hasAttribute( $attr ) && '' !== trim( $el->getAttribute( $attr ) ) ) {
					$src         = trim( $el->getAttribute( $attr ) );
					$script_lazy = true;
					break;
				}
			}
		}

		$srcset = trim( $el->getAttribute( 'srcset' ) );
		if ( '' === $srcset ) {
			foreach ( array( 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
				if ( $el->hasAttribute( $attr ) ) {
					$srcset = trim( $el->getAttribute( $attr ) );
					break;
				}
			}
		}
		$candidates = self::srcset_urls( $srcset );

		if ( '' === $src && ! empty( $candidates ) ) {
			$src = $candidates[0];
		}
		if ( '' === $src || preg_match( '#^(data|blob):#i', $src ) ) {
			return null;
		}

		$url = self::resolve( trim( html_entity_decode( $src ) ), $base );
		if ( null === $url ) {
			return null;
		}

		$width  = self::dimension( $el->getAttribute( 'width' ) );
		$height = self::dimension( $el->getAttribute( 'height' ) );
		if ( ( null !== $width && $width <= 2 ) || ( null !== $height && $height <= 2 ) ) {
			return null; // Tracking pixels.
		}

		$picture_webp = false;
		$parent       = $el->parentNode;
		if ( $parent instanceof DOMElement && 'picture' === strtolower( $parent->nodeName ) ) {
			foreach ( $parent->getElementsByTagName( 'source' ) as $source ) {
				$type = strtolower( $source->getAttribute( 'type' ) );
				$set  = $source->getAttribute( 'srcset' );
				if ( 'image/webp' === $type || preg_match( '/\.webp(?:\s|,|\?|$)/i', $set ) ) {
					$picture_webp = true;
					break;
				}
			}
		}

		$alt = 'missing';
		if ( $el->hasAttribute( 'alt' ) ) {
			$alt = '' === trim( $el->getAttribute( 'alt' ) ) ? 'empty' : 'ok';
		}

		return array(
			'url'          => $url,
			'kind'         => 'img',
			'width'        => $width,
			'height'       => $height,
			'alt'          => $alt,
			'loading'      => strtolower( $el->getAttribute( 'loading' ) ),
			'has_srcset'   => count( $candidates ) > 1,
			'script_lazy'  => $script_lazy,
			'picture_webp' => $picture_webp,
			'count'        => 1,
		);
	}

	/** @param array<string, array<string, mixed>> $records Collected records (by reference). */
	private static function push_background( array &$records, string $raw, string $base, int $order ): void {
		$raw = trim( html_entity_decode( $raw ) );
		if ( '' === $raw || preg_match( '#^(data|blob|javascript|about):#i', $raw ) || str_contains( $raw, '(' ) ) {
			return;
		}
		$url = self::resolve( $raw, $base );
		if ( null === $url ) {
			return;
		}
		self::push(
			$records,
			array(
				'url'          => $url,
				'kind'         => 'background',
				'width'        => null,
				'height'       => null,
				'alt'          => 'ok',
				'loading'      => '',
				'has_srcset'   => false,
				'script_lazy'  => false,
				'picture_webp' => false,
				'count'        => 1,
			),
			$order
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $records Collected records (by reference).
	 * @param array<string, mixed>                $record  New record.
	 */
	private static function push( array &$records, array $record, int $order ): void {
		$key = (string) $record['url'];
		if ( isset( $records[ $key ] ) ) {
			++$records[ $key ]['count'];
			if ( ! empty( $record['picture_webp'] ) ) {
				$records[ $key ]['picture_webp'] = true;
			}
			return;
		}
		$record['order'] = $order;
		$records[ $key ] = $record;
	}

	private static function inside( DOMNode $node, string $tag ): bool {
		for ( $parent = $node->parentNode; null !== $parent; $parent = $parent->parentNode ) {
			if ( $parent instanceof DOMElement && $tag === strtolower( $parent->nodeName ) ) {
				return true;
			}
		}
		return false;
	}

	private static function dimension( string $value ): ?int {
		return preg_match( '/^\s*(\d+)\s*(?:px)?\s*$/i', $value, $m ) ? (int) $m[1] : null;
	}

	/** @return string[] */
	private static function srcset_urls( string $srcset ): array {
		$urls = array();
		if ( '' === $srcset ) {
			return $urls;
		}
		foreach ( preg_split( '/,\s+/', trim( $srcset ) ) ?: array() as $part ) {
			$bits = preg_split( '/\s+/', trim( $part ) );
			if ( ! empty( $bits[0] ) ) {
				$urls[] = $bits[0];
			}
		}
		return $urls;
	}

	/** @return string[] */
	private static function css_urls( string $css ): array {
		$out = array();
		if ( preg_match_all( '/url\(\s*(["\']?)(.*?)\1\s*\)/i', $css, $matches ) ) {
			foreach ( $matches[2] as $found ) {
				$out[] = trim( (string) $found );
			}
		}
		return $out;
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
	 * @param array<int, array<string, mixed>> $images Image records.
	 * @return array<string, mixed>
	 */
	private static function analyze( array $images ): array {
		$method    = KWIP_Delivery::active_method();
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$labels    = self::labels();
		$stats     = KWIP_Stats::savings();
		$ratio     = $stats['original'] > 0 ? max( 0.0, 1 - ( $stats['webp'] / $stats['original'] ) ) : 0.0;

		$counts = array(
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
		$issue_counts = array();
		$totals       = array(
			'delivered' => 0,
			'original'  => 0,
			'wasted'    => 0,
			'potential' => 0,
		);
		$cover        = array(
			'done' => 0,
			'todo' => 0,
		);
		$flow         = array(
			'content'     => 0,
			'no_dims'     => 0,
			'below'       => 0,
			'below_eager' => 0,
			'alt_total'   => 0,
			'alt_ok'      => 0,
			'main_lazy'   => false,
			'main_found'  => false,
		);
		$items        = array();
		$tested       = 0;
		$start        = microtime( true );
		$request      = array(
			'timeout'     => 8,
			'redirection' => 2,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		);

		foreach ( $images as $image ) {
			$url  = (string) $image['url'];
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif' ), true ) || preg_match( '/\.(?:jpe?g|png)\.webp$/i', $path ) ) {
				continue;
			}
			++$counts['found'];

			$is_img = 'img' === $image['kind'];
			$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$local  = $host === $site_host;
			$file   = $local ? KWIP_Files::file_for_url( $url ) : null;
			$onDisk = null !== $file && is_file( $file );
			$size   = $onDisk ? wp_getimagesize( $file ) : false;
			$nat_w  = is_array( $size ) ? (int) $size[0] : 0;
			$nat_h  = is_array( $size ) ? (int) $size[1] : 0;

			$status     = 'pending';
			$note       = '';
			$delivered  = 0;
			$original   = 0;
			$attachment = 0;

			if ( ! $local ) {
				$status = 'external';
				$note   = __( 'Hosted on another website, so it cannot be optimized.', 'kwip-image-plugin' );
			} elseif ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
				$status    = 'other';
				$note      = __( 'Already a web-friendly format.', 'kwip-image-plugin' );
				$delivered = $onDisk ? (int) filesize( $file ) : 0;
				$original  = $delivered;
			} elseif ( ! $onDisk ) {
				$status = 'outside';
				$note   = null === $file
					? __( 'Not in your uploads folder (for example a theme or plugin image), so it cannot be optimized.', 'kwip-image-plugin' )
					: __( 'The file was not found on this server (it may be stored elsewhere), so it cannot be optimized.', 'kwip-image-plugin' );
				if ( self::can_probe( $tested, $start ) ) {
					++$tested;
					$head = self::head( $url, $request, 'image/*,*/*;q=0.5' );
					if ( ! is_wp_error( $head ) && 200 === (int) wp_remote_retrieve_response_code( $head ) ) {
						$delivered = (int) self::header_value( $head, 'content-length' );
						$original  = $delivered;
					}
				}
			} else {
				$original  = (int) filesize( $file );
				$delivered = $original;
				$webp_file = KWIP_Files::webp_path( $file );
				$has_webp  = is_file( $webp_file );
				$webp_size = $has_webp ? (int) filesize( $webp_file ) : 0;

				if ( $has_webp && ! empty( $image['picture_webp'] ) ) {
					$status    = 'webp';
					$delivered = $webp_size;
					$note      = sprintf(
						__( 'Delivered as WebP through the page: %1$s instead of %2$s.', 'kwip-image-plugin' ),
						(string) size_format( $webp_size ),
						(string) size_format( $original )
					);
				} elseif ( 'page' === $method || 'none' === $method ) {
					if ( $has_webp ) {
						$status = 'blocked';
						if ( 'none' === $method ) {
							$note = __( 'An optimized copy exists, but delivery is switched off in Settings.', 'kwip-image-plugin' );
						} elseif ( 'background' === $image['kind'] ) {
							$note = __( 'An optimized copy exists, but this is a CSS background, which the page method cannot change.', 'kwip-image-plugin' );
						} else {
							$note = __( 'An optimized copy exists, but this image is not wrapped in the page. A page builder or script may output it in a way the page method cannot see.', 'kwip-image-plugin' );
						}
					} else {
						$note = __( 'Still the original. No optimized copy exists yet: it was not converted, or the WebP was not smaller.', 'kwip-image-plugin' );
					}
				} elseif ( ! self::can_probe( $tested, $start ) ) {
					$status = 'untested';
					$note   = __( 'Not checked, because this page has more images than one scan can test.', 'kwip-image-plugin' );
				} else {
					++$tested;
					$head = self::head( $url, $request, 'image/webp,image/*;q=0.8,*/*;q=0.5' );
					$code = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_response_code( $head );

					if ( is_wp_error( $head ) ) {
						$status = 'error';
						$note   = sprintf(
							__( 'Could not be checked (%s).', 'kwip-image-plugin' ),
							$head->get_error_message()
						);
					} elseif ( 200 !== $code ) {
						$status = 'error';
						$note   = sprintf(
							__( 'Could not be checked (the server answered HTTP %d).', 'kwip-image-plugin' ),
							$code
						);
					} elseif ( str_contains( self::header_value( $head, 'content-type' ), 'image/webp' ) ) {
						$status    = 'webp';
						$delivered = $has_webp ? $webp_size : (int) self::header_value( $head, 'content-length' );
						$note      = sprintf(
							__( 'Delivered as WebP: %1$s instead of %2$s.', 'kwip-image-plugin' ),
							(string) size_format( $delivered ),
							(string) size_format( $original )
						);
					} elseif ( $has_webp ) {
						$status = 'blocked';
						$note   = __( 'An optimized copy exists, but the original is being sent. A cache, CDN or server rule may be in the way.', 'kwip-image-plugin' );
					} else {
						$note = __( 'Still the original. No optimized copy exists yet: it was not converted, or the WebP was not smaller.', 'kwip-image-plugin' );
					}
				}

				if ( 'pending' === $status ) {
					$attachment = (int) attachment_url_to_postid( explode( '?', $url, 2 )[0] );
					if ( $ratio > 0 ) {
						$totals['potential'] += (int) round( $original * $ratio );
					}
				}
			}

			++$counts[ $status ];

			if ( 'webp' === $status ) {
				$cover['done'] += $original;
			} elseif ( in_array( $status, array( 'pending', 'blocked' ), true ) ) {
				$cover['todo'] += $original;
			}
			$totals['delivered'] += $delivered;
			$totals['original']  += $original > 0 ? $original : $delivered;

			$issues = array();
			$main   = false;
			if ( $is_img ) {
				$shown_w    = $image['width'];
				$is_content = ( null === $shown_w || $shown_w >= 100 ) && ( 0 === $nat_w || $nat_w >= 100 );

				if ( $is_content ) {
					++$flow['content'];
					++$flow['alt_total'];
					if ( 'missing' !== $image['alt'] ) {
						++$flow['alt_ok'];
					} else {
						$issues[] = 'no_alt';
					}
					if ( null === $image['width'] || null === $image['height'] ) {
						++$flow['no_dims'];
						$issues[] = 'no_dims';
					}

					$big = ( null !== $shown_w && $shown_w >= 300 ) || ( null === $shown_w && $nat_w >= 600 );
					if ( ! $flow['main_found'] && $big ) {
						$flow['main_found'] = true;
						$main               = true;
						if ( 'lazy' === $image['loading'] ) {
							$flow['main_lazy'] = true;
							$issues[]          = 'main_lazy';
						}
					} elseif ( $flow['content'] > 3 ) {
						++$flow['below'];
						if ( 'lazy' !== $image['loading'] && empty( $image['script_lazy'] ) ) {
							++$flow['below_eager'];
							$issues[] = 'not_lazy';
						}
					}
				}

				if ( ! $image['has_srcset'] && $nat_w > 0 && null !== $shown_w && $shown_w > 0 && $nat_w >= 2 * $shown_w && $delivered >= 30000 ) {
					$totals['wasted'] += (int) round( $delivered * ( 1 - pow( $shown_w / $nat_w, 2 ) ) );
					$issues[]          = 'oversized';
				}
			}
			if ( $delivered >= self::HEAVY_BYTES ) {
				$issues[] = 'heavy';
			}
			foreach ( $issues as $code ) {
				$issue_counts[ $code ] = ( $issue_counts[ $code ] ?? 0 ) + 1;
			}

			$items[] = array(
				'status'     => $status,
				'label'      => $labels[ $status ],
				'url'        => $url,
				'name'       => rawurldecode( wp_basename( $path ) ),
				'note'       => $note,
				'kind'       => (string) $image['kind'],
				'main'       => $main,
				'nw'         => $nat_w,
				'nh'         => $nat_h,
				'shown'      => $image['width'],
				'delivered'  => $delivered,
				'original'   => $original > 0 ? $original : $delivered,
				'attachment' => $attachment,
				'thumb'      => $local,
				'issues'     => self::issue_tags( $issues ),
				'attention'  => in_array( $status, array( 'pending', 'blocked', 'error', 'untested' ), true ) || ! empty( $issues ),
				'order'      => (int) ( $image['order'] ?? 0 ),
				'count'      => (int) $image['count'],
			);
		}

		if ( 0 === $counts['found'] ) {
			return array(
				'method'          => $method,
				'method_label'    => KWIP_Delivery::method_label(),
				'score'           => null,
				'weight'          => $totals + array( 'saved' => 0 ),
				'summary'         => $counts,
				'issues'          => array(),
				'recommendations' => array(),
				'items'           => array(),
				'listed'          => 0,
			);
		}

		$rank = array(
			'blocked'  => 0,
			'error'    => 1,
			'pending'  => 2,
			'untested' => 3,
			'outside'  => 4,
			'external' => 5,
			'other'    => 6,
			'webp'     => 7,
		);
		usort(
			$items,
			static function ( array $a, array $b ) use ( $rank ): int {
				return array( $rank[ $a['status'] ], -count( $a['issues'] ), -$a['delivered'] ) <=> array( $rank[ $b['status'] ], -count( $b['issues'] ), -$b['delivered'] );
			}
		);

		$totals['saved'] = max( 0, $totals['original'] - $totals['delivered'] );

		return array(
			'method'          => $method,
			'method_label'    => KWIP_Delivery::method_label(),
			'score'           => self::score( $cover, $totals, $flow ),
			'weight'          => $totals,
			'summary'         => $counts,
			'issues'          => $issue_counts,
			'recommendations' => self::recommendations( $counts, $issue_counts, $totals, $method ),
			'items'           => array_slice( $items, 0, self::MAX_LISTED ),
			'listed'          => count( $items ),
		);
	}

	private static function can_probe( int $tested, float $start ): bool {
		return $tested < self::MAX_TESTED && ( microtime( true ) - $start ) <= self::TIME_BUDGET;
	}

	/**
	 * @param array<string, mixed> $request Base request arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function head( string $url, array $request, string $accept ) {
		return wp_remote_head( add_query_arg( 'kwip_scan', (string) time(), $url ), $request + array( 'headers' => array( 'Accept' => $accept ) ) );
	}

	/** @param array<string, mixed>|WP_Error $response HTTP response. */
	private static function header_value( $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}


	/**
	 * Our own 0-100 measure: optimization 40%, sizing 20%, loading 25%, accessibility 15%.
	 *
	 * @param array<string, int>   $cover  Original bytes already delivered as WebP ("done") or still to do ("todo").
	 * @param array<string, int>   $totals Weight totals.
	 * @param array<string, mixed> $flow   Loading and accessibility counters.
	 * @return array<string, mixed>
	 */
	private static function score( array $cover, array $totals, array $flow ): array {
		$optimizable  = $cover['done'] + $cover['todo'];
		$optimization = $optimizable > 0 ? (int) round( 100 * $cover['done'] / $optimizable ) : 100;
		$sizing       = $totals['delivered'] > 0 ? (int) round( max( 0, 100 - ( 100 * $totals['wasted'] / $totals['delivered'] ) ) ) : 100;

		$loading = 100;
		if ( $flow['main_lazy'] ) {
			$loading -= 40;
		}
		if ( $flow['content'] > 0 ) {
			$loading -= (int) round( 30 * $flow['no_dims'] / $flow['content'] );
		}
		if ( $flow['below'] > 0 ) {
			$loading -= (int) round( 30 * $flow['below_eager'] / $flow['below'] );
		}
		$loading = max( 0, $loading );

		$access  = $flow['alt_total'] > 0 ? (int) round( 100 * $flow['alt_ok'] / $flow['alt_total'] ) : 100;
		$overall = (int) round( 0.4 * $optimization + 0.2 * $sizing + 0.25 * $loading + 0.15 * $access );

		if ( $overall >= 90 ) {
			$grade    = __( 'Excellent', 'kwip-image-plugin' );
			$headline = __( 'Your images are in great shape', 'kwip-image-plugin' );
			$tone     = 'ok';
		} elseif ( $overall >= 75 ) {
			$grade    = __( 'Good', 'kwip-image-plugin' );
			$headline = __( 'Good, with room to improve', 'kwip-image-plugin' );
			$tone     = 'ok';
		} elseif ( $overall >= 50 ) {
			$grade    = __( 'Needs work', 'kwip-image-plugin' );
			$headline = __( 'This page needs some image work', 'kwip-image-plugin' );
			$tone     = 'warn';
		} else {
			$grade    = __( 'Poor', 'kwip-image-plugin' );
			$headline = __( 'This page has several image problems', 'kwip-image-plugin' );
			$tone     = 'bad';
		}

		return array(
			'overall'  => $overall,
			'grade'    => $grade,
			'headline' => $headline,
			'tone'     => $tone,
			'parts'    => array(
				'optimization' => $optimization,
				'sizing'       => $sizing,
				'loading'      => $loading,
				'access'       => $access,
			),
		);
	}

	/**
	 * @param array<string, int> $counts Status counts.
	 * @param array<string, int> $issues Issue counts.
	 * @param array<string, int> $totals Weight totals.
	 * @return array<int, array<string, mixed>>
	 */
	private static function recommendations( array $counts, array $issues, array $totals, string $method ): array {
		$recs = array();

		if ( $counts['blocked'] > 0 ) {
			if ( 'none' === $method ) {
				$detail = __( 'Delivery is switched off in Settings, so visitors receive the original images.', 'kwip-image-plugin' );
				$action = 'settings';
				$label  = __( 'Open Settings', 'kwip-image-plugin' );
			} elseif ( 'page' === $method ) {
				$detail = __( 'These images have an optimized copy, but the page method cannot reach them (for example CSS backgrounds or images added by scripts), so visitors still get the originals.', 'kwip-image-plugin' );
				$action = 'status';
				$label  = __( 'Open Health check', 'kwip-image-plugin' );
			} else {
				$detail = __( 'A cache, CDN or server rule is probably answering before the server can swap in the WebP. Use "Check that it works" in the Health check, or try the page method in Settings.', 'kwip-image-plugin' );
				$action = 'status';
				$label  = __( 'Open Health check', 'kwip-image-plugin' );
			}
			$recs[] = self::rec(
				'blocked',
				'high',
				sprintf(
					_n( '%d optimized image is not reaching visitors', '%d optimized images are not reaching visitors', $counts['blocked'], 'kwip-image-plugin' ),
					$counts['blocked']
				),
				$detail,
				0,
				$action,
				$label
			);
		}

		if ( ! empty( $issues['main_lazy'] ) ) {
			$recs[] = self::rec(
				'main_lazy',
				'high',
				__( 'The main image on this page is set to load lazily', 'kwip-image-plugin' ),
				__( 'Lazy loading delays the picture visitors see first. Ask your developer or page builder to load that image straight away.', 'kwip-image-plugin' ),
				0
			);
		}

		if ( $counts['pending'] > 0 ) {
			$detail = $totals['potential'] > 0
				? sprintf(
					__( 'Estimated saving: about %s, based on how your other images compressed.', 'kwip-image-plugin' ),
					(string) size_format( $totals['potential'] )
				)
				: __( 'Images that come out no smaller stay as originals on purpose.', 'kwip-image-plugin' );
			$recs[] = self::rec(
				'pending',
				'medium',
				sprintf(
					_n( '%d image can still be optimized', '%d images can still be optimized', $counts['pending'], 'kwip-image-plugin' ),
					$counts['pending']
				),
				$detail,
				$totals['potential'],
				'fix_all',
				__( 'Open Optimize images', 'kwip-image-plugin' )
			);
		}

		if ( ! empty( $issues['oversized'] ) ) {
			$recs[] = self::rec(
				'oversized',
				'medium',
				sprintf(
					_n( '%d image is much larger than the space it is shown in', '%d images are much larger than the space they are shown in', $issues['oversized'], 'kwip-image-plugin' ),
					$issues['oversized']
				),
				sprintf(
					__( 'About %s of this is wasted. Upload smaller versions, or choose a smaller image size in your page builder.', 'kwip-image-plugin' ),
					(string) size_format( $totals['wasted'] )
				),
				$totals['wasted']
			);
		}

		if ( ! empty( $issues['heavy'] ) ) {
			$recs[] = self::rec(
				'heavy',
				'medium',
				sprintf(
					_n( '%d image is still very heavy (over 500 KB)', '%d images are still very heavy (over 500 KB)', $issues['heavy'], 'kwip-image-plugin' ),
					$issues['heavy']
				),
				__( 'Try a lower quality setting, or use a smaller version of the picture.', 'kwip-image-plugin' ),
				0
			);
		}

		if ( ! empty( $issues['no_dims'] ) ) {
			$recs[] = self::rec(
				'no_dims',
				'low',
				sprintf(
					_n( '%d image has no width and height set', '%d images have no width and height set', $issues['no_dims'], 'kwip-image-plugin' ),
					$issues['no_dims']
				),
				__( 'Without them, browsers cannot reserve space, so the page can jump around while it loads.', 'kwip-image-plugin' ),
				0
			);
		}

		if ( ! empty( $issues['not_lazy'] ) ) {
			$recs[] = self::rec(
				'not_lazy',
				'low',
				sprintf(
					_n( '%d image further down the page is not lazy-loaded', '%d images further down the page are not lazy-loaded', $issues['not_lazy'], 'kwip-image-plugin' ),
					$issues['not_lazy']
				),
				__( 'Loading them later makes the first view of the page faster.', 'kwip-image-plugin' ),
				0
			);
		}

		if ( ! empty( $issues['no_alt'] ) ) {
			$recs[] = self::rec(
				'no_alt',
				'low',
				sprintf(
					_n( '%d image has no alt text', '%d images have no alt text', $issues['no_alt'], 'kwip-image-plugin' ),
					$issues['no_alt']
				),
				__( 'Alt text helps visitors who use screen readers, and search engines understand your pictures.', 'kwip-image-plugin' ),
				0
			);
		}

		if ( $counts['outside'] > 0 ) {
			$recs[] = self::rec(
				'outside',
				'info',
				sprintf(
					_n( '%d image comes from your theme or plugins', '%d images come from your theme or plugins', $counts['outside'], 'kwip-image-plugin' ),
					$counts['outside']
				),
				__( 'This plugin only optimizes images in your Media Library.', 'kwip-image-plugin' ),
				0
			);
		}

		if ( $counts['external'] > 0 ) {
			$recs[] = self::rec(
				'external',
				'info',
				sprintf(
					_n( '%d image is hosted on another website', '%d images are hosted on other websites', $counts['external'], 'kwip-image-plugin' ),
					$counts['external']
				),
				__( 'It cannot be optimized from here, and its weight is not included in the totals.', 'kwip-image-plugin' ),
				0
			);
		}

		$severity = array(
			'high'   => 0,
			'medium' => 1,
			'low'    => 2,
			'info'   => 3,
		);
		usort(
			$recs,
			static function ( array $a, array $b ) use ( $severity ): int {
				return array( $severity[ $a['severity'] ], -$a['impact'] ) <=> array( $severity[ $b['severity'] ], -$b['impact'] );
			}
		);

		return $recs;
	}

	/** @return array<string, mixed> */
	private static function rec( string $id, string $severity, string $title, string $detail, int $impact, string $action = '', string $action_label = '' ): array {
		return array(
			'id'           => $id,
			'severity'     => $severity,
			'title'        => $title,
			'detail'       => $detail,
			'impact'       => $impact,
			'action'       => $action,
			'action_label' => $action_label,
		);
	}

	/**
	 * Remember this scan (last 10 per page, 20 pages) and return the previous one.
	 *
	 * @param array<string, mixed> $report Finished report.
	 * @return array{previous:?array<string, mixed>,trend:int[]}
	 */
	private static function remember( string $page_url, array $report ): array {
		$all = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$key  = md5( $page_url );
		$runs = ( isset( $all[ $key ]['runs'] ) && is_array( $all[ $key ]['runs'] ) ) ? $all[ $key ]['runs'] : array();

		$previous = null;
		if ( ! empty( $runs ) ) {
			$last = end( $runs );
			if ( is_array( $last ) && isset( $last['time'], $last['score'], $last['delivered'] ) ) {
				$previous = array(
					'time'      => (int) $last['time'],
					'score'     => (int) $last['score'],
					'delivered' => (int) $last['delivered'],
					'ago'       => human_time_diff( (int) $last['time'], time() ),
				);
			}
		}

		$runs[] = array(
			'time'      => time(),
			'score'     => (int) $report['score']['overall'],
			'delivered' => (int) $report['weight']['delivered'],
		);
		$runs   = array_slice( $runs, -10 );

		$all[ $key ] = array(
			'url'  => $page_url,
			'runs' => $runs,
		);

		if ( count( $all ) > 20 ) {
			$latest = static function ( array $entry ): int {
				$list = $entry['runs'] ?? array();
				$last = end( $list );
				return is_array( $last ) ? (int) ( $last['time'] ?? 0 ) : 0;
			};
			uasort(
				$all,
				static function ( array $a, array $b ) use ( $latest ): int {
					return $latest( $b ) <=> $latest( $a );
				}
			);
			$all = array_slice( $all, 0, 20, true );
		}
		update_option( self::HISTORY_OPTION, $all, false );

		return array(
			'previous' => $previous,
			'trend'    => array_map(
				static function ( array $run ): int {
					return (int) $run['score'];
				},
				$runs
			),
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
			'other'    => __( 'Already web-friendly', 'kwip-image-plugin' ),
			'untested' => __( 'Not checked', 'kwip-image-plugin' ),
		);
	}

	/**
	 * @param string[] $codes Issue codes.
	 * @return array<int, array{code:string,tone:string,label:string}>
	 */
	private static function issue_tags( array $codes ): array {
		$map = array(
			'main_lazy' => array( 'warn', __( 'Main image is lazy-loaded', 'kwip-image-plugin' ) ),
			'oversized' => array( 'warn', __( 'Larger than needed', 'kwip-image-plugin' ) ),
			'heavy'     => array( 'warn', __( 'Very heavy', 'kwip-image-plugin' ) ),
			'no_dims'   => array( 'info', __( 'No width or height set', 'kwip-image-plugin' ) ),
			'no_alt'    => array( 'info', __( 'No alt text', 'kwip-image-plugin' ) ),
			'not_lazy'  => array( 'info', __( 'Not lazy-loaded', 'kwip-image-plugin' ) ),
		);

		$tags = array();
		foreach ( $codes as $code ) {
			if ( isset( $map[ $code ] ) ) {
				$tags[] = array(
					'code'  => $code,
					'tone'  => $map[ $code ][0],
					'label' => $map[ $code ][1],
				);
			}
		}
		return $tags;
	}
}