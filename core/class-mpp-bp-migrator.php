<?php
/**
 * Migrator class to migrating BP Gallery Media/Gallery to MediaPress Gallery
 */
class MPP_BP_Gallery_Migrator {

	/**
	 * BP Gallery Gallery table.
	 *
	 * @var string
	 */
	private $table_gallery;

	/**
	 * BP Gallery Media Table.
	 *
	 * @var string
	 */
	private $table_media;

	/**
	 * MPP_BP_Gallery_Migrator constructor.
	 */
	public function __construct() {
		$this->table_gallery = buddypress()->gallery->table_galleries_data;
		$this->table_media   = buddypress()->gallery->table_media_data;
	}

	/**
	 * Call it to start migration
	 *
	 * @return boolean|\WP_Error
	 */
	public function start() {
		// most probably BP Gallery is not active.
		if ( empty( $this->table_media ) ) {
			return new WP_Error( __( 'BP Gallery is not active' ) );
		}

		global $wpdb;

		$last_media_id = $this->get_last_migrated_media_id();

		$next_query = "SELECT id FROM {$this->table_media} WHERE id > %d ORDER BY id ASC LIMIT 0, 1 ";

		$next_media_id = $wpdb->get_var( $wpdb->prepare( $next_query, $last_media_id ) );

		// if running for the first time, save the max.
		if ( ! $last_media_id ) {
			$max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$this->table_media} " );
			$this->save_max_media_id( $max_id );

			$total = (int) $wpdb->get_var( "SELECT COUNT('*') FROM {$this->table_media} " );
			$this->update_total_count( $total );

		}

		// last media id with largest id in the bp gallery media table.
		$max_id = $this->get_max_media_id();
		// migration complete.
		if ( $last_media_id == $max_id ) {
			return true;// migration complete.
		}


		if ( $next_media_id && $max_id >= $next_media_id ) {

			$migrated = $this->migrate_media( $next_media_id );

			if ( $migrated && ! is_wp_error( $migrated ) ) {
				$this->save_last_migrated_media_id( $next_media_id );
			}

			return $migrated;// id of media.

		}

		return false;// there was a problem.
	}

	/**
	 *  Migrate a Media.
	 *
	 * @param int $media_id BP Gallery Media ID to migrate.
	 *
	 * @return false|WP_Error on failure, mpp media id on success.
	 */
	private function migrate_media( $media_id ) {
		// We have this BP Gallery Media.
		// We need to migrate it to MediaPress Media, what we need?
		$media = bp_gallery_get_media( $media_id );
		// not a valid media?
		if ( empty( $media ) ) {
			return new WP_Error( __( 'Invalid Media', 'mpp-bp-gallery-migrator' ) );
		}

		// skip media if remote, mpp gallery does not have an equivalent.
		if ( $media->is_remote ) {
			// skip remote media, we don't have an equivalent in MediaPress at the moment.
			return new WP_Error( __( 'Remote media can not be migrated, skipping' ) );
		}

		$gallery_id          = $media->gallery_id;
		$migrated_gallery_id = $this->get_last_migrated_gallery_id();
		// check if it is a change in gallery, if yes, we need to migrate activities.
		if ( $migrated_gallery_id && $gallery_id != $migrated_gallery_id ) {

			// we are trying to migrate media from new gallery, let us migrate all activities too.
			$this->migrate_gallery_activities( $migrated_gallery_id );
			// now continue.
		}

		$mpp_gallery_id = $this->get_mpp_gallery_id( $gallery_id );

		if ( empty( $mpp_gallery_id ) ) {
			// we need to migrate this gallery too.
			$mpp_gallery_id = $this->migrate_gallery( $gallery_id );

			if ( ! $mpp_gallery_id || is_wp_error( $mpp_gallery_id ) ) {
				return new WP_Error( __( 'Gallery creation failed', 'mpp-bp-gallery-migrator' ) );
			}
		}

		$gallery = bp_get_gallery( $gallery_id );
		// move files.
		$uploaded = $this->move_files( $media, $gallery );
		// migrate media.
		if ( empty( $uploaded ) ) {
			return new WP_Error( __( 'Unable to move files', 'mpp-bp-gallery-migrator' ) );
		}

		$url  = $uploaded['url'];
		$type = $uploaded['type'];
		$file = $uploaded['file'];

		$status       = $this->get_new_status( $media->status );
		$component    = $this->get_new_component( $gallery->owner_object_type );
		$mpp_media_id = mpp_add_media( array(
			'title'          => $media->title,
			'description'    => $media->description,
			'user_id'        => $media->user_id,
			'gallery_id'     => $mpp_gallery_id,
			'post_parent'    => $mpp_gallery_id,
			'is_orphan'      => 0, // not orphan.
			'is_uploaded'    => 1,
			'is_remote'      => 0,
			'is_imorted'     => 1,
			'mime_type'      => $type,
			'src'            => $file,
			'url'            => $url,
			'component_id'   => $gallery->owner_object_id,
			'component'      => $component,
			'context'        => 'gallery',
			'status'         => $status,
			'type'           => $media->type,
			'storage_method' => 'local',
			'sort_order'     => 0, // sort order.
			'date_created'   => $media->date_updated,
			'date_updated'   => $media->date_updated,
		) );

		if ( $mpp_media_id ) {
			mpp_gallery_increment_media_count( $mpp_gallery_id );
			$this->update_migrated_count();
			bp_update_media_meta( $media->id, '_mpp_media_id', $mpp_media_id );
			$this->migrate_media_activities( $media->id );
		}

		return $mpp_media_id;

	}


	/**
	 * Migrate a BP Gallery to MediaPress gallery
	 *
	 * @param int $bp_gallery_id id.
	 *
	 * @return boolean
	 */
	public function migrate_gallery( $bp_gallery_id ) {

		$gallery = bp_get_gallery( $bp_gallery_id );

		if ( empty( $gallery ) ) {
			return false;
		}


		$new_status    = $this->get_new_status( $gallery->status );
		$new_component = $this->get_new_component( $gallery->owner_object_type );

		$new_gallery_id = mpp_create_gallery( array(
			'creator_id'   => $gallery->creator_id,
			'title'        => $gallery->title,
			'description'  => $gallery->description,
			'slug'         => $gallery->slug,
			'status'       => $new_status,
			'order'        => false,
			'component'    => $new_component,
			'component_id' => $gallery->owner_object_id,
			'type'         => $gallery->gallery_type,
			'date_created' => gmdate( 'Y-m-d H:i:s', $gallery->date_created ),
			'date_updated' => gmdate( 'Y-m-d H:i:s', $gallery->date_updated ),
		) );

		if ( $new_gallery_id && ! is_wp_error( $new_gallery_id ) ) {
			$this->save_mpp_gallery_id( $bp_gallery_id, $new_gallery_id );
			$this->save_last_migrated_gallery_id( $bp_gallery_id );
		}

		return $new_gallery_id;

	}

	/**
	 * Move the physical file.
	 *
	 * @param BP_Gallery_Media $media Bp Gallery Media object.
	 * @param BP_Gallery_Gallery $gallery Bp Gallery gallery object.
	 *
	 * @return bool|mixed
	 */
	public function move_files( $media, $gallery ) {
		$component    = $this->get_new_component( $gallery->owner_object_type );
		$component_id = $gallery->owner_object_id;
		// check if original image is there.
		$image = bp_get_media_meta( $media->id, "original_image" );

		if ( ! $image ) {
			$image = $media->local_orig_path;
		}

		if ( ! $image ) {
			return false;// no image to process.
		}


		$image = ABSPATH . $image;

		if ( ! file_exists( $image ) ) {
			$image = $this->guess_file_name( $image );
		}

		if ( ! $image ) {
			return false;
		}

		$file_name = wp_basename( $image );

		$storage_manager = mpp_local_storage();
		$uploads         = $storage_manager->get_upload_dir( array(
			'component'    => $component,
			'component_id' => $component_id,
			'gallery_id'   => $this->get_mpp_gallery_id( $gallery->id ),
		) );


		$filename = wp_unique_filename( $uploads['path'], $file_name, null );

		// Move the file to the uploads dir.
		$new_file = $uploads['path'] . "/$filename";

		if ( ! file_exists( $uploads['path'] ) ) {
			wp_mkdir_p( $uploads['path'] );
		}

		if ( false === copy( $image, $new_file ) ) {

			if ( 0 === strpos( $uploads['basedir'], ABSPATH ) ) {
				$error_path = str_replace( ABSPATH, '', $uploads['basedir'] ) . $uploads['subdir'];
			} else {
				$error_path = basename( $uploads['basedir'] ) . $uploads['subdir'];
			}

			return false;// there was an error.
		}

		// Set correct file permissions.
		$stat  = stat( dirname( $new_file ) );
		$perms = $stat['mode'] & 0000666;
		@ chmod( $new_file, $perms );

		// Compute the URL.
		$url = $uploads['url'] . "/$filename";

		$type = mime_content_type( $new_file );

		return apply_filters( 'mpp_handle_upload', array(
			'file' => $new_file,
			'url'  => $url,
			'type' => $type,
		), 'upload' );
	}

	/**
	 * Guess file name.
	 *
	 * @param string $name file name for bp gallery.
	 *
	 * @return string
	 */
	private function guess_file_name( $name ) {
		$pathinfo  = pathinfo( $name );
		$extension = isset( $pathinfo['extension'] ) ? $pathinfo['extension'] : '';

		if ( ! $extension ) {
			return '';
		}

		$pattern = '/^(.*)-[0-9]*x[0-9]*\.' . $extension . '$/';

		$matches = array();
		preg_match_all( $pattern, $name, $matches );

		if ( ! empty( $matches[1] ) ) {
			return $matches[1][0] . '.' . $extension;
		}

		return '';
	}

	/**
	 * Migrate activities for gallery.
	 *
	 * @param int $bp_gallery_id BP Gallery Gallery id.
	 *
	 * @return bool
	 */
	public function migrate_gallery_activities( $bp_gallery_id ) {

		if ( ! function_exists( 'bp_activity_get_meta' ) ) {
			return false;
		}

		global $wpdb;

		$activity_ids = $wpdb->get_col( $wpdb->prepare( "SELECT activity_id FROM " . buddypress()->activity->table_name_meta . " WHERE meta_key='associated_gallery_id' AND meta_value = %d", $bp_gallery_id ) );

		if ( empty( $activity_ids ) ) {
			return true;
		}

		$gallery_id = $this->get_mpp_gallery_id( $bp_gallery_id );
		$gallery    = mpp_get_gallery( $gallery_id );
		$type       = $gallery->type;


		$user_link    = mpp_get_user_link( $gallery->user_id );// it may not be right for groups.
		$gallery_url  = mpp_get_gallery_permalink( $gallery );
		$gallery_link = '<a href="' . esc_url( $gallery_url ) . '" title="' . esc_attr( $gallery->title ) . '">' . mpp_get_gallery_title( $gallery ) . '</a>';

		foreach ( $activity_ids as $activity_id ) {

			$media_ids   = bp_activity_get_meta( $activity_id, 'associated_media', true );
			$media_ids   = $this->map_media_id( $media_ids );
			$media_count = count( $media_ids );

			$type_name = _n( $type, $type . 's', $media_count );

			$activity_id = mpp_gallery_record_activity( array(
				'id'           => $activity_id,
				'gallery_id'   => $gallery_id,
				'media_ids'    => $media_ids,
				'user_id'      => $gallery->user_id,
				'component'    => $gallery->component,
				'component_id' => $gallery->component_id,
				'type'         => 'media_publish',
				'action'       => sprintf( __( '%s shared %d %s to %s ', 'mediaprses' ), $user_link, $media_count, $type_name, $gallery_link ),
				'content'      => '',
			) );
		}

		return true;
	}

	/**
	 * Migrate Media ctivities.
	 *
	 * @param int $bp_gallery_media_id BP Gallery Media id.
	 *
	 * @return bool
	 */
	public function migrate_media_activities( $bp_gallery_media_id ) {

		if ( ! function_exists( 'bp_activity_get_meta' ) ) {
			return false;
		}

		$activity_id = bp_get_media_meta( $bp_gallery_media_id, 'published_activity_id', true );

		if ( ! $activity_id ) {
			return true;// success, no activity to migrate.
		}

		if ( bp_activity_get_meta( $activity_id, 'associated_gallery_id', true ) ) {
			return true;// it is gallery activity not media activity.
		}

		$media_id = $this->get_migrated_media_id( $bp_gallery_media_id );

		$media = mpp_get_media( $media_id );

		$user_link = mpp_get_user_link( $media->user_id );

		$link = mpp_get_media_permalink( $media );

		mpp_media_record_activity( array(
			'id'           => $activity_id,
			'media_id'     => $media_id,
			'type'         => 'add_media',
			'component'    => $media->component,
			'component_id' => $media->component_id,
			'user_id'      => $media->user_id,
			'content'      => '',
			'action'       => sprintf( __( '%s added a new <a href="%s">%s</a>', 'mediapress' ), $user_link, $link, $media->type ),
		) );

		return true;
	}


	// in case of bp gallery the only status that needs to be migrated is membersonly.
	public function get_new_status( $old_status ) {

		if ( $old_status == 'membersonly' ) {
			$old_status = 'loggedin';
		}

		return $old_status;
	}

	/**
	 * Get the new component type based on the old type from Bp Gallery.
	 *
	 * @param string $component_type old component type.
	 *
	 * @return string
	 */
	public function get_new_component( $component_type ) {

		if ( $component_type == 'user' ) {
			return 'members';
		} elseif ( $component_type == 'group' ) {
			return 'groups';
		}

		return $component_type;
	}


	public function map_media_id( $bp_gallery_media_ids ) {
		// maps media ids to mpp media ids.
		if ( empty( $bp_gallery_media_ids ) ) {
			return array();
		}

		$list = '(' . join( ',', $bp_gallery_media_ids ) . ')';
		global $wpdb;

		$table_media_meta = buddypress()->gallery->table_media_meta;

		$media_ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$table_media_meta} WHERE media_id IN {$list} AND meta_key = %s", '_mpp_media_id' ) );

		return $media_ids;
	}

	private function save_mpp_gallery_id( $gallery_id, $mpp_id ) {
		return bp_update_gallery_meta( $gallery_id, '_migrated_id', $mpp_id );
	}

	public function get_migrated_media_id( $bp_media_id ) {
		return bp_get_media_meta( $bp_media_id, '_mpp_media_id', true );
	}

	/**
	 * Get the MediaPress gallery id to which this Bp Gallery was migrated
	 *
	 * @param int $gallery_id
	 *
	 * @return int
	 */

	public function get_mpp_gallery_id( $gallery_id ) {
		return bp_get_gallery_meta( $gallery_id, '_migrated_id' );
	}

	public function save_last_migrated_media_id( $media_id ) {
		return update_site_option( '_mpp_migrated_bpg_media_id', $media_id );
	}

	public function save_max_media_id( $media_id ) {
		return update_site_option( '_mpp_migrated_bpg_max_media_id', $media_id );
	}

	public function save_last_migrated_gallery_id( $gallery_id ) {
		return update_site_option( '_mpp_migrated_bpg_gallery_id', $gallery_id );
	}

	public function get_last_migrated_media_id() {
		return get_site_option( '_mpp_migrated_bpg_media_id', 0 );
	}

	public function get_last_migrated_gallery_id() {
		return get_site_option( '_mpp_migrated_bpg_gallery_id', 0 );
	}

	public function get_max_media_id() {
		return get_site_option( '_mpp_migrated_bpg_max_media_id', 0 );
	}

	public function update_migrated_count() {
		$count = $this->get_migrated_count();
		update_site_option( '_bpg_migrated_media_count', $count + 1 );
	}

	public function get_migrated_count() {
		return get_site_option( '_bpg_migrated_media_count', 0 );
	}

	public function get_total_count() {
		return get_site_option( '_bpg_total_media_count', 0 );
	}

	public function update_total_count( $total ) {
		return update_site_option( '_bpg_total_media_count', $total );
	}
}