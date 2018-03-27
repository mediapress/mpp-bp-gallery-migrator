<?php

/**
 * Ajax Handler.
 */
class MPP_BP_Gallery_Migrator_Ajax_Handler {

	/**
	 * MPP_BP_Gallery_Migrator_Ajax_Handler constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_mpp_migrate_bp_gallery', array( $this, 'migrate' ) );

	}

	/**
	 * Migrates Media
	 */
	public function migrate() {

		if ( ! function_exists( 'bp_get_gallery' ) ) {
			wp_send_json( array(
				'error'   => 1,
				'message' => __( 'Please activate BP Gallery and try again!', 'mpp-bp-gallery-migrator' ),
			) );
		}

		$migrator = new  MPP_BP_Gallery_Migrator();
		// start migration.
		$migrated_id = $migrator->start();

		if ( $migrated_id && is_wp_error( $migrated_id ) ) {

			wp_send_json( array(
				'error'   => 1,
				'message' => $migrated_id->get_error_message(),
			) );
		}

		// if we are here, all is good, let us say that we have migrated.
		if ( $migrated_id && $migrated_id !== true ) {
			$migrated_count = $migrator->get_migrated_count();
			$total_count    = $migrator->get_total_count();

			wp_send_json( array(
				'success'   => 1,
				'message'   => sprintf( "=> Migrated Media ID: %d, %d of %d done", $migrated_id, $migrated_count, $total_count ),
				'remaining' => 1,
			) );
		}

		if ( $migrated_id && $migrated_id === true ) {

			// let us run the gallery activity migration for the last gallery.
			$migrator->migrate_gallery_activities( $migrator->get_last_migrated_gallery_id() );
			wp_send_json( array( 'success' => 1, 'message' => 'Migration complete', 'remaining' => 0 ) );

		}

		exit( 0 );
	}
}

new MPP_BP_Gallery_Migrator_Ajax_Handler();
