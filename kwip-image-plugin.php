<?php

/**
 * Plugin Name:       KWIP Image 
 * Description:       Converts supported images to WebP to reduce image size and improve loading performance, keeping originals and an original-format fallback.
 * Version:           0.2.4
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            kiyouh.
 * License:           GPL-2.0-or-later
 * Text Domain:       webp-image-plugin
 */

if (! defined('ABSPATH')) {
	exit;
}

define('KWIP_VERSION', '0.2.4');
define('KWIP_FILE', __FILE__);
define('KWIP_PATH', plugin_dir_path(__FILE__));
define('KWIP_URL', plugin_dir_url(__FILE__));
define('KWIP_MIN_PHP', '8.2');
define('KWIP_MIN_WP', '6.0');

/*
 * Guard: this file must stay parseable on old PHP versions so the notice can
 * be shown instead of a fatal error. All PHP 8.2 code lives in /includes.
 */
if (version_compare(PHP_VERSION, KWIP_MIN_PHP, '<')) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						__('KWIP Image requires PHP %1$s or later. Your server is running PHP %2$s. The plugin is inactive.', 'kwip-image-plugin'),
						KWIP_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

require_once KWIP_PATH . 'includes/class-kwip-environment.php';
require_once KWIP_PATH . 'includes/class-kwip-settings.php';
require_once KWIP_PATH . 'includes/class-kwip-files.php';
require_once KWIP_PATH . 'includes/class-kwip-converter.php';
require_once KWIP_PATH . 'includes/class-kwip-stats.php';
require_once KWIP_PATH . 'includes/class-kwip-queue.php';
require_once KWIP_PATH . 'includes/class-kwip-bulk.php';
require_once KWIP_PATH . 'includes/class-kwip-scan.php';
require_once KWIP_PATH . 'includes/class-kwip-media.php';
require_once KWIP_PATH . 'includes/class-kwip-admin.php';
require_once KWIP_PATH . 'includes/class-kwip-delivery.php';

add_action(
	'plugins_loaded',
	static function () {
		KWIP_Settings::init();
		KWIP_Converter::init();
		KWIP_Queue::init();
		KWIP_Delivery::init();
		if (is_admin()) {
			KWIP_Admin::init();
			KWIP_Media::init();
			KWIP_Bulk::init();
			KWIP_Scan::init();
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		KWIP_Delivery::apply();
		if ( KWIP_Queue::pending_count() > 0 ) {
			KWIP_Queue::schedule( 10 );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		KWIP_Delivery::remove_rules();
		wp_clear_scheduled_hook( KWIP_Queue::HOOK );
	}
);