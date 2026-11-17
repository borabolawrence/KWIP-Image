<?php
/**
 * KWIP Image test runner.
 *
 * Runs inside your real WordPress install (it loads wp-load.php), so it tests
 * the plugin against real WordPress, GD and the database. Use it on a local
 * or development site only.
 *
 *   php wp-content/plugins/KWIP-Image/tests/run.php --yes
 *
 * Options:
 *   --yes            Required. Confirms that this is a development site.
 *   --filter=name    Run only the groups whose file name contains "name".
 *   --verbose, -v    List every test, not only the failures.
 *   --allow-remote   Allow a site address that does not look local.
 *   --wp=path        Folder that contains wp-load.php (found automatically).
 *
 * @package kwip-image-plugin
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Run this from the command line: php tests/run.php --yes\n" );
}

$opts = array(
	'yes'     => false,
	'verbose' => false,
	'remote'  => false,
	'filter'  => '',
	'wp'      => '',
);
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--yes' === $arg ) {
		$opts['yes'] = true;
	} elseif ( '--verbose' === $arg || '-v' === $arg ) {
		$opts['verbose'] = true;
	} elseif ( '--allow-remote' === $arg ) {
		$opts['remote'] = true;
	} elseif ( str_starts_with( $arg, '--filter=' ) ) {
		$opts['filter'] = substr( $arg, 9 );
	} elseif ( str_starts_with( $arg, '--wp=' ) ) {
		$opts['wp'] = substr( $arg, 5 );
	}
}

$wp_load = '' !== $opts['wp'] ? rtrim( $opts['wp'], '/\\' ) . '/wp-load.php' : '';
if ( '' === $wp_load ) {
	$dir = __DIR__;
	for ( $i = 0; $i < 8; $i++ ) {
		$dir = dirname( $dir );
		if ( is_file( $dir . '/wp-load.php' ) ) {
			$wp_load = $dir . '/wp-load.php';
			break;
		}
	}
}
if ( '' === $wp_load || ! is_file( $wp_load ) ) {
	exit( "Could not find wp-load.php. Pass the WordPress folder with --wp=path\n" );
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true ); // Never let the tests trigger the real queue.
}
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';

require $wp_load;

if ( ! class_exists( 'KWIP_Files' ) ) {
	exit( "KWIP Image is not active on this site. Activate the plugin first.\n" );
}

$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$local = 'localhost' === $host
	|| (bool) preg_match( '/\.(test|local|localhost)$/', $host )
	|| (bool) preg_match( '/^(127\.|10\.|192\.168\.|\[?::1\]?$)/', $host );

if ( ! $opts['yes'] ) {
	echo "This suite creates and deletes temporary images (kwip-test-*) in the uploads folder\n";
	echo "and temporarily changes KWIP settings and the kwip_db_version option on:\n  " . home_url() . "\n";
	echo "Everything is restored afterwards. Run it on a development site only.\n";
	echo "Add --yes to continue.\n";
	exit( 1 );
}
if ( ! $local && ! $opts['remote'] ) {
	echo "'" . $host . "' does not look like a local site. Add --allow-remote to run anyway.\n";
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once __DIR__ . '/helpers.php';

// Keep tests from rewriting .htaccess and from queueing every generated image.
remove_action( 'update_option_' . KWIP_Settings::OPTION, array( 'KWIP_Delivery', 'on_settings_saved' ) );
remove_action( 'add_option_' . KWIP_Settings::OPTION, array( 'KWIP_Delivery', 'on_settings_saved' ) );
remove_filter( 'wp_generate_attachment_metadata', array( 'KWIP_Queue', 'on_generate' ), 99 );

// Report notices and deprecations raised by the plugin (or by the tests themselves).
error_reporting( E_ALL );
set_error_handler(
	static function ( int $number, string $message, string $file, int $line ): bool {
		if ( ! ( error_reporting() & $number ) ) {
			return false;
		}
		if ( str_starts_with( wp_normalize_path( $file ), wp_normalize_path( KWIP_PATH ) ) ) {
			KT::$notices[] = sprintf( '%s (%s:%d)', $message, basename( $file ), $line );
		}
		return true;
	}
);

KT::$verbose = $opts['verbose'];
register_shutdown_function( array( 'KT', 'finish' ) );
KT::boot();

echo 'KWIP Image tests on ' . home_url() . ' (PHP ' . PHP_VERSION . ', WordPress ' . get_bloginfo( 'version' ) . ")\n";
$started = microtime( true );

foreach ( glob( __DIR__ . '/cases/*-test.php' ) ?: array() as $file ) {
	$group = basename( $file, '-test.php' );
	if ( '' !== $opts['filter'] && false === stripos( $group, $opts['filter'] ) ) {
		continue;
	}

	echo "\n[" . $group . "]\n";
	$tests = require $file;

	foreach ( $tests as $name => $callback ) {
		++KT::$tests;
		KT::$current     = $group . ' / ' . $name;
		KT::$test_failed = false;
		KT::$notices     = array();
		$skip_reason     = null;

		try {
			$callback();
		} catch ( KWIP_Test_Skip $skip ) {
			$skip_reason = $skip->getMessage();
		} catch ( Throwable $error ) {
			KT::fail( 'Uncaught ' . get_class( $error ) . ': ' . $error->getMessage() . ' (' . basename( $error->getFile() ) . ':' . $error->getLine() . ')' );
		}
		foreach ( KT::$notices as $notice ) {
			KT::fail( 'PHP notice: ' . $notice );
		}
		KT::restore();
		KT::cleanup();

		if ( KT::$test_failed ) {
			++KT::$failed;
			echo '  FAIL  ' . $name . "\n";
		} elseif ( null !== $skip_reason ) {
			++KT::$skipped;
			echo '  skip  ' . $name . ' - ' . $skip_reason . "\n";
		} else {
			++KT::$passed;
			if ( KT::$verbose ) {
				echo '  ok    ' . $name . "\n";
			}
		}
	}
}

KT::finish();

echo "\n";
if ( KT::$failures ) {
	echo "Failures:\n";
	foreach ( KT::$failures as $failure ) {
		echo ' - ' . $failure . "\n";
	}
	echo "\n";
}
printf(
	"%d tests: %d passed, %d failed, %d skipped (%d checks, %.1fs)\n",
	KT::$tests,
	KT::$passed,
	KT::$failed,
	KT::$skipped,
	KT::$assertions,
	microtime( true ) - $started
);
exit( KT::$failed > 0 ? 1 : 0 );