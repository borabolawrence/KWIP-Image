<?php
/**
 * Page method: wraps images that have a WebP copy in <picture> tags.
 *
 * Every visitor receives the same HTML, so this is safe behind any server,
 * CDN or page cache; the visitor's browser decides which file to load.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Picture {

	/** Query parameters of page-builder editing screens, plus a debugging switch. */
	private const SKIP_PARAMS = array( 'kwip_off', 'elementor-preview', 'et_fb', 'fl_builder', 'app', 'ct_builder', 'brizy-edit', 'vc_editable' );

	private static bool $active = false;

	/** @var array<string, bool> */
	private static array $exists = array();

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_style' ), 1 );
	}

	public static function start(): void {
		if ( ! self::should_run() ) {
			return;
		}
		self::$active = true;
		ob_start( array( __CLASS__, 'process' ) );
	}

	/** Makes <picture> invisible to layout, so themes see the same <img> as before. */
	public static function print_style(): void {
		if ( self::$active ) {
			echo '<style id="kwip-picture-style">.kwip-picture{display:contents}</style>' . "\n";
		}
	}

	private static function should_run(): bool {
		if ( 'page' !== KWIP_Delivery::active_method() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() || is_embed() || is_customize_preview() || is_preview() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		$request = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $request ) {
			return false;
		}

		foreach ( self::SKIP_PARAMS as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return false;
			}
		}
		return true;
	}

	/**
	 * Output-buffer callback. Any problem returns the page unchanged.
	 */
	public static function process( string $html ): string {
		if ( strlen( $html ) < 200 || strlen( $html ) > 6 * MB_IN_BYTES || false === stripos( $html, '<img' ) || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return $html;
			}
		}

		try {
			return self::rewrite( $html );
		} catch ( Throwable $e ) {
			return $html;
		}
	}

	private static function rewrite( string $html ): string {
		$held = array();

		$work = preg_replace_callback(
			'#<(script|style|noscript|picture|textarea|template|svg)\b[^>]*>.*?</\1\s*>|<!--.*?-->#is',
			static function ( array $m ) use ( &$held ): string {
				$held[] = $m[0];
				return "\x01" . ( count( $held ) - 1 ) . "\x02";
			},
			$html
		);
		if ( ! is_string( $work ) ) {
			return $html;
		}

		$work = preg_replace_callback(
			'#<img\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i',
			static function ( array $m ): string {
				return self::wrap( $m[0] );
			},
			$work
		);
		if ( ! is_string( $work ) ) {
			return $html;
		}

		$restored = preg_replace_callback(
			"#\x01(\d+)\x02#",
			static function ( array $m ) use ( $held ): string {
				return $held[ (int) $m[1] ] ?? '';
			},
			$work
		);
		return is_string( $restored ) ? $restored : $html;
	}

	private static function wrap( string $tag ): string {
		if ( false !== stripos( $tag, 'data-kwip-skip' ) ) {
			return $tag;
		}

		$attrs  = self::attributes( $tag );
		$src    = $attrs['src'] ?? '';
		$srcset = $attrs['srcset'] ?? '';
		$sizes  = $attrs['sizes'] ?? '';

		$candidates = array();
		if ( '' !== $srcset ) {
			foreach ( preg_split( '/,\s+/', trim( $srcset ) ) ?: array() as $candidate ) {
				if ( ! preg_match( '/^(\S+)(?:\s+(\S+))?$/', trim( $candidate ), $m ) ) {
					continue;
				}
				$webp = self::webp_url( $m[1] );
				if ( null !== $webp ) {
					$candidates[] = $webp . ( isset( $m[2] ) ? ' ' . $m[2] : '' );
				}
			}
		} elseif ( '' !== $src ) {
			$webp = self::webp_url( $src );
			if ( null !== $webp ) {
				$candidates[] = $webp;
			}
		}

		if ( empty( $candidates ) ) {
			return $tag;
		}

		$source = '<source type="image/webp" srcset="' . esc_attr( implode( ', ', $candidates ) ) . '"';
		if ( '' !== $srcset && '' !== $sizes ) {
			$source .= ' sizes="' . esc_attr( $sizes ) . '"';
		}
		$source .= '>';

		return '<picture class="kwip-picture">' . $source . $tag . '</picture>';
	}

	/** URL of the WebP copy of a JPG/PNG uploads image, or null if there is none. */
	private static function webp_url( string $url ): ?string {
		$clean = explode( '?', explode( '#', trim( html_entity_decode( $url ) ), 2 )[0], 2 )[0];
		if ( ! preg_match( '/\.(?:jpe?g|png)$/i', $clean ) ) {
			return null;
		}

		if ( ! isset( self::$exists[ $clean ] ) ) {
			$file                    = KWIP_Files::file_for_url( self::absolute( $clean ) );
			self::$exists[ $clean ] = null !== $file && is_file( KWIP_Files::webp_path( $file ) );
		}
		return self::$exists[ $clean ] ? $clean . '.webp' : null;
	}

	private static function absolute( string $url ): string {
		if ( str_starts_with( $url, '//' ) || preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			$parts = wp_parse_url( home_url() );
			return ( $parts['scheme'] ?? 'http' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $url;
		}
		return $url;
	}

	/** @return array<string, string> Lower-cased attribute names mapped to decoded values. */
	private static function attributes( string $tag ): array {
		$out = array();
		$ok  = preg_match_all(
			'/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/',
			substr( $tag, 4 ),
			$matches,
			PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
		);
		if ( ! $ok ) {
			return $out;
		}

		foreach ( $matches as $m ) {
			$name = strtolower( $m[1] );
			if ( ! isset( $out[ $name ] ) ) {
				$out[ $name ] = html_entity_decode( (string) ( $m[2] ?? $m[3] ?? $m[4] ?? '' ) );
			}
		}
		return $out;
	}
}