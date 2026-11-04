<?php
/**
 * Runs when KWIP Image is deleted from the Plugins screen.
 *
 * Always removed: settings, the conversion records and queue entries stored with
 * attachments, scan history, cached values, the scheduled queue job and the
 * rules this plugin added to .htaccess.
 *
 * Removed only when "Also delete the WebP files when the plugin is deleted" is
 * switched on in Settings (off by default): the generated .webp files. Your
 * original images are never touched.
 *
 * This file must not depend on the plugin's own classes (the plugin is not
 * loaded when it runs), so stored names are written out here. If they are ever
 * renamed, change them here too.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function kwip_uninstall_remove_file( string $webp, string $uploads_base ): void {
	if ( 'webp' !== strtolower( pathinfo( $webp, PATHINFO_EXTENSION ) ) || ! is_file( $webp ) || is_link( $webp ) ) {
		return;
	}
	$real = realpath( $webp );
	if ( false === $real ) {
		return;
	}
	$real = wp_normalize_path( $real );
	if ( str_starts_with( $real, $uploads_base . '/' ) ) {
		wp_delete_file( $webp );
	}
}

function kwip_uninstall_delete_webp(): void {
	$uploads = wp_get_upload_dir();
	$base    = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
	if ( false === $base ) {
		return;
	}
	$base = untrailingslashit( wp_normalize_path( $base ) );
	$page = 1;

	do {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => '_kwip_data',
			)
		);

		foreach ( $ids as $id ) {
			$record = get_post_meta( (int) $id, '_kwip_data', true );
			$main   = get_attached_file( (int) $id );
			if ( ! $main || ! is_array( $record ) || empty( $record['files'] ) || ! is_array( $record['files'] ) ) {
				continue;
			}
			$dir = trailingslashit( dirname( $main ) );
			foreach ( array_keys( $record['files'] ) as $name ) {
				kwip_uninstall_remove_file( $dir . wp_basename( (string) $name ) . '.webp', $base );
			}
		}
		++$page;
	} while ( count( $ids ) === 200 );
}

function kwip_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'kwip_settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] ) ) {
		kwip_uninstall_delete_webp();
	}

	wp_clear_scheduled_hook( 'kwip_process_queue' );

	foreach ( array( '_kwip_data', '_kwip_queued', '_kwip_attempts' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	foreach ( array( 'kwip_settings', 'kwip_scan_history', 'kwip_queue_run_lock' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'kwip_savings', 'kwip_routes_2' ) as $transient ) {
		delete_transient( $transient );
	}

	$patterns = array(
		'kwip\_lock\_%',
		'\_transient\_kwip\_flash\_%',
		'\_transient\_timeout\_kwip\_flash\_%',
	);
	foreach ( $patterns as $pattern ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
	}
}

function kwip_uninstall_htaccess(): void {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	$file = get_home_path() . '.htaccess';
	if ( ! is_file( $file ) || ! wp_is_writable( $file ) ) {
		return;
	}
	foreach ( array( 'KWIP Image', 'Guanzon WebP Image' ) as $marker ) {
		if ( array() !== extract_from_markers( $file, $marker ) ) {
			insert_with_markers( $file, $marker, array() );
		}
	}
}

if ( is_multisite() ) {
	$offset = 0;
	do {
		$blog_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);
		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			kwip_uninstall_site();
			restore_current_blog();
		}
		$offset += 100;
	} while ( count( $blog_ids ) === 100 );
} else {
	kwip_uninstall_site();
}

kwip_uninstall_htaccess();