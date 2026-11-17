<?php
/**
 * KWIP_Upgrade: the gwip_ to kwip_ rename (v1) and the status backfill (v2).
 */

$run = static function ( int $from ): void {
	delete_option( 'kwip_lock_upgrade' );
	update_option( 'kwip_db_version', $from );
	KWIP_Upgrade::maybe_run();
};

$record = static function ( string $status, string $code = '' ): array {
	return array(
		'version'        => 1,
		'status'         => $status,
		'time'           => time(),
		'original_total' => 0,
		'webp_total'     => 0,
		'files'          => '' === $code ? array() : array(
			'a.jpg' => array(
				'status' => $status,
				'code'   => $code,
			),
		),
	);
};

return array(

	'version 2 fills in the status of existing records' => static function () use ( $run, $record ) {
		if ( ! get_current_user_id() ) {
			KT::skip( 'No administrator account found.' );
		}

		$ids = array();
		foreach ( array( 'converted', 'partial', 'skipped', 'failed' ) as $status ) {
			$ids[ $status ] = KT::text_attachment();
			update_post_meta( $ids[ $status ], KWIP_Converter::META_KEY, $record( $status ) );
		}
		$odd = KT::text_attachment();
		update_post_meta( $odd, KWIP_Converter::META_KEY, $record( 'weird' ) );
		$junk = KT::text_attachment();
		update_post_meta( $junk, KWIP_Converter::META_KEY, 'not a record' );

		$run( 1 );

		foreach ( $ids as $status => $id ) {
			KT::same( $status, get_post_meta( $id, KWIP_Converter::META_STATUS, true ), "status '$status' copied from the record" );
		}
		KT::same( 'skipped', get_post_meta( $odd, KWIP_Converter::META_STATUS, true ), 'an unknown status becomes skipped' );
		KT::same( 'skipped', get_post_meta( $junk, KWIP_Converter::META_STATUS, true ), 'a damaged record becomes skipped' );
		KT::same( KWIP_Upgrade::DB_VERSION, (int) get_option( 'kwip_db_version' ), 'the data version is stored' );

		$run( KWIP_Upgrade::DB_VERSION );
		KT::same( 'converted', get_post_meta( $ids['converted'], KWIP_Converter::META_STATUS, true ), 'running again changes nothing' );
	},

	'version 1 moves data saved under the old gwip_ names' => static function () use ( $run, $record ) {
		if ( ! get_current_user_id() ) {
			KT::skip( 'No administrator account found.' );
		}

		$a = KT::text_attachment();
		update_post_meta( $a, '_gwip_data', $record( 'failed', 'gwip_disk_space' ) );
		update_post_meta( $a, '_gwip_queued', time() );
		update_post_meta( $a, '_gwip_attempts', 2 );

		$b = KT::text_attachment();
		update_post_meta( $b, '_gwip_data', $record( 'failed' ) );
		update_post_meta( $b, KWIP_Converter::META_KEY, $record( 'skipped' ) );

		$had_old_option = null !== get_option( 'gwip_settings', null );
		if ( ! $had_old_option ) {
			add_option( 'gwip_settings', array( 'quality' => 11 ), '', true );
		}
		$kwip_before = get_option( KWIP_Settings::OPTION, null );

		$run( 0 );

		KT::same( '', get_post_meta( $a, '_gwip_data', true ), 'the old record is gone' );
		$moved = get_post_meta( $a, KWIP_Converter::META_KEY, true );
		KT::same( 'kwip_disk_space', $moved['files']['a.jpg']['code'] ?? null, 'the error code is renamed inside the record' );
		KT::ok( '' !== (string) get_post_meta( $a, '_kwip_queued', true ), 'the queue entry moved' );
		KT::same( '2', (string) get_post_meta( $a, '_kwip_attempts', true ), 'the attempts moved' );
		KT::same( 'failed', get_post_meta( $a, KWIP_Converter::META_STATUS, true ), 'the status meta was filled in' );

		$kept = get_post_meta( $b, KWIP_Converter::META_KEY, true );
		KT::same( 'skipped', $kept['status'] ?? null, 'a record that already has the new name wins' );
		KT::same( '', get_post_meta( $b, '_gwip_data', true ), 'the duplicate old record is removed' );

		KT::same( false, get_option( 'gwip_settings', false ), 'the old settings option is removed' );
		if ( null !== $kwip_before ) {
			KT::same( $kwip_before, get_option( KWIP_Settings::OPTION, null ), 'existing new settings are not overwritten' );
		}
	},

	'nothing runs when the data is already current' => static function () use ( $run, $record ) {
		if ( ! get_current_user_id() ) {
			KT::skip( 'No administrator account found.' );
		}
		$id = KT::text_attachment();
		update_post_meta( $id, '_gwip_data', $record( 'failed' ) );

		$run( KWIP_Upgrade::DB_VERSION );

		KT::ok( is_array( get_post_meta( $id, '_gwip_data', true ) ), 'the old record was not touched' );
	},

	'the upgrade needs manage_options' => static function () use ( $run ) {
		wp_set_current_user( 0 );
		delete_option( 'kwip_lock_upgrade' );
		update_option( 'kwip_db_version', 0 );
		KWIP_Upgrade::maybe_run();
		KT::same( 0, (int) get_option( 'kwip_db_version' ), 'a visitor cannot run the upgrade' );
	},
);