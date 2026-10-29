<?php
/**
 * Bulk optimization: library counts, queue filling and AJAX progress.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Bulk {

	private const NONCE_ACTION = 'gwip_bulk';
	private const CHUNK        = 200;

	/** @var array<string, int>|null */
	private static ?array $counts_cache = null;

	public static function init(): void {
		add_action( 'wp_ajax_gwip_bulk', array( __CLASS__, 'handle_ajax' ) );
	}

	public static function enqueue(): void {
		KWIP_Admin::register_shared_assets();
		wp_enqueue_style( 'kwip-modal' );

		wp_enqueue_script( 'kwip-bulk', KWIP_URL . 'assets/js/bulk.js', array( 'jquery', 'wp-a11y', 'kwip-modal' ), KWIP_VERSION, true );
		wp_localize_script(
			'kwip-bulk',
			'gwipBulk',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'counts'  => self::counts(),
				'i18n'    => array(
					'preparing'           => __( 'Preparing the queue… %s images waiting.', 'kwip-image-plugin' ),
					'converting'          => __( 'Converting… %1$s of %2$s done.', 'kwip-image-plugin' ),
					'stopping'            => __( 'Pausing after the current batch…', 'kwip-image-plugin' ),
					'stopped'             => __( 'Paused. %s images are still waiting. Press Resume to continue.', 'kwip-image-plugin' ),
					'finished'            => __( 'Done! %1$s optimized, %2$s already as small as possible, %3$s could not be processed.', 'kwip-image-plugin' ),
					'retryLater'          => __( '%s images will be retried automatically in a few minutes.', 'kwip-image-plugin' ),
					'waiting'             => __( '%s images are waiting in the queue.', 'kwip-image-plugin' ),
					'cancelled'           => __( 'The waiting images were removed from the queue. Converted images were not affected.', 'kwip-image-plugin' ),
					'confirmCancelTitle'  => __( 'Remove the waiting images?', 'kwip-image-plugin' ),
					'confirmCancel'       => __( 'All images still waiting will be removed from the queue. Images already converted are not affected.', 'kwip-image-plugin' ),
					'confirmCancelButton' => __( 'Remove from queue', 'kwip-image-plugin' ),
					'confirmAllTitle'     => __( 'Re-convert every image?', 'kwip-image-plugin' ),
					'confirmAll'          => __( 'Existing WebP files will be replaced. This can take a while on a large library.', 'kwip-image-plugin' ),
					'confirmAllButton'    => __( 'Re-convert all', 'kwip-image-plugin' ),
					'error'               => __( 'Something went wrong. Please try again.', 'kwip-image-plugin' ),
				),
			)
		);
	}

	/** @return string[] MIME types of the image types enabled in Settings. */
	private static function mime_types(): array {
		$map     = array(
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
		);
		$enabled = KWIP_Settings::get()['formats'];
		$mimes   = array();
		foreach ( $map as $key => $mime ) {
			if ( in_array( $key, $enabled, true ) ) {
				$mimes[] = $mime;
			}
		}
		return $mimes ? $mimes : array_values( $map );
	}

	/**
	 * @return array{total:int,unprocessed:int,queued:int}
	 */
	public static function counts( bool $fresh = false ): array {
		if ( null !== self::$counts_cache && ! $fresh ) {
			return self::$counts_cache;
		}

		$base = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::mime_types(),
			'fields'         => 'ids',
			'posts_per_page' => 1,
		);

		$total = new WP_Query( $base );
		$new   = new WP_Query(
			$base + array(
				'meta_query' => array(
					array(
						'key'     => KWIP_Converter::META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		self::$counts_cache = array(
			'total'       => (int) $total->found_posts,
			'unprocessed' => (int) $new->found_posts,
			'queued'      => KWIP_Queue::pending_count(),
		);
		return self::$counts_cache;
	}


	public static function handle_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'kwip-image-plugin' ) ), 403 );
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		switch ( $op ) {
			case 'status':
				wp_send_json_success( self::counts( true ) );
				break;

			case 'prepare':
				$report = KWIP_Environment::get_report();
				if ( ! $report['ready'] ) {
					wp_send_json_error( array( 'message' => __( 'This server cannot write WebP images. See Server status.', 'kwip-image-plugin' ) ) );
				}

				$scope = ( isset( $_POST['scope'] ) && 'all' === sanitize_key( wp_unslash( $_POST['scope'] ) ) ) ? 'all' : 'new';
				$after = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;

				$ids   = self::next_ids( $scope, $after );
				$added = 0;
				foreach ( $ids as $id ) {
					if ( KWIP_Queue::enqueue( $id ) ) {
						++$added;
					}
				}

				wp_send_json_success(
					array(
						'added'   => $added,
						'last_id' => $ids ? (int) end( $ids ) : $after,
						'done'    => count( $ids ) < self::CHUNK,
						'pending' => KWIP_Queue::pending_count(),
					)
				);
				break;

			case 'process':
				$result = KWIP_Queue::process( 10 );
				$data   = array(
					'processed' => $result['processed'],
					'remaining' => $result['remaining'],
					'due'       => KWIP_Queue::due_count(),
					'stats'     => $result['stats'],
				);
				if ( 0 === $data['due'] ) {
					$data['counts'] = self::counts( true );
				}
				wp_send_json_success( $data );
				break;

			case 'cancel':
				KWIP_Queue::clear();
				wp_send_json_success( array( 'counts' => self::counts( true ) ) );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown action.', 'kwip-image-plugin' ) ), 400 );
		}
	}

	/**
	 * Next chunk of attachment IDs after a cursor, in ascending ID order.
	 *
	 * @return int[]
	 */
	private static function next_ids( string $scope, int $after ): array {
		$args = array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'post_mime_type'   => self::mime_types(),
			'posts_per_page'   => self::CHUNK,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		);
		if ( 'new' === $scope ) {
			$args['meta_query'] = array(
				array(
					'key'     => KWIP_Converter::META_KEY,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$filter = static function ( $where ) use ( $after ) {
			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		};

		add_filter( 'posts_where', $filter );
		$posts = get_posts( $args );
		remove_filter( 'posts_where', $filter );

		return array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );
	}


	/**
	 * @param array<string, mixed> $report Environment report.
	 */
	public static function render( array $report ): void {
		if ( ! $report['ready'] ) {
			printf(
				'<div class="notice notice-warning inline"><p>%1$s <a href="%2$s" data-kwip-goto="status">%3$s</a></p></div>',
				esc_html__( 'Optimizing needs a server that can create WebP images.', 'kwip-image-plugin' ),
				esc_url(
					add_query_arg(
						array(
							'page' => 'KWIP-webp',
							'tab'  => 'status',
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Open the Health check', 'kwip-image-plugin' )
			);
			return;
		}

		$counts        = self::counts();
		$settings      = KWIP_Settings::get();
		$presets       = KWIP_Settings::presets();
		$preset_key    = KWIP_Settings::preset_for_quality( (int) $settings['quality'] );
		$quality_label = $preset_key ? $presets[ $preset_key ]['label'] : sprintf(
			__( 'Custom (%d)', 'kwip-image-plugin' ),
			(int) $settings['quality']
		);
		$settings_url  = add_query_arg(
			array(
				'page' => 'KWIP-webp',
				'tab'  => 'settings',
			),
			admin_url( 'admin.php' )
		);
		$optimized_url = add_query_arg(
			array(
				'mode'        => 'list',
				'gwip_filter' => 'optimized',
			),
			admin_url( 'upload.php' )
		);
		?>
		<section class="kwip-card kwip-bulk" id="kwip-bulk">
			<h2><?php esc_html_e( 'Optimize your images', 'kwip-image-plugin' ); ?></h2>
			<p class="kwip-lead"><?php esc_html_e( 'Already have photos in your Media Library? Start here to optimize all of them. You can keep using your website while this runs; keeping this page open makes it faster.', 'kwip-image-plugin' ); ?></p>

			<dl class="kwip-stats">
				<div>
					<dt><?php esc_html_e( 'Images in your library', 'kwip-image-plugin' ); ?></dt>
					<dd id="kwip-count-total"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Not optimized yet', 'kwip-image-plugin' ); ?></dt>
					<dd id="kwip-count-unprocessed"><?php echo esc_html( number_format_i18n( $counts['unprocessed'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Waiting in line', 'kwip-image-plugin' ); ?></dt>
					<dd id="kwip-count-queued"><?php echo esc_html( number_format_i18n( $counts['queued'] ) ); ?></dd>
				</div>
			</dl>

			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						__( 'Quality: %s.', 'kwip-image-plugin' ),
						$quality_label
					)
				);
				?>
				<a href="<?php echo esc_url( $settings_url ); ?>" data-kwip-goto="settings"><?php esc_html_e( 'Change in Settings', 'kwip-image-plugin' ); ?></a>
			</p>

			<fieldset class="kwip-field" id="kwip-bulk-scope">
				<legend><strong><?php esc_html_e( 'What should be optimized?', 'kwip-image-plugin' ); ?></strong></legend>
				<label class="kwip-choice">
					<input type="radio" name="kwip-bulk-scope" value="new" checked>
					<span>
						<strong><?php esc_html_e( 'New images only (recommended)', 'kwip-image-plugin' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Images that have not been checked yet.', 'kwip-image-plugin' ); ?></span>
					</span>
				</label>
				<label class="kwip-choice">
					<input type="radio" name="kwip-bulk-scope" value="all">
					<span>
						<strong><?php esc_html_e( 'Everything again', 'kwip-image-plugin' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Useful after changing the quality. Existing optimized copies are replaced.', 'kwip-image-plugin' ); ?></span>
					</span>
				</label>
			</fieldset>

			<div class="kwip-actions">
				<button type="button" class="button button-primary button-hero" id="kwip-bulk-start"><?php esc_html_e( 'Start optimizing', 'kwip-image-plugin' ); ?></button>
				<button type="button" class="button button-primary" id="kwip-bulk-resume" hidden><?php esc_html_e( 'Resume', 'kwip-image-plugin' ); ?></button>
				<button type="button" class="button" id="kwip-bulk-stop" hidden><?php esc_html_e( 'Pause', 'kwip-image-plugin' ); ?></button>
				<button type="button" class="button" id="kwip-bulk-cancel" hidden><?php esc_html_e( 'Remove waiting images', 'kwip-image-plugin' ); ?></button>
			</div>

			<div class="kwip-progress" id="kwip-bulk-progress-wrap" hidden>
				<div class="kwip-bar" id="kwip-bulk-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Optimization progress', 'kwip-image-plugin' ); ?>">
					<div class="kwip-bar__fill" id="kwip-bulk-fill"></div>
				</div>
				<span id="kwip-bulk-percent">0%</span>
			</div>

			<p id="kwip-bulk-status" role="status"></p>
			<p id="kwip-bulk-message" role="alert"></p>
			<p>
				<a id="kwip-bulk-link" href="<?php echo esc_url( $optimized_url ); ?>" hidden><?php esc_html_e( 'See the optimized images in your Media Library', 'kwip-image-plugin' ); ?></a>
			</p>
		</section>
		<?php
	}
}