<?php
/**
 * KWIP_Scan: reading a page the way a visitor's browser receives it.
 */

$extract = static function ( string $html ) {
	$method = new ReflectionMethod( 'KWIP_Scan', 'extract_images' );
	return $method->invoke( null, $html, home_url( '/page/' ) );
};

$page = static function ( string $body ): string {
	return '<!DOCTYPE html><html><head><title>Test</title></head><body>' . $body . '</body></html>';
};

$record_for = static function ( $records, string $url ): ?array {
	if ( ! is_array( $records ) ) {
		return null;
	}
	foreach ( $records as $record ) {
		if ( $url === $record['url'] ) {
			return $record;
		}
	}
	return null;
};

return array(

	'an image wrapped in <picture> is seen as delivered through the page' => static function () use ( $extract, $page, $record_for ) {
		$url     = home_url( '/wp-content/uploads/2026/11/a.png' );
		$records = $extract(
			$page(
				'<div><picture class="kwip-picture"><source type="image/webp" srcset="' . $url . '.webp 1024w">'
				. '<img src="' . $url . '" srcset="' . $url . ' 1024w" width="500" height="500" alt=""></picture></div>'
			)
		);
		if ( is_wp_error( $records ) ) {
			KT::skip( $records->get_error_message() );
		}
		$record = $record_for( $records, $url );
		if ( null === $record ) {
			KT::ok( false, 'the image was found on the page' );
			return;
		}
		KT::same( true, $record['picture_webp'], 'the WebP <source> next to the image is found' );
	},

	'an image without a <picture> is not seen as delivered' => static function () use ( $extract, $page, $record_for ) {
		$url     = home_url( '/wp-content/uploads/2026/11/b.png' );
		$records = $extract( $page( '<div><img src="' . $url . '" width="500" height="500" alt=""></div>' ) );
		if ( is_wp_error( $records ) ) {
			KT::skip( $records->get_error_message() );
		}
		$record = $record_for( $records, $url );
		if ( null === $record ) {
			KT::ok( false, 'the image was found on the page' );
			return;
		}
		KT::same( false, $record['picture_webp'], 'no <source>, so not delivered as WebP' );
	},

	'an image gets the place it sits in on the page' => static function () use ( $extract, $page, $record_for ) {
		$head = home_url( '/wp-content/uploads/2026/11/c.png' );
		$main = home_url( '/wp-content/uploads/2026/11/d.png' );
		$records = $extract(
			$page(
				'<header><img src="' . $head . '" width="300" height="100" alt=""></header>'
				. '<main><div id="hero"><img src="' . $main . '" width="300" height="100" alt=""></div></main>'
			)
		);
		if ( is_wp_error( $records ) ) {
			KT::skip( $records->get_error_message() );
		}
		$a = $record_for( $records, $head );
		$b = $record_for( $records, $main );
		KT::same( 'header', $a['place'] ?? null, 'an image in the header' );
		KT::same( 'main', $b['place'] ?? null, 'an image in the main content' );
		KT::same( '#hero', $b['hint'] ?? null, 'the nearest id is used as a hint' );
	},
);