<?php
/**
 * Checks that need no WordPress data: syntax, naming, version and consistency.
 */

$find = static function ( string $dir, string $ext ): array {
	$found = array();
	if ( ! is_dir( $dir ) ) {
		return $found;
	}
	$base     = wp_normalize_path( $dir );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$path = wp_normalize_path( (string) $file );
		if ( str_ends_with( $path, '.' . $ext ) && ! preg_match( '#/(vendor|node_modules|\.git)/#', substr( $path, strlen( $base ) ) ) ) {
			$found[] = $path;
		}
	}
	sort( $found );
	return $found;
};

$code_files = static function (): array {
	$files = array_merge( glob( KWIP_PATH . '*.php' ) ?: array(), glob( KWIP_PATH . 'includes/*.php' ) ?: array() );
	return array_map( 'wp_normalize_path', $files );
};

return array(

	'every PHP file passes php -l' => static function () use ( $find ) {
		foreach ( $find( KWIP_PATH, 'php' ) as $file ) {
			$run = KT::run( array( PHP_BINARY, '-l', $file ) );
			if ( null === $run ) {
				KT::skip( 'Programs cannot be started from PHP on this machine.' );
			}
			KT::ok( 0 === $run['code'], 'Syntax error in ' . basename( $file ) . ': ' . $run['output'] );
		}
	},

	'every JavaScript file passes node --check' => static function () use ( $find ) {
		$probe = KT::run( array( 'node', '--version' ) );
		if ( null === $probe || 0 !== $probe['code'] ) {
			KT::skip( 'Node.js is not installed (optional).' );
		}
		foreach ( $find( KWIP_PATH . 'assets', 'js' ) as $file ) {
			$run = KT::run( array( 'node', '--check', $file ) );
			KT::ok( null !== $run && 0 === $run['code'], 'Syntax error in ' . basename( $file ) . ': ' . ( $run['output'] ?? '' ) );
		}
	},

	'plugin header and constants agree' => static function () {
		$source = (string) file_get_contents( KWIP_FILE );
		$header = static function ( string $name ) use ( $source ): ?string {
			return preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':[ \t]*([^\r\n]*)/mi', $source, $m ) ? $m[1] : null;
		};

		KT::same( KWIP_VERSION, trim( (string) $header( 'Version' ) ), 'Version header matches KWIP_VERSION' );
		KT::same( KWIP_MIN_PHP, trim( (string) $header( 'Requires PHP' ) ), 'Requires PHP header matches KWIP_MIN_PHP' );
		KT::same( KWIP_MIN_WP, trim( (string) $header( 'Requires at least' ) ), 'Requires at least header matches KWIP_MIN_WP' );
		KT::same( 'kwip-image-plugin', trim( (string) $header( 'Text Domain' ) ), 'Text Domain header' );

		$name = (string) $header( 'Plugin Name' );
		KT::ok( '' !== $name && trim( $name ) === $name, 'Plugin Name is set and has no stray spaces' );
	},

	'every translated string uses the plugin text domain' => static function () use ( $code_files ) {
		$pattern = '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*,\s*\'([^\']*)\'\s*\)/';
		foreach ( $code_files() as $file ) {
			preg_match_all( $pattern, (string) file_get_contents( $file ), $matches );
			foreach ( $matches[1] as $domain ) {
				KT::ok( 'kwip-image-plugin' === $domain, basename( $file ) . ' uses the text domain "' . $domain . '"' );
			}
		}
	},

	'no leftover names from the old plugin name' => static function () use ( $find ) {
		$files = array_merge(
			$find( KWIP_PATH . 'includes', 'php' ),
			$find( KWIP_PATH . 'assets', 'js' ),
			$find( KWIP_PATH . 'assets', 'css' ),
			array( wp_normalize_path( KWIP_FILE ), wp_normalize_path( KWIP_PATH . 'uninstall.php' ) )
		);
		foreach ( $files as $file ) {
			if ( 'class-kwip-upgrade.php' === basename( $file ) || ! is_file( $file ) ) {
				continue; // The upgrade class has to know the old names.
			}
			$source = (string) file_get_contents( $file );
			KT::ok( ! preg_match( '/gwip/i', $source ), basename( $file ) . ' still contains "gwip"' );
			KT::ok( ! str_contains( $source, 'KWIP-webp' ), basename( $file ) . ' links to the old page slug' );
		}
	},

	'every error code explained to the user can be produced' => static function () use ( $find ) {
		$known = array();
		foreach ( $find( KWIP_PATH . 'includes', 'php' ) as $file ) {
			if ( preg_match_all( '/new WP_Error\(\s*\'(kwip_[a-z_]+)\'/', (string) file_get_contents( $file ), $m ) ) {
				$known = array_merge( $known, $m[1] );
			}
		}

		preg_match_all( '/case \'(kwip_[a-z_]+)\'/', (string) file_get_contents( KWIP_PATH . 'includes/class-kwip-problems.php' ), $cases );
		KT::ok( count( $cases[1] ) > 0, 'found the error codes in class-kwip-problems.php' );
		foreach ( $cases[1] as $code ) {
			KT::ok( in_array( $code, $known, true ), "Problems explains '$code', but nothing in the plugin produces it" );
		}
	},

	'every include file refuses direct access' => static function () {
		foreach ( glob( KWIP_PATH . 'includes/*.php' ) ?: array() as $file ) {
			KT::ok( str_contains( substr( (string) file_get_contents( $file ), 0, 800 ), "defined( 'ABSPATH' )" ), basename( $file ) . ' has no ABSPATH guard near the top' );
		}
	},

	'uninstall removes every stored name' => static function () {
		$source = (string) file_get_contents( KWIP_PATH . 'uninstall.php' );
		$names  = array(
			KWIP_Converter::META_KEY,
			KWIP_Converter::META_STATUS,
			'_kwip_queued',
			'_kwip_attempts',
			KWIP_Settings::OPTION,
			'kwip_scan_history',
			'kwip_db_version',
			'kwip_savings',
			'kwip_routes_2',
			'kwip_problem_count',
			'kwip_optimized_count',
		);
		foreach ( $names as $name ) {
			KT::ok( str_contains( $source, "'" . $name . "'" ), "uninstall.php does not remove '$name'" );
		}
	},

		'every setting is read somewhere' => static function () {
		$source = '';
		foreach ( glob( KWIP_PATH . 'includes/*.php' ) ?: array() as $file ) {
			if ( 'class-kwip-settings.php' !== basename( $file ) ) {
				$source .= (string) file_get_contents( $file );
			}
		}
		$source .= (string) file_get_contents( KWIP_PATH . 'uninstall.php' );

		foreach ( array_keys( KWIP_Settings::defaults() ) as $key ) {
			KT::ok(
				str_contains( $source, "['" . $key . "']" ) || str_contains( $source, '["' . $key . '"]' ),
				"the setting '$key' is stored but never read"
			);
		}
	},

	'licence files agree with the plugin header' => static function () {
		$source = (string) file_get_contents( KWIP_FILE );
		KT::ok( (bool) preg_match( '/^[ \t\/*#@]*License:[ \t]*GPL-2\.0-or-later[ \t]*\r?$/mi', $source ), 'the License header is GPL-2.0-or-later' );
		KT::ok( (bool) preg_match( '/^[ \t\/*#@]*License URI:[ \t]*https?:\/\/\S+/mi', $source ), 'a License URI header exists' );

		$licence = KWIP_PATH . 'LICENSE';
		if ( is_file( $licence ) ) {
			$text = (string) file_get_contents( $licence );
			KT::ok( str_contains( $text, 'GNU GENERAL PUBLIC LICENSE' ) && str_contains( $text, 'Version 2, June 1991' ), 'LICENSE is the GPL version 2 text' );
		} else {
			KT::ok( false, 'the LICENSE file is missing' );
		}

		$readme = is_file( KWIP_PATH . 'README.md' ) ? (string) file_get_contents( KWIP_PATH . 'README.md' ) : '';
		KT::ok( '' !== $readme, 'README.md exists' );
		KT::ok( ! str_contains( $readme, 'No license has been specified' ), 'the README no longer says there is no license' );
		KT::ok( str_contains( $readme, 'GPL-2.0-or-later' ), 'the README names the license' );
	},

	'release archives leave out the tests' => static function () {
		$file = KWIP_PATH . '.gitattributes';
		if ( ! is_file( $file ) ) {
			KT::ok( false, '.gitattributes is missing' );
			return;
		}
		$text = (string) file_get_contents( $file );
		KT::ok( (bool) preg_match( '#^/tests\s+export-ignore#m', $text ), '/tests is marked export-ignore' );
		KT::ok( (bool) preg_match( '/^\*\s+text=auto/m', $text ), 'line endings are normalized' );
	},
);