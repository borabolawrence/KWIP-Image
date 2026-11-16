<?php
/**
 * KWIP_Picture: the page method (wrapping images in <picture>).
 */

$page = static function ( string $body ): string {
	return '<!DOCTYPE html><html><head><title>Test</title></head><body>' . $body . '<p>' . str_repeat( 'padding ', 40 ) . '</p></body></html>';
};

$converted = static function (): int {
	KT::prepare_conversion();
	$id = KT::attachment( 'jpeg' );
	$r  = KWIP_Converter::convert_attachment( $id );
	if ( is_wp_error( $r ) || 'converted' !== $r['status'] ) {
		KT::skip( 'The test image could not be converted on this server.' );
	}
	return $id;
};

return array(

	'wraps a converted image in <picture>' => static function () use ( $page, $converted ) {
		$id  = $converted();
		$img = wp_get_attachment_image( $id, 'medium' );
		KT::has( 'srcset=', $img, 'the test image has a srcset' );

		$out = KWIP_Picture::process( $page( $img ) );
		KT::has( '<picture class="kwip-picture">', $out, 'a <picture> wrapper' );
		KT::has( '<source type="image/webp"', $out, 'a WebP <source>' );
		KT::has( '.jpg.webp', $out, 'the WebP address' );
		KT::has( $img, $out, 'the original <img> tag is kept exactly' );
	},

	'keeps every size in the WebP srcset' => static function () use ( $page, $converted ) {
		$id  = $converted();
		$img = wp_get_attachment_image( $id, 'medium' );
		if ( ! preg_match( '/\ssrcset="([^"]*)"/', $img, $m ) ) {
			KT::skip( 'No srcset was generated for the test image.' );
		}
		$entries = preg_split( '/,\s+/', trim( $m[1] ) ) ?: array();
		KT::ok( count( $entries ) >= 3, 'the test image has at least 3 srcset sizes (got ' . count( $entries ) . ')' );

		// Remove the WebP of the second size, as if that size had been skipped.
		$second = preg_split( '/\s+/', trim( $entries[1] ) )[0];
		$file   = KWIP_Files::file_for_url( $second );
		KT::ok( null !== $file && is_file( $file . '.webp' ), 'the second size has a WebP to remove' );
		if ( null !== $file ) {
			@unlink( $file . '.webp' );
		}

		$out = KWIP_Picture::process( $page( $img ) );
		if ( ! preg_match( '/<source type="image\/webp" srcset="([^"]*)"/', $out, $s ) ) {
			KT::ok( false, 'a <source> was written' );
			return;
		}
		$wrapped = preg_split( '/,\s+/', trim( $s[1] ) ) ?: array();
		KT::same( count( $entries ), count( $wrapped ), 'no size is dropped from the srcset' );

		$plain = array_filter(
			$wrapped,
			static function ( string $entry ): bool {
				return ! str_contains( $entry, '.webp' );
			}
		);
		KT::same( 1, count( $plain ), 'only the size without a WebP keeps its original file' );
	},

	'leaves an image without a WebP alone' => static function () use ( $page ) {
		KT::prepare_conversion();
		$id   = KT::attachment( 'jpeg', 800, 600 );
		$html = $page( wp_get_attachment_image( $id, 'medium' ) );
		KT::same( $html, KWIP_Picture::process( $html ), 'the page is unchanged' );
	},

	'ignores noscript and comments, and wraps each image once' => static function () use ( $page, $converted ) {
		$id  = $converted();
		$img = wp_get_attachment_image( $id, 'medium' );
		$out = KWIP_Picture::process( $page( $img . '<noscript>' . $img . '</noscript><!-- ' . $img . ' -->' ) );
		KT::same( 1, substr_count( $out, '<picture' ), 'only the real image is wrapped' );

		$existing = '<picture><source srcset="a.webp"><img src="a.jpg" alt=""></picture>';
		KT::same( 1, substr_count( KWIP_Picture::process( $page( $existing . $img ) ), '<picture' ) - 1, 'an existing <picture> is not wrapped again' );
	},

	'respects data-kwip-skip' => static function () use ( $page, $converted ) {
		$id     = $converted();
		$tagged = str_replace( '<img ', '<img data-kwip-skip ', wp_get_attachment_image( $id, 'medium' ) );
		$html   = $page( $tagged );
		KT::same( $html, KWIP_Picture::process( $html ), 'a skipped image is unchanged' );
	},

	'the kwip_picture_skip filter can skip an image' => static function () use ( $page, $converted ) {
		$id   = $converted();
		$img  = wp_get_attachment_image( $id, 'medium' );
		$seen = array();

		$callback = static function ( $skip, $tag, $attrs ) use ( &$seen ) {
			$seen = $attrs;
			return true;
		};
		add_filter( 'kwip_picture_skip', $callback, 10, 3 );
		$skipped = KWIP_Picture::process( $page( $img ) );
		remove_filter( 'kwip_picture_skip', $callback, 10 );

		KT::same( $page( $img ), $skipped, 'the image is left alone' );
		KT::ok( ! empty( $seen['src'] ), 'the filter receives the attributes' );
		KT::has( '<picture', KWIP_Picture::process( $page( $img ) ), 'without the filter it is wrapped again' );
	},

	'leaves images from other sites alone' => static function () use ( $page ) {
		$html = $page( '<img src="https://example.com/a.jpg" width="10" height="10" alt="">' );
		KT::same( $html, KWIP_Picture::process( $html ), 'an external image is unchanged' );
	},

	'leaves a srcset it cannot read completely alone' => static function () use ( $page, $converted ) {
		$id   = $converted();
		$url  = (string) wp_get_attachment_url( $id );
		$html = $page( '<img src="' . $url . '" srcset="' . $url . ' 300w,' . $url . ' 600w" alt="">' );
		KT::same( $html, KWIP_Picture::process( $html ), 'the image is unchanged' );
	},

	'handles a query string on the image address' => static function () use ( $page, $converted ) {
		$id  = $converted();
		$url = (string) wp_get_attachment_url( $id );
		$out = KWIP_Picture::process( $page( '<img src="' . $url . '?ver=2" alt="">' ) );
		KT::has( '.jpg.webp"', $out, 'the WebP address has no query string' );
	},

	'skips tiny or non-HTML responses' => static function () {
		KT::same( '<img src="x">', KWIP_Picture::process( '<img src="x">' ), 'a tiny fragment' );
		$json = '{"html":"<img src=\"x.jpg\">"}' . str_repeat( ' ', 300 );
		KT::same( $json, KWIP_Picture::process( $json ), 'JSON is not touched' );
	},
);