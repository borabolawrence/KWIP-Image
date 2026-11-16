<?php
/**
 * The queue, the status queries and the screens that read them.
 */

return array(

	'the queue refuses duplicate jobs' => static function () {
		$id     = KT::attachment( 'jpeg', 400, 300 );
		$before = KWIP_Queue::pending_count();

		KT::same( true, KWIP_Queue::enqueue( $id ), 'first enqueue' );
		KT::same( false, KWIP_Queue::enqueue( $id ), 'second enqueue is refused' );
		KT::ok( KWIP_Queue::is_queued( $id ), 'the image is queued' );
		KT::same( $before + 1, KWIP_Queue::pending_count(), 'queued once' );

		KWIP_Queue::dequeue( $id );
		KT::ok( ! KWIP_Queue::is_queued( $id ), 'removed from the queue' );
		KT::same( $before, KWIP_Queue::pending_count(), 'the count goes back' );
	},

	'only JPEG and PNG images are queued' => static function () {
		KT::same( false, KWIP_Queue::enqueue( KT::text_attachment() ), 'a text file' );
	},

	'delayed jobs are not due yet' => static function () {
		$id  = KT::attachment( 'jpeg', 400, 300 );
		$due = KWIP_Queue::due_count();
		$all = KWIP_Queue::pending_count();

		KWIP_Queue::enqueue( $id, 3600 );
		KT::same( $due, KWIP_Queue::due_count(), 'a delayed job is not due' );
		KT::same( $all + 1, KWIP_Queue::pending_count(), 'but it is pending' );
		KWIP_Queue::dequeue( $id );
	},

	'regenerating thumbnails resets the conversion' => static function () {
		KT::prepare_conversion( array( 'auto_convert' => false ) );
		$id = KT::attachment( 'jpeg', 800, 600 );
		KWIP_Converter::convert_attachment( $id );

		$meta = array( 'sizes' => array() );
		KT::same( $meta, KWIP_Queue::on_generate( $meta, $id ), 'the metadata is returned unchanged' );
		KT::same( array(), KT::webps( $id ), 'the old WebP files were removed' );
		KT::ok( KWIP_Queue::is_queued( $id ), 'an image that had a record is queued again' );
		KWIP_Queue::dequeue( $id );

		$fresh = KT::attachment( 'jpeg', 300, 200 );
		KWIP_Queue::on_generate( array(), $fresh );
		KT::ok( ! KWIP_Queue::is_queued( $fresh ), 'a new image is not queued when auto-convert is off' );

		KT::settings( array( 'auto_convert' => true ) );
		KWIP_Queue::on_generate( array(), $fresh );
		KT::ok( KWIP_Queue::is_queued( $fresh ), 'a new image is queued when auto-convert is on' );
		KWIP_Queue::dequeue( $fresh );
	},

	'the queue runner converts a due image' => static function () {
		KT::prepare_conversion();
		if ( KWIP_Queue::due_count() > 0 ) {
			KT::skip( 'Your real queue has due images, and this test would process them too.' );
		}
		$id = KT::attachment( 'jpeg', 600, 400 );
		KT::ok( KWIP_Queue::enqueue( $id ), 'the image is queued' );
		KT::same( 1, KWIP_Queue::due_count(), 'one image is due' );

		$result = KWIP_Queue::process( 20 );
		KT::ok( $result['processed'] >= 1, 'the runner processed it' );
		KT::same( 'converted', get_post_meta( $id, '_kwip_status', true ), 'the image was converted' );
		KT::ok( ! KWIP_Queue::is_queued( $id ), 'it left the queue' );
		KT::same( 0, KWIP_Queue::due_count(), 'nothing is due afterwards' );
	},

	'only one queue runner works at a time' => static function () {
		if ( false !== get_option( 'kwip_queue_run_lock', false ) ) {
			KT::skip( 'A real queue run seems to be in progress.' );
		}
		add_option( 'kwip_queue_run_lock', time(), '', false );
		$result = KWIP_Queue::process( 1 );
		delete_option( 'kwip_queue_run_lock' );
		KT::same( 0, $result['processed'], 'a second runner does nothing' );
	},

	'the optimized-images query matches converted and partial only' => static function () {
		$ids = array();
		foreach ( array( 'converted', 'partial', 'skipped', 'failed' ) as $status ) {
			$ids[ $status ] = KT::text_attachment();
			update_post_meta( $ids[ $status ], KWIP_Converter::META_STATUS, $status );
		}
		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post__in'    => array_values( $ids ),
				'fields'      => 'ids',
				'numberposts' => -1,
				'meta_query'  => array( KWIP_Media::optimized_meta_query() ),
			)
		);
		$found = array_map( 'intval', $found );
		sort( $found );
		$expect = array( $ids['converted'], $ids['partial'] );
		sort( $expect );
		KT::same( $expect, $found, 'only converted and partial images match' );
	},

	'the problems count follows the status meta' => static function () {
		KWIP_Problems::flush();
		$base = KWIP_Problems::count();
		$id   = KT::text_attachment();

		$expect = array(
			'failed'    => $base + 1,
			'partial'   => $base + 1,
			'skipped'   => $base,
			'converted' => $base,
		);
		foreach ( $expect as $status => $count ) {
			update_post_meta( $id, KWIP_Converter::META_STATUS, $status );
			KWIP_Problems::flush();
			KT::same( $count, KWIP_Problems::count(), "count with status '$status'" );
		}
	},

	'the savings totals count a converted image' => static function () {
		KT::prepare_conversion();
		KWIP_Stats::flush();
		$before = KWIP_Stats::savings();

		$id = KT::attachment( 'jpeg', 800, 600 );
		KWIP_Converter::convert_attachment( $id );

		$after = KWIP_Stats::savings();
		KT::same( $before['optimized'] + 1, $after['optimized'], 'one more optimized image' );
		KT::ok( $after['original'] > $before['original'], 'the original total grew' );
		KT::ok( $after['webp'] > $before['webp'], 'the WebP total grew' );
	},

	'the media status box shows each state and escapes text' => static function () {
		$id = KT::text_attachment();
		$ui = KWIP_Media::render( $id );
		KT::has( 'Not converted', $ui, 'a fresh image' );
		KT::has( 'data-status="none"', $ui, 'its status attribute' );

		update_post_meta(
			$id,
			KWIP_Converter::META_KEY,
			array(
				'version' => 1,
				'status'  => 'failed',
				'error'   => 'boom<script>alert(1)</script>',
				'files'   => array(),
			)
		);
		$ui = KWIP_Media::render( $id );
		KT::has( 'Failed', $ui, 'a failed image' );
		KT::has( 'boom&lt;script&gt;', $ui, 'the error text is escaped' );
		KT::not_has( '<script>', $ui, 'no raw script tag is output' );
	},
);