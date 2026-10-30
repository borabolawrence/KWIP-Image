<?php
/**
 * Plugin settings: defaults, storage and validation.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Settings {

	public const OPTION = 'kwip_settings';
	public const GROUP  = 'kwip_settings_group';

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Default values. Confirmed defaults: quality 100, auto-convert on,
	 * backups on, WebP delivery with original fallback.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'quality'      => 100,
			'lossless'     => false,
			'formats'      => array( 'jpeg', 'png' ),
			'auto_convert' => true,
			'keep_backups' => true,
			'delivery'     => 'webp_fallback',
			'method'       => 'auto',
			'min_savings'  => 5,
			'allow_larger' => false,
		);
	}

	/**
	 * Image types the plugin can convert (animated GIF and SVG are never converted).
	 *
	 * @return array<string, string>
	 */
	public static function format_choices(): array {
		return array(
			'jpeg' => __( 'JPEG (.jpg, .jpeg)', 'kwip-image-plugin' ),
			'png'  => __( 'PNG (transparency is preserved)', 'kwip-image-plugin' ),
		);
	}
	
	/**
	 * Quality presets shown as cards. Only "Highest quality" (100) is the confirmed
	 * default; the other two values are starting points.
	 *
	 * @return array<string, array{quality:int,icon:string,label:string,desc:string}>
	 */
	public static function presets(): array {
		return array(
			'highest'  => array(
				'quality' => 100,
				'icon'    => 'visibility',
				'label'   => __( 'Highest quality', 'kwip-image-plugin' ),
				'desc'    => __( 'Looks as close to the original as possible. Files get smaller, but photos may not shrink much.', 'kwip-image-plugin' ),
			),
			'balanced' => array(
				'quality' => 85,
				'icon'    => 'image-filter',
				'label'   => __( 'Balanced', 'kwip-image-plugin' ),
				'desc'    => __( 'A good middle ground between picture quality and file size.', 'kwip-image-plugin' ),
			),
			'smallest' => array(
				'quality' => 70,
				'icon'    => 'performance',
				'label'   => __( 'Smallest files', 'kwip-image-plugin' ),
				'desc'    => __( 'The lightest files and fastest pages. Fine detail in photos may look slightly softer.', 'kwip-image-plugin' ),
			),
		);
	}

	/** Key of the preset whose quality matches, or null for a custom value. */
	public static function preset_for_quality( int $quality ): ?string {
		foreach ( self::presets() as $key => $preset ) {
			if ( $preset['quality'] === $quality ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Saved settings merged over defaults. Safe to call on the front end.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Validate submitted values. Unchecked checkboxes are absent from the
	 * submission, so a missing key means "off".
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		// WordPress may call this twice on the very first save; announce once.
		static $announced = false;
		if ( ! $announced ) {
			$announced = true;
			add_settings_error( self::OPTION, 'kwip_saved', __( 'Settings saved.', 'kwip-image-plugin' ), 'success' );
		}

		$clean = array();

		$quality = isset( $input['quality'] ) ? (int) $input['quality'] : $defaults['quality'];
		if ( $quality < 1 || $quality > 100 ) {
			$quality = max( 1, min( 100, $quality ) );
			add_settings_error(
				self::OPTION,
				'kwip_quality',
				sprintf( __( 'Quality must be between 1 and 100. It was set to %d.', 'kwip-image-plugin' ), $quality ),
				'warning'
			);
		}
		$clean['quality'] = $quality;

		$report            = KWIP_Environment::get_report();
		$clean['lossless'] = ! empty( $input['lossless'] ) && $report['gd_lossless'];

		$formats = array();
		if ( isset( $input['formats'] ) && is_array( $input['formats'] ) ) {
			$submitted = array_map( 'sanitize_key', $input['formats'] );
			$formats   = array_values( array_intersect( array_keys( self::format_choices() ), $submitted ) );
		}
		if ( empty( $formats ) ) {
			$formats = $defaults['formats'];
			add_settings_error(
				self::OPTION,
				'kwip_formats',
				__( 'Select at least one image type. The defaults were restored.', 'kwip-image-plugin' ),
				'warning'
			);
		}
		$clean['formats'] = $formats;

		$clean['auto_convert'] = ! empty( $input['auto_convert'] );
		$clean['keep_backups'] = ! empty( $input['keep_backups'] );

		$delivery          = isset( $input['delivery'] ) ? sanitize_key( $input['delivery'] ) : $defaults['delivery'];
		$clean['delivery'] = in_array( $delivery, array( 'webp_fallback', 'off' ), true ) ? $delivery : $defaults['delivery'];
		$method          = isset( $input['method'] ) ? sanitize_key( $input['method'] ) : $defaults['method'];
		$clean['method'] = in_array( $method, array( 'auto', 'server', 'page' ), true ) ? $method : $defaults['method'];

		$min_savings          = isset( $input['min_savings'] ) ? (int) $input['min_savings'] : $defaults['min_savings'];
		$clean['min_savings'] = max( 0, min( 90, $min_savings ) );

		$clean['allow_larger'] = ! empty( $input['allow_larger'] );

		return $clean;
	}
}
