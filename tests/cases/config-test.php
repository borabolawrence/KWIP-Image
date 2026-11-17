<?php
/**
 * Settings, environment report and delivery configuration.
 */

return array(

	'defaults match the confirmed project defaults' => static function () {
		$d = KWIP_Settings::defaults();
		KT::same( 100, $d['quality'], 'quality' );
		KT::same( true, $d['auto_convert'], 'auto convert' );
		KT::same( 'webp_fallback', $d['delivery'], 'delivery' );
		KT::same( 'auto', $d['method'], 'method' );
		KT::same( array( 'jpeg', 'png' ), $d['formats'], 'formats' );
		KT::same( 5, $d['min_savings'], 'minimum saving' );
		KT::same( false, $d['allow_larger'], 'larger files not used by default' );
	},

	'sanitize clamps and whitelists what is submitted' => static function () {
		$s = KWIP_Settings::sanitize(
			array(
				'quality'     => '0',
				'formats'     => array( 'gif' ),
				'method'      => 'bogus',
				'delivery'    => 'x',
				'min_savings' => '999',
			)
		);
		KT::same( 1, $s['quality'], 'quality floor' );
		KT::same( array( 'jpeg', 'png' ), $s['formats'], 'unknown formats restore the defaults' );
		KT::same( 'auto', $s['method'], 'unknown method' );
		KT::same( 'webp_fallback', $s['delivery'], 'unknown delivery' );
		KT::same( 90, $s['min_savings'], 'minimum saving ceiling' );

		KT::same( 100, KWIP_Settings::sanitize( array( 'quality' => '500' ) )['quality'], 'quality ceiling' );

		$s = KWIP_Settings::sanitize(
			array(
				'quality'     => '85',
				'formats'     => array( 'png' ),
				'method'      => 'page',
				'delivery'    => 'off',
				'min_savings' => '-4',
			)
		);
		KT::same( 85, $s['quality'], 'valid quality kept' );
		KT::same( array( 'png' ), $s['formats'], 'valid formats kept' );
		KT::same( 'page', $s['method'], 'valid method kept' );
		KT::same( 'off', $s['delivery'], 'valid delivery kept' );
		KT::same( 0, $s['min_savings'], 'minimum saving floor' );

		$s = KWIP_Settings::sanitize( array() );
		KT::same( false, $s['auto_convert'], 'an unchecked box means off' );
		KT::same( false, $s['allow_larger'], 'an unchecked override means off' );
		KT::same( false, $s['delete_on_uninstall'], 'an unchecked cleanup box means off' );

		KT::same( 100, KWIP_Settings::sanitize( 'junk' )['quality'], 'non-array input falls back to the default' );
	},

	'get ignores unknown keys and fills missing ones' => static function () {
		update_option(
			KWIP_Settings::OPTION,
			array(
				'quality' => 42,
				'evil'    => 'x',
			)
		);
		$s = KWIP_Settings::get();
		KT::same( 42, $s['quality'], 'saved value used' );
		KT::ok( ! array_key_exists( 'evil', $s ), 'unknown key dropped' );
		KT::same( array( 'jpeg', 'png' ), $s['formats'], 'missing key filled from defaults' );

		update_option( KWIP_Settings::OPTION, 'junk' );
		KT::same( 100, KWIP_Settings::get()['quality'], 'a damaged option falls back to the defaults' );
	},

	'preset_for_quality finds the matching preset' => static function () {
		KT::same( 'highest', KWIP_Settings::preset_for_quality( 100 ), '100' );
		KT::same( 'balanced', KWIP_Settings::preset_for_quality( 85 ), '85' );
		KT::same( 'smallest', KWIP_Settings::preset_for_quality( 70 ), '70' );
		KT::same( null, KWIP_Settings::preset_for_quality( 90 ), 'a custom value' );
	},

	'environment report is consistent' => static function () {
		$r = KWIP_Environment::get_report();
		foreach ( array( 'php_version', 'php_ok', 'wp_ok', 'wp_webp', 'uploads_ok', 'ready', 'gd_webp', 'imagick_webp' ) as $key ) {
			KT::ok( array_key_exists( $key, $r ), "the report has '$key'" );
		}
		KT::same( true, $r['php_ok'], 'PHP meets the minimum version' );
		KT::same( $r['php_ok'] && $r['wp_ok'] && $r['wp_webp'] && $r['uploads_ok'], $r['ready'], "'ready' is the combination of its parts" );
	},

	'active_method follows the settings' => static function () {
		$cases = array(
			array( 'off', 'auto', 'none' ),
			array( 'webp_fallback', 'page', 'page' ),
			array( 'webp_fallback', 'server', 'server' ),
			array( 'webp_fallback', 'auto', KWIP_Platform::recommended_method() ),
		);
		foreach ( $cases as $case ) {
			KT::settings(
				array(
					'delivery' => $case[0],
					'method'   => $case[1],
				)
			);
			KT::same( $case[2], KWIP_Delivery::active_method(), $case[0] . ' / ' . $case[1] );
			KT::ok( '' !== KWIP_Delivery::method_label(), 'a label exists for ' . $case[2] );
		}
	},

	'delivery state matches the chosen method' => static function () {
		KT::settings(
			array(
				'delivery' => 'webp_fallback',
				'method'   => 'page',
			)
		);
		KT::same( 'page', KWIP_Delivery::state()['key'], 'page method' );
		KT::same( true, KWIP_Delivery::is_delivering(), 'the page method delivers' );

		KT::settings( array( 'delivery' => 'off' ) );
		KT::ok( in_array( KWIP_Delivery::state()['key'], array( 'off', 'stale' ), true ), 'turned off' );
		KT::same( false, KWIP_Delivery::is_delivering(), 'nothing is delivered when off' );

		KT::settings(
			array(
				'delivery' => 'webp_fallback',
				'method'   => 'server',
			)
		);
		KT::ok( in_array( KWIP_Delivery::state()['key'], array( 'active', 'missing', 'manual' ), true ), 'server method' );
	},

	'server rules are well formed' => static function () {
		$rules = implode( "\n", KWIP_Delivery::rules() );
		KT::has( 'RewriteCond %{HTTP_ACCEPT} image/webp', $rules, 'only browsers that accept WebP' );
		KT::has( 'RewriteCond %{REQUEST_FILENAME}.webp -f', $rules, 'only when the WebP exists' );
		KT::has( 'Header append Vary Accept', $rules, 'caches are told the answer varies' );
		KT::same( substr_count( $rules, '<IfModule' ), substr_count( $rules, '</IfModule>' ), 'every IfModule is closed' );

		KT::ok( (bool) preg_match( '/^RewriteRule (\S+) /m', $rules, $m ), 'a RewriteRule exists' );
		$pattern = '#' . ( $m[1] ?? '$^' ) . '#i';
		KT::ok( (bool) preg_match( $pattern, 'wp-content/uploads/a.JPG' ), 'matches a JPG' );
		KT::ok( (bool) preg_match( $pattern, 'x.png' ), 'matches a PNG' );
		KT::ok( ! preg_match( $pattern, 'x.jpg.webp' ), 'does not match a WebP (no loop)' );
		KT::ok( ! preg_match( $pattern, 'x.gif' ), 'does not match a GIF' );
		KT::ok( ! preg_match( $pattern, 'x.svg' ), 'does not match an SVG' );

		$nginx = KWIP_Delivery::nginx_rules();
		KT::has( 'try_files', $nginx, 'nginx rules use try_files' );
		KT::has( 'Vary Accept', $nginx, 'nginx rules set Vary' );
	},

	'CDN detection reads response headers' => static function () {
		KT::same( 'Cloudflare', KWIP_Platform::detect_cdn( array( 'headers' => array( 'cf-ray' => 'abc' ) ) ), 'cf-ray header' );
		KT::same( 'Cloudflare', KWIP_Platform::detect_cdn( array( 'headers' => array( 'server' => 'cloudflare' ) ) ), 'server header' );
		KT::same( 'Amazon CloudFront', KWIP_Platform::detect_cdn( array( 'headers' => array( 'x-amz-cf-id' => 'x' ) ) ), 'CloudFront' );
		KT::same( 'BunnyCDN', KWIP_Platform::detect_cdn( array( 'headers' => array( 'server' => 'BunnyCDN-1' ) ) ), 'BunnyCDN' );
		KT::same( null, KWIP_Platform::detect_cdn( array( 'headers' => array( 'server' => 'Apache' ) ) ), 'no CDN' );
	},

	'flash messages are shown once' => static function () {
		if ( ! get_current_user_id() ) {
			KT::skip( 'No administrator account found.' );
		}
		$message = array(
			'type'    => 'info',
			'message' => 'hello',
		);
		KWIP_Delivery::flash( $message );
		KT::same( $message, KWIP_Delivery::take_flash(), 'the message comes back' );
		KT::same( null, KWIP_Delivery::take_flash(), 'and only once' );
	},
		'settings left over from earlier versions are ignored' => static function () {
		update_option(
			KWIP_Settings::OPTION,
			array(
				'quality'      => 60,
				'keep_backups' => true,
				'lossless'     => true,
			)
		);
		$s = KWIP_Settings::get();
		KT::same( 60, $s['quality'], 'the saved value is kept' );
		KT::ok( ! array_key_exists( 'keep_backups', $s ) && ! array_key_exists( 'lossless', $s ), 'removed settings are not returned' );

		$clean = KWIP_Settings::sanitize(
			array(
				'quality'      => '60',
				'keep_backups' => '1',
				'lossless'     => '1',
			)
		);
		KT::ok( ! array_key_exists( 'keep_backups', $clean ) && ! array_key_exists( 'lossless', $clean ), 'saving drops them from storage' );
	},
);