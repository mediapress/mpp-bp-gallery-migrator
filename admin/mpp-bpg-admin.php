<?php

/**
 * Migrator Admin Page.
 */
class MPP_BP_Gallery_Migrator_Admin {

	/**
	 * MPP_BP_Gallery_Migrator_Admin constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_js' ) );
	}

	/**
	 * Add Menu.
	 */
	public function add_menu() {
		add_submenu_page( mpp_admin()->get_menu_slug(), __( 'BP Gallery Migrator', 'mpp-bp-gallery-migrator' ), __( 'BP Gallery Migrator', 'mpp-bp-gallery-migrator' ), 'manage_options', 'bp-gallery-migrator', array(
			$this,
			'view',
		) );
	}

	/**
	 * Load js on admin page.
	 */
	public function load_js() {
		wp_enqueue_script( 'mpp-bp-gallery-migrator', plugin_dir_url( __FILE__ ) . 'mpp-bpg-admin.js', array( 'jquery' ) );
	}

	/**
	 * Render admin view.
	 */
	public function view() {

		?>

        <div class='wrap'>
            <h2><?php _e( 'Migrate Old BP Gallery data to MediaPress Now', 'mpp-bp-gallery-migrator' );?></h2>

            <a href="#" class='button button-secondary' id='mpp-bp-gallery-start-migration'><?php _e( 'Start Migration', 'mpp-bp-gallery-migrator' );?></a>

            <div class='clear'></div>

            <div id='mpp-bp-gallery-migration-log'>
            </div>
        </div>

        <style type='text/css'>

            #mpp-bp-gallery-migration-log {
                background: #ccc;
                color: #333;
                padding: 10px;
                border: 1px solid #333;
            }

            #mpp-bp-gallery-migration-log p {
                font-size: 13px;
                font-weight: bold;
                margin-bottom: 10px;
            }

        </style>
		<?php
	}

}

new MPP_BP_Gallery_Migrator_Admin();
