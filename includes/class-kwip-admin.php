<?php
/**
 * Admin menu and screens: Overview, Optimize images, Settings, Health check.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Admin {

	private const PAGE = 'KWIP Image Optimizer';

	private static string $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_gwip_process_now', array( __CLASS__, 'handle_process_now' ) );
		add_action( 'admin_post_gwip_delivery_apply', array( __CLASS__, 'handle_delivery_apply' ) );
		add_action( 'admin_post_gwip_delivery_test', array( __CLASS__, 'handle_delivery_test' ) );
	}

	public static function register_menu(): void {
		self::$hook = (string) add_menu_page(
			__( 'KWIP Image', 'kwip-image-plugin' ),
			__( 'KWIP Image', 'kwip-image-plugin' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-images-alt2',
			81
		);
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' === self::$hook || self::$hook !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'kwip-admin', KWIP_URL . 'assets/css/admin.css', array(), KWIP_VERSION );
		wp_enqueue_script( 'kwip-admin', KWIP_URL . 'assets/js/admin.js', array(), KWIP_VERSION, true );
		KWIP_Bulk::enqueue();
		KWIP_Scan::enqueue();
	}

	public static function register_shared_assets(): void {
		if ( wp_script_is( 'kwip-modal', 'registered' ) ) {
			return;
		}
		wp_register_style( 'kwip-modal', KWIP_URL . 'assets/css/modal.css', array(), KWIP_VERSION );
		wp_register_script( 'kwip-modal', KWIP_URL . 'assets/js/modal.js', array(), KWIP_VERSION, true );
		wp_localize_script(
			'kwip-modal',
			'gwipDialog',
			array(
				'i18n' => array(
					'cancel'  => __( 'Cancel', 'kwip-image-plugin' ),
					'confirm' => __( 'Confirm', 'kwip-image-plugin' ),
				),
			)
		);
	}

	/** @return array<string, string> */
	private static function tabs(): array {
		return array(
			'overview' => __( 'Overview', 'kwip-image-plugin' ),
			'bulk'     => __( 'Optimize images', 'kwip-image-plugin' ),
			'settings' => __( 'Settings', 'kwip-image-plugin' ),
			'status'   => __( 'Health check', 'kwip-image-plugin' ),
		);
	}

	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'overview';
	}

	private static function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwip-image-plugin' ) );
		}

		$current = self::current_tab();
		$report  = KWIP_Environment::get_report();
		$queued  = KWIP_Bulk::counts()['queued'];
		$icons   = array(
			'overview' => 'dashboard',
			'bulk'     => 'images-alt2',
			'settings' => 'admin-generic',
			'status'   => 'heart',
		);
		?>
		<div class="wrap kwip-wrap">
			<h1><?php esc_html_e( 'KWIP Image', 'kwip-image-plugin' ); ?></h1>
			<p class="kwip-tagline"><?php esc_html_e( 'Smaller images. A faster website.', 'kwip-image-plugin' ); ?></p>

			<div class="kwip-tabs" role="tablist" aria-label="<?php esc_attr_e( 'KWIP Image sections', 'kwip-image-plugin' ); ?>">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<?php $is_active = $slug === $current; ?>
					<a role="tab"
						id="kwip-tab-<?php echo esc_attr( $slug ); ?>"
						class="kwip-tab<?php echo $is_active ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"
						data-tab="<?php echo esc_attr( $slug ); ?>"
						aria-controls="kwip-panel-<?php echo esc_attr( $slug ); ?>"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
						<?php echo $is_active ? '' : 'tabindex="-1"'; ?>>
						<span class="dashicons dashicons-<?php echo esc_attr( $icons[ $slug ] ); ?>" aria-hidden="true"></span>
						<span><?php echo esc_html( $label ); ?></span>
						<?php if ( 'bulk' === $slug ) : ?>
							<span class="kwip-tab__badge" id="kwip-tab-badge-bulk"<?php echo $queued > 0 ? '' : ' hidden'; ?>><?php echo esc_html( number_format_i18n( $queued ) ); ?></span>
						<?php elseif ( 'status' === $slug && ! $report['ready'] ) : ?>
							<span class="kwip-tab__dot" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Needs attention', 'kwip-image-plugin' ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
				<span class="kwip-tabs__indicator" aria-hidden="true"></span>
			</div>

			<?php settings_errors(); ?>

			<?php foreach ( array_keys( self::tabs() ) as $slug ) : ?>
				<div role="tabpanel"
					id="kwip-panel-<?php echo esc_attr( $slug ); ?>"
					class="kwip-panel"
					aria-labelledby="kwip-tab-<?php echo esc_attr( $slug ); ?>"
					<?php echo $slug === $current ? '' : 'hidden'; ?>>
					<?php
					match ( $slug ) {
						'overview' => self::render_overview( $report ),
						'bulk'     => KWIP_Bulk::render( $report ),
						'status'   => self::render_status( $report ),
						default    => self::render_settings( $report ),
					};
					?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $r Environment report.
	 */
	private static function render_overview( array $r ): void {
		$counts   = KWIP_Bulk::counts();
		$savings  = KWIP_Stats::savings();
		$settings = KWIP_Settings::get();
		$delivery = KWIP_Delivery::state()['key'];

		$total     = $counts['total'];
		$left      = $counts['unprocessed'];
		$queued    = $counts['queued'];
		$processed = max( 0, $total - $left );
		$percent   = $total > 0 ? (int) round( ( $processed / $total ) * 100 ) : 0;
		$saved     = max( 0, $savings['original'] - $savings['webp'] );
		$saved_pct = $savings['original'] > 0 ? round( ( $saved / $savings['original'] ) * 100, 1 ) : 0;
		$as_is     = max( 0, $processed - $savings['optimized'] );
		$offset    = round( 326.73 * ( 1 - $percent / 100 ), 2 );

		$action_tab   = '';
		$action_label = '';

		if ( ! $r['ready'] ) {
			$tone         = 'warn';
			$title        = __( 'Your server cannot make optimized images yet', 'kwip-image-plugin' );
			$text         = __( 'This usually means a server feature is switched off. Open the Health check to see what is needed, or ask your hosting provider to enable WebP support for PHP.', 'kwip-image-plugin' );
			$action_tab   = 'status';
			$action_label = __( 'Open Health check', 'kwip-image-plugin' );
		} elseif ( 0 === $total ) {
			$tone  = 'start';
			$title = __( 'No images to optimize yet', 'kwip-image-plugin' );
			$text  = __( 'Upload JPG or PNG images to your Media Library and they will be optimized automatically.', 'kwip-image-plugin' );
		} elseif ( $queued > 0 ) {
			$tone         = 'busy';
			$title        = __( 'Optimizing your images…', 'kwip-image-plugin' );
			$text         = sprintf(
				_n( '%d image is waiting. This happens in the background, so you can keep working.', '%d images are waiting. This happens in the background, so you can keep working.', $queued, 'kwip-image-plugin' ),
				$queued
			);
			$action_tab   = 'bulk';
			$action_label = __( 'See progress', 'kwip-image-plugin' );
		} elseif ( $left > 0 ) {
			$tone         = 'start';
			$title        = __( 'Ready to speed up your website', 'kwip-image-plugin' );
			$text         = sprintf(
				_n( '%d image has not been optimized yet. It takes one click.', '%d images have not been optimized yet. It takes one click.', $left, 'kwip-image-plugin' ),
				$left
			);
			$action_tab   = 'bulk';
			$action_label = __( 'Optimize my images', 'kwip-image-plugin' );
		} elseif ( ! KWIP_Delivery::is_delivering() ) {
			$tone  = 'warn';
			$title = __( 'Your images are optimized, but visitors are not seeing them yet', 'kwip-image-plugin' );
			if ( in_array( $delivery, array( 'missing', 'manual' ), true ) ) {
				$text         = __( 'The server setup that delivers optimized images is not active. The Health check can fix this.', 'kwip-image-plugin' );
				$action_tab   = 'status';
				$action_label = __( 'Open Health check', 'kwip-image-plugin' );
			} else {
				$text         = __( 'Optimized images are only shown to visitors when "Show optimized images to visitors" is turned on in Settings.', 'kwip-image-plugin' );
				$action_tab   = 'settings';
				$action_label = __( 'Open Settings', 'kwip-image-plugin' );
			}
		} else {
			$tone  = 'success';
			$title = __( 'All done. Your images are optimized', 'kwip-image-plugin' );
			if ( ! empty( $settings['auto_convert'] ) ) {
				$text = __( 'Visitors now get lighter images automatically, and new uploads are optimized in the background.', 'kwip-image-plugin' );
			} else {
				$text         = __( 'Visitors now get lighter images automatically. New uploads are not optimized automatically; you can turn that on in Settings.', 'kwip-image-plugin' );
				$action_tab   = 'settings';
				$action_label = __( 'Open Settings', 'kwip-image-plugin' );
			}
		}

		list( $saved_value, $saved_decimals, $saved_unit ) = KWIP_Stats::bytes_parts( $saved );

		$steps = array(
			array( 'upload', __( 'You upload images as usual', 'kwip-image-plugin' ), __( 'Nothing changes in how you add photos and graphics to your website.', 'kwip-image-plugin' ) ),
			array( 'image-filter', __( 'We make lighter copies', 'kwip-image-plugin' ), __( 'Each JPG or PNG gets a smaller copy in a modern format called WebP, made to look very similar.', 'kwip-image-plugin' ) ),
			array( 'performance', __( 'Visitors get the lighter copy', 'kwip-image-plugin' ), __( 'Pages load faster. Browsers that cannot use WebP still receive your original image.', 'kwip-image-plugin' ) ),
		);

		$faq = array(
			array( __( 'What is WebP?', 'kwip-image-plugin' ), __( 'WebP is a modern image format that makes pictures much smaller. Current versions of all major browsers can show it.', 'kwip-image-plugin' ) ),
			array( __( 'Will my images look worse?', 'kwip-image-plugin' ), __( 'The optimized copies are made at the quality you choose in Settings, so they should look very similar to the originals. If you are not happy with an image, click Restore on it in the Media Library.', 'kwip-image-plugin' ) ),
			array( __( 'Are my original images safe?', 'kwip-image-plugin' ), __( 'Yes. The plugin never changes or deletes your original files. The optimized copies are saved next to them.', 'kwip-image-plugin' ) ),
			array( __( 'How do I undo it?', 'kwip-image-plugin' ), __( 'For one image: open Media, Library, and click Restore in its WebP column. For the whole site: turn off "Show optimized images to visitors" in Settings.', 'kwip-image-plugin' ) ),
		);
		?>
		<section class="kwip-hero kwip-hero--<?php echo esc_attr( $tone ); ?>">
			<div class="kwip-ring" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent */ __( '%d%% of your images have been checked', 'kwip-image-plugin' ), $percent ) ); ?>">
				<svg viewBox="0 0 120 120" aria-hidden="true">
					<circle class="kwip-ring__track" cx="60" cy="60" r="52"></circle>
					<circle class="kwip-ring__bar" cx="60" cy="60" r="52" style="--kwip-offset:<?php echo esc_attr( (string) $offset ); ?>"></circle>
				</svg>
				<div class="kwip-ring__label">
					<span class="kwip-ring__num" data-countup="<?php echo esc_attr( (string) $percent ); ?>"><?php echo esc_html( (string) $percent ); ?></span><span class="kwip-ring__unit">%</span>
				</div>
			</div>
			<div class="kwip-hero__body">
				<h2 class="kwip-hero__title"><?php echo esc_html( $title ); ?></h2>
				<p class="kwip-hero__text"><?php echo esc_html( $text ); ?></p>
				<?php if ( '' !== $action_tab ) : ?>
					<a class="button button-primary button-hero" href="<?php echo esc_url( self::tab_url( $action_tab ) ); ?>" data-kwip-goto="<?php echo esc_attr( $action_tab ); ?>"><?php echo esc_html( $action_label ); ?></a>
				<?php endif; ?>
				<?php if ( $total > 0 ) : ?>
					<p class="kwip-hero__meta">
						<?php
						echo esc_html(
							sprintf(
								__( '%1$s of %2$s images checked', 'kwip-image-plugin' ),
								number_format_i18n( $processed ),
								number_format_i18n( $total )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</section>

		<div class="kwip-tiles">
			<div class="kwip-tile">
				<span class="kwip-tile__label"><?php esc_html_e( 'Space saved', 'kwip-image-plugin' ); ?></span>
				<span class="kwip-tile__value"
					data-countup="<?php echo esc_attr( (string) $saved_value ); ?>"
					data-decimals="<?php echo esc_attr( (string) $saved_decimals ); ?>"
					data-suffix="<?php echo esc_attr( ' ' . $saved_unit ); ?>"><?php echo esc_html( number_format_i18n( $saved_value, $saved_decimals ) . ' ' . $saved_unit ); ?></span>
				<span class="kwip-tile__sub">
					<?php
					echo $saved > 0
						? esc_html(
							sprintf(
								__( '%s%% smaller than the originals', 'kwip-image-plugin' ),
								number_format_i18n( (float) $saved_pct, 1 )
							)
						)
						: esc_html__( 'Nothing saved yet', 'kwip-image-plugin' );
					?>
				</span>
			</div>

			<div class="kwip-tile">
				<span class="kwip-tile__label"><?php esc_html_e( 'Images optimized', 'kwip-image-plugin' ); ?></span>
				<span class="kwip-tile__value" data-countup="<?php echo esc_attr( (string) $savings['optimized'] ); ?>"><?php echo esc_html( number_format_i18n( $savings['optimized'] ) ); ?></span>
				<span class="kwip-tile__sub">
					<?php
					echo esc_html(
						sprintf(
							__( 'out of %s in your library', 'kwip-image-plugin' ),
							number_format_i18n( $total )
						)
					);
					if ( $as_is > 0 ) {
						echo ' ' . esc_html(
							sprintf(
								_n( '(%s was already as small as possible)', '(%s were already as small as possible)', $as_is, 'kwip-image-plugin' ),
								number_format_i18n( $as_is )
							)
						);
					}
					?>
				</span>
			</div>

			<div class="kwip-tile">
				<span class="kwip-tile__label"><?php esc_html_e( 'Visitors see optimized images', 'kwip-image-plugin' ); ?></span>
				<?php if ( KWIP_Delivery::is_delivering() ) : ?>
					<span class="kwip-chip kwip-chip--ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'On', 'kwip-image-plugin' ); ?></span>
					<span class="gwip-tile__sub"><?php echo esc_html( 'page' === $delivery ? __( 'Delivered through the page, so it works with any server, CDN or cache.', 'kwip-image-plugin' ) : __( 'Modern browsers get the lighter images automatically.', 'kwip-image-plugin' ) ); ?></span>
				<?php else : ?>
					<span class="kwip-chip kwip-chip--warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html( in_array( $delivery, array( 'missing', 'manual' ), true ) ? __( 'Needs attention', 'kwip-image-plugin' ) : __( 'Off', 'kwip-image-plugin' ) ); ?></span>
					<span class="kwip-tile__sub"><?php esc_html_e( 'Visitors currently receive your original images.', 'kwip-image-plugin' ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<section class="kwip-card">
			<h2><?php esc_html_e( 'How it works', 'kwip-image-plugin' ); ?></h2>
			<p class="kwip-lead"><?php esc_html_e( 'Photos and graphics are usually the heaviest part of a web page. This plugin makes them lighter, so your pages load faster for every visitor.', 'kwip-image-plugin' ); ?></p>
			<ol class="kwip-steps">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li class="kwip-step">
						<span class="kwip-step__icon"><span class="dashicons dashicons-<?php echo esc_attr( $step[0] ); ?>" aria-hidden="true"></span></span>
						<strong><?php echo esc_html( (string) ( $i + 1 ) . '. ' . $step[1] ); ?></strong>
						<span><?php echo esc_html( $step[2] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
			<p class="kwip-reassure"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Your original images are never changed or deleted.', 'kwip-image-plugin' ); ?></p>
		</section>

		<section class="kwip-card kwip-faq">
			<h2><?php esc_html_e( 'Common questions', 'kwip-image-plugin' ); ?></h2>
			<?php foreach ( $faq as $item ) : ?>
				<details>
					<summary><?php echo esc_html( $item[0] ); ?></summary>
					<p><?php echo esc_html( $item[1] ); ?></p>
				</details>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $r Environment report.
	 */
	private static function render_settings( array $r ): void {
		$s       = KWIP_Settings::get();
		$name    = KWIP_Settings::OPTION;
		$presets = KWIP_Settings::presets();
		$current = KWIP_Settings::preset_for_quality( (int) $s['quality'] );

		if ( ! $r['ready'] ) {
			printf(
				'<div class="notice notice-warning inline"><p>%1$s <a href="%2$s" data-kwip-goto="status">%3$s</a></p></div>',
				esc_html__( 'This server cannot create WebP images yet. You can still save your settings.', 'kwip-image-plugin' ),
				esc_url( self::tab_url( 'status' ) ),
				esc_html__( 'Open the Health check', 'kwip-image-plugin' )
			);
		}
		?>
		<form method="post" action="options.php" class="kwip-form">
			<?php settings_fields( KWIP_Settings::GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[keep_backups]" value="1">

			<section class="kwip-card">
				<h2><?php esc_html_e( 'Image quality', 'kwip-image-plugin' ); ?></h2>
				<p class="kwip-lead"><?php esc_html_e( 'Choose how much your images are shrunk. Smaller files make pages faster; higher quality keeps more detail.', 'kwip-image-plugin' ); ?></p>

				<div class="kwip-presets" role="radiogroup" aria-label="<?php esc_attr_e( 'Image quality', 'kwip-image-plugin' ); ?>">
					<?php foreach ( $presets as $key => $preset ) : ?>
						<label class="kwip-preset">
							<input type="radio" name="gwip_preset" value="<?php echo esc_attr( (string) $preset['quality'] ); ?>" <?php checked( $key === $current ); ?>>
							<span class="kwip-preset__body">
								<span class="dashicons dashicons-<?php echo esc_attr( $preset['icon'] ); ?>" aria-hidden="true"></span>
								<strong><?php echo esc_html( $preset['label'] ); ?></strong>
								<span class="description"><?php echo esc_html( $preset['desc'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="description kwip-custom" id="kwip-custom-note"
					data-template="<?php echo esc_attr( __( 'Custom quality: %d (set in Advanced options below)', 'kwip-image-plugin' ) ); ?>"
					<?php echo $current ? 'hidden' : ''; ?>>
					<?php echo esc_html( sprintf( __( 'Custom quality: %d (set in Advanced options below)', 'kwip-image-plugin' ), (int) $s['quality'] ) ); ?>
				</p>
			</section>

			<section class="kwip-card">
				<h2><?php esc_html_e( 'Automatic mode', 'kwip-image-plugin' ); ?></h2>

				<label class="kwip-switch">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_convert]" value="1" <?php checked( ! empty( $s['auto_convert'] ) ); ?>>
					<span class="kwip-switch__track" aria-hidden="true"></span>
					<span class="kwip-switch__text">
						<strong><?php esc_html_e( 'Optimize new uploads automatically', 'kwip-image-plugin' ); ?></strong>
						<span class="description"><?php esc_html_e( 'New images are optimized in the background, so uploading stays fast.', 'kwip-image-plugin' ); ?></span>
					</span>
				</label>

				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[delivery]" value="off">
				<label class="kwip-switch">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delivery]" value="webp_fallback" <?php checked( 'webp_fallback', $s['delivery'] ); ?>>
					<span class="kwip-switch__track" aria-hidden="true"></span>
					<span class="kwip-switch__text">
						<strong><?php esc_html_e( 'Show optimized images to visitors', 'kwip-image-plugin' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Visitors with a modern browser get the lighter copy. Everyone else automatically gets your original image.', 'kwip-image-plugin' ); ?></span>
					</span>
				</label>

				<p class="description gwip-platform">
					<?php
					if ( 'none' === KWIP_Delivery::active_method() ) {
						esc_html_e( 'Visitors currently receive your original images.', 'kwip-image-plugin' );
					} else {
						echo esc_html(
							sprintf(
								__( 'Your server: %1$s. Optimized images are delivered with the %2$s.', 'kwip-image-plugin' ),
								KWIP_Platform::label(),
								KWIP_Delivery::method_label()
							)
						);
					}
					?>
				</p>

				<p class="kwip-reassure"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Your original images are always kept. Nothing is ever deleted or overwritten.', 'kwip-image-plugin' ); ?></p>
			</section>

			<details class="kwip-card kwip-advanced">
				<summary><?php esc_html_e( 'Advanced options', 'kwip-image-plugin' ); ?></summary>
				<div class="kwip-advanced__body">

					<div class="kwip-field" id="kwip-quality-field">

					<?php
					$methods = array(
						'auto'   => array(
							'lightbulb',
							__( 'Automatic (recommended)', 'kwip-image-plugin' ),
							'server' === KWIP_Platform::recommended_method()
								? __( 'We pick the best method for your server. Right now that is the server method.', 'kwip-image-plugin' )
								: __( 'We pick the best method for your server. Right now that is the page method.', 'kwip-image-plugin' ),
						),
						'server' => array(
							'database',
							__( 'Server method', 'kwip-image-plugin' ),
							KWIP_Platform::supports_server_rules()
								? __( 'Fastest, and it changes nothing in your pages. Works on Apache and LiteSpeed. Some CDNs ignore it.', 'kwip-image-plugin' )
								: __( 'Not available on your server, because it cannot be set up from here.', 'kwip-image-plugin' ),
						),
						'page'   => array(
							'editor-code',
							__( 'Page method', 'kwip-image-plugin' ),
							__( 'Works on any server, CDN or cache. Images in your pages are wrapped so each visitor\'s browser picks the lighter file. Images added by some page builders may be missed.', 'kwip-image-plugin' ),
						),
					);
					?>
					<fieldset class="gwip-field">
						<legend><strong><?php esc_html_e( 'How optimized images reach visitors', 'kwip-image-plugin' ); ?></strong></legend>
						<p class="description"><?php esc_html_e( 'Most sites should leave this on Automatic.', 'kwip-image-plugin' ); ?></p>
						<div class="gwip-presets">
							<?php foreach ( $methods as $key => $method ) : ?>
								<label class="kwip-preset">
									<input type="radio" name="<?php echo esc_attr( $name ); ?>[method]" value="<?php echo esc_attr( $key ); ?>"
										<?php checked( $key, (string) $s['method'] ); ?>
										<?php disabled( 'server' === $key && ! KWIP_Platform::supports_server_rules() ); ?>>
									<span class="kwip-preset__body">
										<span class="dashicons dashicons-<?php echo esc_attr( $method[0] ); ?>" aria-hidden="true"></span>
										<strong><?php echo esc_html( $method[1] ); ?></strong>
										<span class="description"><?php echo esc_html( $method[2] ); ?></span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>


						<label for="kwip-quality"><strong><?php esc_html_e( 'Exact quality (1 to 100)', 'kwip-image-plugin' ); ?></strong></label>
						<div class="kwip-quality">
							<input type="range" id="kwip-quality-range" min="1" max="100" step="1"
								value="<?php echo esc_attr( (string) $s['quality'] ); ?>" aria-hidden="true" tabindex="-1">
							<input type="number" id="kwip-quality" class="small-text" min="1" max="100" step="1"
								name="<?php echo esc_attr( $name ); ?>[quality]"
								value="<?php echo esc_attr( (string) $s['quality'] ); ?>"
								aria-describedby="kwip-quality-desc">
						</div>
						<p class="description" id="kwip-quality-desc"><?php esc_html_e( 'The quality cards above set this for you. Higher keeps more detail; lower makes smaller files.', 'kwip-image-plugin' ); ?></p>
					</div>

					<fieldset class="kwip-field">
						<legend><strong><?php esc_html_e( 'Image types to optimize', 'kwip-image-plugin' ); ?></strong></legend>
						<?php foreach ( KWIP_Settings::format_choices() as $key => $label ) : ?>
							<label class="kwip-inline">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[formats][]" value="<?php echo esc_attr( $key ); ?>"
									<?php checked( in_array( $key, $s['formats'], true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Animated GIFs are left untouched and SVG files are never changed.', 'kwip-image-plugin' ); ?></p>
					</fieldset>

					<div class="kwip-field">
						<label for="kwip-min-savings"><strong><?php esc_html_e( 'Only use an optimized copy if it is smaller by at least', 'kwip-image-plugin' ); ?></strong></label>
						<div>
							<input type="number" id="kwip-min-savings" class="small-text" min="0" max="90" step="1"
								name="<?php echo esc_attr( $name ); ?>[min_savings]"
								value="<?php echo esc_attr( (string) $s['min_savings'] ); ?>"
								aria-describedby="kwip-min-savings-desc"> %
						</div>
						<p class="description" id="kwip-min-savings-desc"><?php esc_html_e( 'If the optimized copy would save less than this, your original image is used instead.', 'kwip-image-plugin' ); ?></p>
					</div>

					<label class="kwip-switch">
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_larger]" value="1" <?php checked( ! empty( $s['allow_larger'] ) ); ?>>
						<span class="kwip-switch__track" aria-hidden="true"></span>
						<span class="kwip-switch__text">
							<strong><?php esc_html_e( 'Use optimized copies even when they are not smaller', 'kwip-image-plugin' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Not recommended. This overrides the size check above.', 'kwip-image-plugin' ); ?></span>
						</span>
					</label>
				</div>
			</details>

			<div class="kwip-savebar" id="kwip-savebar">
				<span class="kwip-savebar__text"><?php esc_html_e( 'You have unsaved changes.', 'kwip-image-plugin' ); ?></span>
				<button type="button" class="button" id="kwip-discard"><?php esc_html_e( 'Discard', 'kwip-image-plugin' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'kwip-image-plugin' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * @param array<string, mixed> $r Environment report.
	 */
	private static function render_status( array $r ): void {
		$checks = array(
			array(
				$r['wp_webp'],
				__( 'Your server can create optimized (WebP) images', 'kwip-image-plugin' ),
				__( 'Ask your hosting provider to enable WebP support for PHP (the GD or Imagick extension).', 'kwip-image-plugin' ),
			),
			array(
				$r['uploads_ok'],
				__( 'WordPress can save files in your uploads folder', 'kwip-image-plugin' ),
				__( 'The uploads folder is not writable. Check its permissions or ask your hosting provider.', 'kwip-image-plugin' ),
			),
			array(
				$r['php_ok'],
				sprintf( __( 'PHP version %s is supported', 'kwip-image-plugin' ), $r['php_version'] ),
				sprintf( __( 'PHP %s or newer is required. Ask your hosting provider to update it.', 'kwip-image-plugin' ), KWIP_MIN_PHP ),
			),
			array(
				$r['wp_ok'],
				sprintf( __( 'WordPress version %s is supported', 'kwip-image-plugin' ), $r['wp_version'] ),
				sprintf( __( 'WordPress %s or newer is required. Please update WordPress.', 'kwip-image-plugin' ), KWIP_MIN_WP ),
			),
			array(
				true,
				sprintf(
					__( 'Your server (%1$s) is supported. Delivery method: %2$s.', 'kwip-image-plugin' ),
					KWIP_Platform::label(),
					KWIP_Delivery::method_label()
				),
				'',
			),
		);

		$problems = array_filter(
			$checks,
			static function ( $check ) {
				return ! $check[0];
			}
		);

		$yes = __( 'Yes', 'kwip-image-plugin' );
		$no  = __( 'No', 'kwip-image-plugin' );
		$rows = array(
			array( __( 'PHP version', 'kwip-image-plugin' ), $r['php_version'] ),
			array( __( 'WordPress version', 'kwip-image-plugin' ), $r['wp_version'] ),
			array( __( 'WordPress image editor can write WebP', 'kwip-image-plugin' ), $r['wp_webp'] ? $yes : $no ),
			array( __( 'Uploads directory writable', 'kwip-image-plugin' ), $r['uploads_ok'] ? $yes : $no ),
			array( __( 'GD loaded', 'kwip-image-plugin' ), $r['gd_loaded'] ? $yes : $no ),
			array( __( 'GD WebP support', 'kwip-image-plugin' ), $r['gd_webp'] ? $yes : $no ),
			array( __( 'Imagick loaded', 'kwip-image-plugin' ), $r['imagick_loaded'] ? $yes : $no ),
			array( __( 'Imagick WebP support', 'kwip-image-plugin' ), $r['imagick_webp'] ? $yes : $no ),
			array( __( 'PHP memory_limit', 'kwip-image-plugin' ), $r['memory_limit'] ),
			array( __( 'PHP max_execution_time (seconds)', 'kwip-image-plugin' ), $r['max_execution'] ),
		);
		?>
		<section class="kwip-card kwip-health">
			<div class="kwip-health__head">
				<?php if ( empty( $problems ) ) : ?>
					<svg class="kwip-check" viewBox="0 0 52 52" aria-hidden="true">
						<circle class="kwip-check__circle" cx="26" cy="26" r="24"></circle>
						<path class="kwip-check__tick" d="M14 27l8 8 16-17"></path>
					</svg>
					<div>
						<h2><?php esc_html_e( 'Everything is set up correctly', 'kwip-image-plugin' ); ?></h2>
						<p><?php esc_html_e( 'Your server can create optimized images and save them.', 'kwip-image-plugin' ); ?></p>
					</div>
				<?php else : ?>
					<span class="dashicons dashicons-warning kwip-health__warn" aria-hidden="true"></span>
					<div>
						<h2><?php esc_html_e( 'Something needs attention', 'kwip-image-plugin' ); ?></h2>
						<p><?php esc_html_e( 'The items marked below need to be fixed before images can be optimized.', 'kwip-image-plugin' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<ul class="kwip-checklist">
				<?php foreach ( $checks as $check ) : ?>
					<li>
						<span class="dashicons dashicons-<?php echo $check[0] ? 'yes-alt' : 'warning'; ?>" aria-hidden="true"></span>
						<span>
							<?php echo esc_html( $check[1] ); ?>
							<?php if ( ! $check[0] ) : ?>
								<span class="description kwip-block"><?php echo esc_html( $check[2] ); ?></span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		self::render_delivery_card();
		self::render_queue_card();
		KWIP_Scan::render_card();
		?>
		<details class="kwip-card kwip-advanced">
			<summary><?php esc_html_e( 'Technical details', 'kwip-image-plugin' ); ?></summary>
			<div class="kwip-advanced__body">
				<p class="description"><?php esc_html_e( 'Useful if you are asking a developer or your hosting provider for help.', 'kwip-image-plugin' ); ?></p>
				<table class="widefat striped kwip-status">
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row[0] ); ?></td>
							<td><?php echo esc_html( $row[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
		<?php
	}

	public static function handle_process_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'kwip-image-plugin' ), 403 );
		}
		check_admin_referer( 'gwip_process_now' );

		$result = KWIP_Queue::process( 15 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE,
					'tab'            => 'status',
					'gwip_processed' => $result['processed'],
					'gwip_remaining' => $result['remaining'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_queue_card(): void {
		if ( isset( $_GET['gwip_processed'], $_GET['gwip_remaining'] ) ) { 
			$done = absint( wp_unslash( $_GET['gwip_processed'] ) ); 
			$left = absint( wp_unslash( $_GET['gwip_remaining'] ) ); 
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						__( 'Processed %1$d image(s). %2$d still waiting.', 'kwip-image-plugin' ),
						$done,
						$left
					)
				)
			);
		}

		$pending  = KWIP_Queue::pending_count();
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		?>
		<section class="kwip-card">
			<h2><?php esc_html_e( 'Work in the background', 'kwip-image-plugin' ); ?></h2>
			<p>
				<?php
				if ( $pending > 0 ) {
					echo esc_html(
						sprintf(
							_n( '%d image is waiting to be optimized. This normally happens by itself within a few minutes.', '%d images are waiting to be optimized. This normally happens by itself within a few minutes.', $pending, 'kwip-image-plugin' ),
							$pending
						)
					);
				} else {
					esc_html_e( 'Nothing is waiting. New uploads will be optimized automatically.', 'kwip-image-plugin' );
				}
				?>
			</p>
			<?php if ( $cron_off ) : ?>
				<p class="description"><?php esc_html_e( 'Scheduled tasks are switched off on this site, so waiting images are only processed when you use the button below or when your host runs scheduled tasks.', 'kwip-image-plugin' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gwip_process_now">
				<?php wp_nonce_field( 'gwip_process_now' ); ?>
				<?php submit_button( __( 'Optimize waiting images now', 'kwip-image-plugin' ), 'secondary', 'submit', false, $pending > 0 ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</section>
		<?php
	}

	public static function handle_delivery_apply(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'kwip-image-plugin' ), 403 );
		}
		check_admin_referer( 'gwip_delivery_apply', 'gwip_apply_nonce' );

		$result = KWIP_Delivery::apply();
		KWIP_Delivery::flash(
			is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					'message' => __( 'The server setup is up to date.', 'kwip-image-plugin' ),
				)
		);

		wp_safe_redirect( self::tab_url( 'status' ) );
		exit;
	}

	public static function handle_delivery_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'kwip-image-plugin' ), 403 );
		}
		check_admin_referer( 'gwip_delivery_test', 'gwip_test_nonce' );

		KWIP_Delivery::flash( KWIP_Delivery::test() );

		wp_safe_redirect( self::tab_url( 'status' ) );
		exit;
	}

	private static function render_delivery_card(): void {
		$state = KWIP_Delivery::state();
		$flash = KWIP_Delivery::take_flash();

		$descriptions = array(
			'active'  => __( 'On, using the server method. Visitors with a modern browser receive the lighter images automatically; everyone else gets your originals.', 'kwip-image-plugin' ),
			'page'    => __( 'On, using the page method. Images in your pages are wrapped so each visitor\'s browser picks the lighter file. This works with any server, CDN or cache.', 'kwip-image-plugin' ),
			'missing' => __( 'Not working yet. The server setup is missing. Click "Fix automatically".', 'kwip-image-plugin' ),
			'manual'  => __( 'Needs manual setup. This server cannot be set up automatically. Choose the page method in Settings, or send the rules below to your hosting provider.', 'kwip-image-plugin' ),
			'stale'   => __( 'Off, but leftover server rules are still in place. Click "Fix automatically" to clean up.', 'kwip-image-plugin' ),
			'off'     => __( 'Off. Visitors receive your original images. You can turn this on in Settings.', 'kwip-image-plugin' ),
		);

		if ( $flash ) {
			$type = in_array( $flash['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? (string) $flash['type'] : 'info';
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p>', esc_attr( $type ), esc_html( (string) ( $flash['message'] ?? '' ) ) );
			if ( ! empty( $flash['rows'] ) && is_array( $flash['rows'] ) ) {
				echo '<ul class="kwip-checks">';
				foreach ( $flash['rows'] as $row ) {
					printf(
						'<li>%1$s: <code>%2$s</code> <strong>%3$s</strong></li>',
						esc_html( (string) $row[0] ),
						esc_html( (string) $row[1] ),
						esc_html( $row[2] ? __( 'OK', 'kwip-image-plugin' ) : __( 'Not detected', 'kwip-image-plugin' ) )
					);
				}
				echo '</ul>';
			}
			echo '</div>';
		}
		?>
		<section class="kwip-card">
			<h2><?php esc_html_e( 'Show optimized images to visitors', 'kwip-image-plugin' ); ?></h2>
			<p><?php echo esc_html( $descriptions[ $state['key'] ] ); ?></p>

			<div class="kwip-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="gwip_delivery_apply">
					<?php wp_nonce_field( 'gwip_delivery_apply', 'gwip_apply_nonce' ); ?>
					<?php submit_button( __( 'Fix automatically', 'kwip-image-plugin' ), 'secondary', 'kwip-apply', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="gwip_delivery_test">
					<?php wp_nonce_field( 'gwip_delivery_test', 'gwip_test_nonce' ); ?>
					<?php submit_button( __( 'Check that it works', 'kwip-image-plugin' ), 'secondary', 'kwip-test', false, 'off' === $state['key'] ? array( 'disabled' => 'disabled' ) : array() ); ?>
				</form>
			</div>

			<details class="kwip-rules">
				<summary><?php esc_html_e( 'For developers: server rules', 'kwip-image-plugin' ); ?></summary>
				<p class="description"><?php esc_html_e( 'Apache / LiteSpeed (added to .htaccess automatically):', 'kwip-image-plugin' ); ?></p>
				<textarea readonly rows="13" class="large-text code"><?php echo esc_textarea( implode( "\n", KWIP_Delivery::rules() ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Nginx (manual, not tested):', 'kwip-image-plugin' ); ?></p>
				<textarea readonly rows="12" class="large-text code"><?php echo esc_textarea( KWIP_Delivery::nginx_rules() ); ?></textarea>
			</details>
		</section>
		<?php
	}
}