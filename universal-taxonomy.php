<?php

/*
Plugin Name: Universal Taxonomy
Plugin URI: http://github.com/
Description: Creates an "Index" site, and synchronises a taxonomy and the posts and pages from all other sites with this. Heavy hat tip to the Sitewide Tags plugin. May have issues with 100s of sites.
Network: true
Version: 1.0
Author: Simon Wheatley
Author URI: http://simonwheatley.co.uk//wordpress/
*/
 
/*  Copyright 2011 Simon Wheatley

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

// @TODO: Some method to stop updating terms from anything except the index site (seems tricky with existing hooks, see edit_terms method)
// @TODO: After WP 3.1: Maybe add a column to the post, page, etc editing lists which shows the Defra taxonomy terms for the post lists

require_once( 'plugin.php' );

/**
 * 
 * 
 * @package UniversalTaxonomy
 * @author Simon Wheatley
 **/
class UniversalTaxonomy extends UniversalTaxonomy_Plugin {

	/**
	 * An array of all IDs of all the sites in this network
	 *
	 * @var array
	 **/
	protected $all_site_ids;

	/**
	 * A boolean flag indicating whether we are in the process 
	 * of syncing a term between index and local sites.
	 *
	 * @var bool
	 **/
	protected $syncing_term;

	/**
	 * Initiate!
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function __construct() {
		$this->setup( 'uni_tax' );
		if ( is_admin() ) {
			$this->register_activation( __FILE__ );
			$this->add_action( 'admin_init' );
			$this->add_action( 'admin_notices' );
			$this->add_action( 'created_term', null, null, 3 );
			$this->add_action( 'delete_term_taxonomy' );
			$this->add_action( 'delete_term_taxonomy', 'term_delete_check_tt_id' );
			$this->add_action( 'edited_term', null, null, 3 );
			$this->add_action( 'edit_term_taxonomies', 'term_delete_check_tt_id' );
			$this->add_action( 'load-edit-tags.php', 'load_edit_terms' );
			$this->add_action( 'save_post', null, null, 2 );
			$this->add_action( 'wpmu_new_blog', 'new_blog', null, 6 );
			$this->add_filter( 'pre_insert_term', null, null, 2 );

			$this->all_site_ids = false;
			$this->syncing_term = false;
		}
		$this->add_filter( 'page_link', null, null, 2 );
		$this->add_filter( 'post_link', null, null, 2 );
	}

	/**
	 * Hooks the plugin activation function.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function activate() {
		global $wpdb, $current_site, $current_user;

		// Determine if we have an index blog, or if we need to
		// create one.
		$domain = $current_site->domain;
		$tags_blog = 'index';
		$path = trailingslashit( $current_site->path . $tags_blog );
		$index_blog_id = $wpdb->get_var( "SELECT blog_id FROM {$wpdb->blogs} WHERE domain = '$domain' AND path = '$path'" );
		if( $index_blog_id ) {
			$this->update_option( 'index_blog_id', $index_blog_id );
		} else {
			$wpdb->hide_errors();
			$index_blog_id = wpmu_create_blog( $domain, $path, __( 'Index','universal_taxonomy' ), $current_user->id , array( "public" => 1 ), $current_site->id );
			$this->update_option( 'index_blog_id', $index_blog_id );
			$wpdb->show_errors();
		}
	}
	
	// HOOKS AND ALL THAT
	// ==================

	/**
	 * Hooks the WP MS action wpmu_new_blog, which is fired when a new blog is
	 * created, in order to sync the terms across.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function new_blog( $new_blog_id, $user_id, $domain, $path, $site_id, $meta ) {
		global $wpdb;

		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return;

		$taxonomies = apply_filters( 'ut_synced_taxonomies', array() );
		$this->syncing_term = true;
		// Get the admin email now... is this pointful?
		$admin_email = get_option( 'admin_email' );
		foreach( $taxonomies as $taxonomy ) {
			$args = array( 'get' => 'all' );
			$terms = get_terms( $taxonomy, $args );
			$existing_parents = array();
			$needs_parents = array();
			switch_to_blog( $new_blog_id );
			// We need to make sure we only insert terms with parents after
			// those parents have been created.
			while( $terms ) {
				// Pop a term off the beginning of the array
				$term = array_shift( $terms );
				// Check we're not going round in circles too much
				if ( $term->retries > 3 ) {
					$site_name = get_bloginfo( 'name' );
					$site_url = home_url();
					$current_user = wp_get_current_user();
					// Send an email to the current user, and the admin user
					$to = ( $admin_email == $current_user->user_email ) ? $admin_email : "$admin_email, $current_user->user_email";
					$subject = "Error syncing taxonomies";
					$message = "Probable infinite loop in taxonomy '$taxonomy' to new site $site_name. \n\n Terms follow \n\n " . print_r( $terms, true );
					wp_mail( $to, $subject, $message );
					throw new exception( $message );
				}
				// Check if it needs a parent, and whether the parent exists
				if ( $term->parent && ! in_array( $this->get_local_term_id( $term->parent ), $existing_parents ) ) {
					// Note retry
					$term->retries = (int) $term->retries + 1;
					// No parent, push the term back to the end of the queue to retry later
					array_push( $terms, $term );
					$needs_parents[ $term->term_id ] = true;
					continue;
				}

				$term_name = $term->name;
				$args = array();
				$args[ 'slug' ] = $term->slug;
				if ( $term->description )
					$args [ 'description' ] = $term->description;
				if ( $term->parent ) {
					$args[ 'parent' ] = $this->get_local_term_id( $term->parent );
				}
				$result = wp_insert_term( $term_name, $taxonomy, $args );

				// If there's an error, warn the admin 
				if ( is_wp_error( $result ) ) {
					$site_name = get_bloginfo( 'name' );
					$site_url = home_url();
					$current_user = wp_get_current_user();
					// Send an email to the current user, and the admin user
					$to = ( $admin_email == $current_user->user_email ) ? $admin_email : "$admin_email, $current_user->user_email";
					$subject = "Error syncing taxonomies";
					$message = "Error syncing term '$term_name' in taxonomy '$taxonomy' to new site $site_name. \n\n Error follows \n\n " . print_r( $result, true );
					wp_mail( $to, $subject, $message );
					throw new exception( $message );
				} else {
					$this->set_local_term_id( $term->term_id, $result[ 'term_id' ] );
				}

				// Store that we have a new potential parent
				$existing_parents[] = $result[ 'term_id' ];
				if ( isset( $needs_parents[ $term->term_id ] ) )
					unset( $needs_parents[ $term->term_id ] );

				// We shouldn't have caching problems, what with it being a new 
				// site, but what the heck...
				$this->note_outdated_term_cache( $new_blog_id, $result[ 'term_id' ], $taxonomy );
			}
			restore_current_blog();
		}
	}

	/**
	 * Hooks the WP admin_init action to:
	 * * Check if there are any outdated taxonomy caches in this site, 
	 *   and update them
	 *
	 * @param  
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function admin_init() {
		$this->refresh_outdated_term_caches();
	}

	/**
	 * Hooks the WP admin_notices action to alert of any issues.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function admin_notices() {
		global $current_screen, $wpdb;
		
		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id && current_user_can( 'manage_options' ) )
			echo '<div class="error"><p>The Universal Taxonomy plugin has not activated properly, please de-activate and re-activate.</p></div>';
			
		if ( $index_blog_id == $wpdb->blogid && isset( $current_screen->post_type ) )
			echo '<div class="error"><p>WARNING: Any edits on this index site will be overwritten when the synced content on the other site(s) is updated.</p></div>';
	}

	/**
	 * Hooks the WP save_post action, fired after a post has been inserted/updated in the
	 * database, to duplicate the posts in the index site.
	 *
	 * @param int $orig_post_id The ID of the post being saved 
	 * @param object $orig_post A WP Post object of unknown type
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function save_post( $orig_post_id, $orig_post ) {
		global $wpdb;

		// Copy the post data into an array. We will now make
		// any comparisons back to the $orig_post object and
		// any alterations to this $post_data array.
		if ( ! is_object( $orig_post ) )
			return;
		$post_data = get_object_vars( $orig_post );

		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return;

		// Don't replicate posts on the index site onto the index site, as
		// that would be silly and result in infinite recursion.
		if ( $index_blog_id == $wpdb->blogid )
			return;

		// Check it's an allowed post type
		if ( ! in_array( $orig_post->post_type, apply_filters( 'ut_synced_post_types', array() ) ) )
			return;

		// Get some details for the reference post
		$orig_post_permalink = get_permalink( $orig_post_id );
		$orig_blog_id = $wpdb->blogid;

		// Store the post categories to add the categories to the index site later
		$post_data[ 'post_category' ] = wp_get_post_categories( $orig_post_id );
		foreach( $post_data[ 'post_category' ] as $c ) {
			$cat = get_category( $c );
			$cats[] = array( 'name' => wp_specialchars( $cat->name ), 'slug' => wp_specialchars( $cat->slug ) );
		}

		// Construct a unique GUID for the potential index post
		$post_data[ 'guid' ] = $orig_blog_id . '.' . $orig_post_id;
		$index_post_guid = $post_data[ 'guid' ];

		// No commenting or pinging on index posts
		$post_data[ 'ping_status' ] = 'closed';
		$post_data[ 'comment_status' ] = 'closed';

		switch_to_blog( $index_blog_id );

		// Do we already have this post in the index site?
		$prepared_sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE guid IN (%s,%s)", $index_post_guid, esc_url( $index_post_guid ) );
		$index_post_id = $wpdb->get_var( $prepared_sql );
		if ( $index_post_id )
			$index_post = get_post( $index_post_id );
		else 
			$index_post = false;
		
		// Delete the index post if the reference post is no longer published
		if ( is_object( $index_post ) && $orig_post->post_status != 'publish' )
			wp_delete_post( $index_post_id );
		
		// Update the post if it already exists
		// N.B. This conditional block must happen before inserting any new post below
		if ( is_object( $index_post ) && $orig_post->post_status == 'publish' ) {
			// Make sure we update the right post
			$post_data[ 'ID' ] = $index_post_id;
			wp_update_post( $post_data );
		}
		
		// // If the index post exists make sure the permalink data is up to date
		// N.B. This conditional block must happen before inserting any new post below
		if ( is_object( $index_post ) )
			update_post_meta( $index_post_id, "permalink", $orig_post_permalink );

		// Create an index post if the reference post is published, and 
		// there's no index post in the index site.
		if ( ! is_object( $index_post ) && $orig_post->post_status == 'publish' ) {
			// We must unset the ID, otherwise we'll end up trying to update a post
			// rather than insert one.
			unset( $post_data[ 'ID' ] );
			$index_post_id = wp_insert_post( $post_data );
			add_post_meta( $index_post_id, "blogid", $orig_blog_id ); // org_blog_id
			add_post_meta( $index_post_id, "permalink", $orig_post_permalink );
			// Clear the posts cache
			wp_cache_delete( $post_id, 'posts' );
			unset( $index_post );
			$index_post = get_post( $index_post_id );
		}
		
		restore_current_blog();

		// Now update the terms on the index post for the taxonomies we're 
		// tasked with syncing.
		if ( is_object( $index_post ) )
			$this->sync_taxonomies( $orig_post, $index_post );
	}
	
	/**
	 * Hook the WP created_term action to sync terms from the index site 
	 * out to other sites.
	 *
	 * @param int $term_id The term ID  
	 * @param int $tt_id The term taxonomy ID  
	 * @param string $taxonomy The name of the taxonomy the term belongs to
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function created_term( $term_id, $tt_id, $taxonomy ) {
		global $wpdb;

		// Check that the taxonomy is one we are syncing
		if ( ! in_array( $taxonomy, apply_filters( 'ut_synced_taxonomies', array() ) ) )
			return;

		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return;

		// Check we are on the index blog (otherwise don't sync)
		if ( $wpdb->blogid != $index_blog_id )
			return;

		$this->syncing_term = true;

		$orig_term = get_term( $term_id, $taxonomy );
		
		$this->insert_term_on_all_sites( $orig_term, $taxonomy );

		$this->syncing_term = false;
	}
	
	/**
	 * Hook the WP edited_term action to sync terms from the index site 
	 * out to other sites.
	 *
	 * @param int $term_id The term ID  
	 * @param int $tt_id The term taxonomy ID  
	 * @param string $taxonomy The name of the taxonomy the term belongs to
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function edited_term( $term_id, $tt_id, $taxonomy ) {
		global $wpdb;

		// Check that the taxonomy is one we are syncing
		if ( ! in_array( $taxonomy, apply_filters( 'ut_synced_taxonomies', array() ) ) )
			return;

		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return;

		// Check we are on the index blog (otherwise don't sync)
		if ( $wpdb->blogid != $index_blog_id )
			return;

		$this->syncing_term = true;

		$edited_term = get_term( $term_id, $taxonomy );
		
		$this->update_term_on_all_sites( $edited_term, $taxonomy );

		$this->syncing_term = false;
	}

	/**
	 * Hooks the WP delete_term_taxonomy action to delete terms from the 
	 * other sites when they are deleted from the index site.
	 *
	 * @param int $term The term_id of the term which is about to be deleted 
	 * @param int $tt_id The term taxonomy ID of the term which is about to be deleted
	 * @param string $taxonomy The name of the taxonomy from which the term is about to be deleted
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function delete_term_taxonomy( $tt_id ) {
		global $wpdb;

		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return;

		// Check we are on the index blog (otherwise don't sync)
		if ( $wpdb->blogid != $index_blog_id )
			return;

		$this->syncing_term = true;

		$result = $wpdb->get_row( $wpdb->prepare( " SELECT term_id, taxonomy FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d ", $tt_id ), ARRAY_A );
		extract( $result );

		// Check that the taxonomy is one we are syncing
		$taxonomies = apply_filters( 'ut_synced_taxonomies', array() );
		if ( ! in_array( $taxonomy, $taxonomies ) )
			return;

		$index_term = get_term( $term_id, $taxonomy );
		
		$site_ids = $this->get_all_site_ids();

		foreach ( $site_ids as $site_id ) {

			// Don't try and delete the term from the index site
			if ( $site_id == $this->get_option( 'index_blog_id' ) )
				continue;

			switch_to_blog( $site_id );

			$local_term_id = $this->get_local_term_id( $index_term->term_id );
			
			wp_delete_term( $local_term_id, $taxonomy );

			$this->delete_local_term_id( $index_term->term_id );
			
			$this->note_outdated_term_cache( $site_id, $local_term_id, $taxonomy );

			restore_current_blog();
		}

		$this->syncing_term = false;
	}

	/**
	 * Hooks the WP action load_edit_terms to stop loading the taxonomy
	 * screens, in any site other than index, for taxonomies which are being synced.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function load_edit_terms() {
		global $wpdb, $current_screen;
		
		// Allow screen to load if we have no setting for the index site ID
		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return;

		// Allow screen to load when we are using the index site
		if ( $wpdb->blogid == $index_blog_id )
			return;
			
		// Allow screen to load if we are not syncing that taxonomy
		$taxonomy = @ $_GET[ 'taxonomy' ];
		$taxonomies = apply_filters( 'ut_synced_taxonomies', array() );
		if ( ! in_array( $taxonomy, $taxonomies ) )
			return;

		// Allow terms to pass if they aren't in a taxonomy we're syncing
		$taxonomies = apply_filters( 'ut_synced_taxonomies', array() );
		if ( ! in_array( $taxonomy, $taxonomies ) )
			return;

		$this->no_editing_die( $current_screen->taxonomy );
		exit;
	}

	/**
	 * Hooks the WP pre_insert_term filter to stop any term insertions
	 * in the synced taxonomies on any site except the index site.
	 *
	 * @param string $term The term to be added or updated
	 * @param string $taxonomy The taxonomy to which to add the term
	 * @return string The term to be added or updated
	 * @author Simon Wheatley
	 **/
	public function pre_insert_term( $term, $taxonomy ) {
		global $wpdb;
		
		// Allow terms to pass if we have no setting for the index site ID
		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return $term;
			
		// Allow terms to pass when we are using the index site
		if ( $wpdb->blogid == $index_blog_id )
			return $term;
		
		// Allow terms to pass when we are syncing
		if ( $this->syncing_term )
			return $term;

		// Allow terms to pass if they aren't in a taxonomy we're syncing
		$taxonomies = apply_filters( 'ut_synced_taxonomies', array() );
		if ( ! in_array( $taxonomy, $taxonomies ) )
			return $term;
		
		$this->no_editing_die( $taxonomy );
		exit;
	}

	/**
	 * Hooks the WP edit_term_taxonomies action, which is called before relocating soon
	 * to be orphaned term children of the soon to be deleted term, and delete_term_taxonomy 
	 * action, which is called before deleting a term. Stops any term deletes in the 
	 * synced taxonomies on any site except the index site.
	 *
	 * @param int $tt_id The term_taxonomy_id to be deleted 
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function term_delete_check_tt_id( $tt_ids ) {
		global $wpdb;
		if ( empty( $tt_ids ) )
			return;
		$prepared_sql = $wpdb->prepare("SELECT term_id, taxonomy FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d", $tt_ids);
		$result = $wpdb->get_row( $prepared_sql );
		$term = get_term( $result->term_id, $result->taxonomy );
		
		// Allow terms to pass if we have no setting for the index site ID
		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return;
			
		// Allow terms to pass when we are using the index site
		if ( $wpdb->blogid == $index_blog_id )
			return;
		
		// Allow terms to pass when we are syncing
		if ( $this->syncing_term )
			return;

		// Allow terms to pass if they aren't in a taxonomy we're syncing
		$taxonomies = apply_filters( 'ut_synced_taxonomies', array() );
		if ( ! in_array( $taxonomy, $taxonomies ) )
			return;

		$this->no_editing_die( $result->taxonomy );
		exit;
	}

	/**
	 * Hooks the WP post_link filter to provide the original
	 * permalink (stored in post meta) when a permalink
	 * is requested from the index blog.
	 *
	 * @param string $permalink The permalink
	 * @param object $post A WP Post object 
	 * @return string A permalink
	 * @author Simon Wheatley
	 **/
	public function post_link( $permalink, $post ) {
		global $blog_id;

		// Only substitute the permalink if we're switched to the index blog
		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( $blog_id != $index_blog_id )
			return $permalink;

		if ( $original_permalink = get_post_meta( $post->ID, 'permalink', true ) )
			return $original_permalink;
		
		return $permalink;
	}
	
	/**
	 * Hooks the WP page_link filter to provide the original
	 * permalink (stored in post meta) when a page link is
	 * is requested from the index blog.
	 *
	 * @param string $permalink The page link
	 * @param int $post_id The ID of the post object 
	 * @return string A page link
	 * @author Simon Wheatley
	 **/
	public function page_link( $permalink, $post_id ) {
		global $blog_id;

		// Only substitute the permalink if we're switched to the index blog
		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( $blog_id != $index_blog_id )
			return $permalink;

		if ( $original_permalink = get_post_meta( $post_id, 'permalink', true ) )
			return $original_permalink;
		
		return $permalink;
	}

	// UTILITIES
	// =========
	
	/**
	 * Syncs the terms from the synced taxonomies from a post on the local 
	 * site to the equivalent post on the index site.
	 *
	 * Expects the switch_to_blog context to be the local, not the 
	 * index, site.
	 *
	 * @param object $orig_post The WP Post object we're copying from 
	 * @param object $orig_post The WP Post object we're copying to 
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function sync_taxonomies( $orig_post, $index_post ) {
		global $wpdb;
		// Check what taxonomies we are syncing, and bomb out if there's none
		$taxonomies = apply_filters( 'ut_synced_taxonomies', array() );
		if ( empty( $taxonomies ) )
			return;

		$index_blog_id = $this->get_option( 'index_blog_id' );
		if ( ! $index_blog_id )
			return;
		
		foreach ( $taxonomies as $taxonomy ) {
			$get_args = array(
				'fields' => 'ids',
			);
			$orig_term_ids = wp_get_object_terms( $orig_post->ID, $taxonomy, $get_args );

			// Match the original term IDs with index term IDs
			$index_term_ids = array();
			foreach ( $orig_term_ids as $orig_term_id ) {
				$index_term_id = $this->get_index_term_id( $orig_term_id );
				if ( $index_term_id !== false )
					$index_term_ids[] = $index_term_id;
			}

			switch_to_blog( $index_blog_id );

			wp_set_object_terms( $index_post->ID, $index_term_ids, $taxonomy );

			restore_current_blog();
		}
	}

	/**
	 * Creates a term on all sites (if it doesn't exist there yet).
	 *
	 * @param object A WP Term object 
	 * @param string $taxonomy The taxonomy to create the term in
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function insert_term_on_all_sites( $orig_term, $taxonomy ) {
		$site_ids = $this->get_all_site_ids();

		$term = $orig_term->name;

		$args = array();
		if ( $orig_term->description )
			$args [ 'description' ] = $orig_term->description;
		if ( $orig_term->slug )
			$args[ 'slug' ] = $orig_term->slug;

		foreach ( $site_ids as $site_id ) {
			// Don't sync the term back to the index site
			if ( $site_id == $this->get_option( 'index_blog_id' ) )
				continue;

			switch_to_blog( $site_id );

			if ( $orig_term->parent )
				$args[ 'parent' ] = $this->get_local_term_id( $orig_term->parent );

			$result = wp_insert_term( $term, $taxonomy, $args );
			if ( is_wp_error( $result ) )
				error_log( "Term Syncing Error: " . print_r( $result, true ) . " syncing $term to $taxonomy with args: " . print_r( $args, true ) );
			else
				$this->set_local_term_id( $orig_term->term_id, $result[ 'term_id' ] );

			$this->note_outdated_term_cache( $site_id, $result[ 'term_id' ], $taxonomy );

			restore_current_blog();
		}
	}

	/**
	 * Updates a term on all sites
	 *
	 * @param object A WP Term object 
	 * @param string $taxonomy The taxonomy to create the term in
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function update_term_on_all_sites( $orig_term, $taxonomy ) {
		$site_ids = $this->get_all_site_ids();

		$args = array();
		$args[ 'name' ] = $orig_term->name;
		if ( $orig_term->description )
			$args [ 'description' ] = $orig_term->description;
		if ( $orig_term->slug )
			$args[ 'slug' ] = $orig_term->slug;

		foreach ( $site_ids as $site_id ) {
			// Don't sync the term back to the index site
			if ( $site_id == $this->get_option( 'index_blog_id' ) )
				continue;

			switch_to_blog( $site_id );

			$local_term_id = $this->get_local_term_id( $orig_term->term_id );

			if ( $orig_term->parent )
				$args[ 'parent' ] = $this->get_local_term_id( $orig_term->parent );
			else
				$args[ 'parent' ] = false;

			$result = wp_update_term( $local_term_id, $taxonomy, $args );
			if ( is_wp_error( $result ) )
				error_log( "Term Syncing Error: " . print_r( $result, true ) . " updating $term on $taxonomy with args: " . print_r( $args, true ) );

			$this->note_outdated_term_cache( $site_id, $local_term_id, $taxonomy );

			restore_current_blog();
		}
	}
	
	/**
	 * Returns the term_id, in the local/current (non-index) site, which
	 * corresponds with a term_id from the index site. Assumes that 
	 * the site has been switched to, and that any option cache/object 
	 * cache issues have been resolved (http://core.trac.wordpress.org/ticket/14992).
	 *
	 * @param int $index_term_id The term_id from the index site to reference 
	 * @return int A term_id from the remote (non-index) site
	 * @author Simon Wheatley
	 **/
	protected function get_local_term_id( $index_term_id ) {
		$map = get_option( 'universal_taxonomy_map', array() );
		if ( ! isset( $map[ $index_term_id ] ) )
			return false;
		return $map[ $index_term_id ];
	}
	
	/**
	 * Returns the term_id, in the index site which corresponds with a 
	 * term_id from the local site. Assumes that the index site has 
	 * been switched to, and that any option cache/object cache issues 
	 * have been resolved (http://core.trac.wordpress.org/ticket/14992).
	 *
	 * @param int $local_term_id The term_id from the local site to reference 
	 * @return int A term_id from the index site
	 * @author Simon Wheatley
	 **/
	protected function get_index_term_id( $local_term_id ) {
		$map = get_option( 'universal_taxonomy_map', array() );
		return array_search( $local_term_id, $map );
	}
	
	/**
	 * Stores which term_id in a local/current (non-index) site corresponds
	 * to a term_id from the index site. Assumes that the site has been 
	 * switched to, and that any option cache/object cache issues have 
	 * been resolved (http://core.trac.wordpress.org/ticket/14992).
	 *
	 * @param int $index_term_id The term_id from the index site to reference 
	 * @return int A term_id from the remote (non-index) site
	 * @author Simon Wheatley
	 **/
	protected function set_local_term_id( $index_term_id, $local_term_id ) {
		$map = get_option( 'universal_taxonomy_map', array() );
		$map[ $index_term_id ] = $local_term_id;
		update_option( 'universal_taxonomy_map', $map );
	}
		
	/**
	 * Deletes a term_id from the local to index site map. Assumes that the 
	 * site has been switched to, and that any option cache/object cache 
	 * issues have been resolved (http://core.trac.wordpress.org/ticket/14992).
	 *
	 * @param int $index_term_id The term_id from the index site to reference 
	 * @return int A term_id from the remote (non-index) site
	 * @author Simon Wheatley
	 **/
	protected function delete_local_term_id( $index_term_id ) {
		$map = get_option( 'universal_taxonomy_map', array() );
		unset( $map[ $index_term_id ] );
		update_option( 'universal_taxonomy_map', $map );
	}

	/**
	 * Gets all the sites in this network.
	 *
	 * @FIXME: This may not cope with a very large (100s of sites) network
	 *
	 * @return array An array of information about the sites in the network
	 * @author Simon Wheatley
	 **/
	protected function get_all_site_ids() {
		global $wpdb;
		if ( ! $this->all_site_ids )
			$this->all_site_ids = $wpdb->get_col( " SELECT blog_id FROM {$wpdb->blogs} " );
		return $this->all_site_ids;
	}

	/**
	 * Notes that this site has an outdated term cache. Schedules it for 
	 * clearing when that site is next loaded.
	 *
	 * @param int $site_id The ID of the site that this applies to 
	 * @param int $term_id The term_id we changed
	 * @param string $taxonomy The taxonomy the term is within
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function note_outdated_term_cache( $site_id, $term_id, $taxonomy ) {
		$outdated_caches = $this->get_option( 'outdated_caches', array() );
		if ( ! isset( $outdated_caches[ $site_id ] ) )
			$outdated_caches[ $site_id ] = array();
		if ( ! isset( $outdated_caches[ $site_id ][ $taxonomy ] ) )
			$outdated_caches[ $site_id ][ $taxonomy ] = array();
		$outdated_caches[ $site_id ][ $taxonomy ][] = $term_id;
		$this->update_option( 'outdated_caches', $outdated_caches );
	}
	
	/**
	 * Checks for any taxonomies and term_ids therein which have been 
	 * marked as having outdated caches. Runs clean_term_cache on them.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function refresh_outdated_term_caches() {
		global $wpdb;
		$outdated_caches = $this->get_option( 'outdated_caches', array() );

		if ( ! isset( $outdated_caches[ $wpdb->blogid ] ) )
			return;

		foreach ( $outdated_caches[ $wpdb->blogid ] as $taxonomy => $term_ids )
			clean_term_cache( $term_ids, $taxonomy );
			
		unset( $outdated_caches[ $wpdb->blogid ] );

		$this->update_option( 'outdated_caches', $outdated_caches );
	}

	/**
	 * Gets the value of a *site* option named as per this plugin.
	 *
	 * @return mixed Whatever 
	 * @author Simon Wheatley
	 **/
	protected function get_all_options() {
		return get_site_option( $this->name );
	}
	
	/**
	 * Sets the value of a *site* option named as per this plugin.
	 *
	 * @return mixed Whatever 
	 * @author Simon Wheatley
	 **/
	protected function update_all_options( $value ) {
		return update_site_option( $this->name, $value );
	}
	
	/**
	 * Gets the value from an array index on a *site* (i.e. network wide) option named as per this plugin.
	 *
	 * @param string $key A string 
	 * @return mixed Whatever 
	 * @author Simon Wheatley
	 **/
	public function get_option( $key ) {
		// I'm forcing WP to NOT use the cache as object caching is messing with 
		// our minds  (possibly: http://core.trac.wordpress.org/ticket/14992).
		$option = get_site_option( $this->name, null, false );
		if ( ! is_array( $option ) || ! isset( $option[ $key ] ) )
			return null;
		return $option[ $key ];
	}
	
	/**
	 * Sets the value on an array index on a *site* (i.e. network wide) option named as per this plugin.
	 *
	 * @param string $key A string 
	 * @param mixed $value Whatever
	 * @return bool False if option was not updated and true if option was updated.
	 * @author Simon Wheatley
	 **/
	protected function update_option( $key, $value ) {
		$option = get_site_option( $this->name );
		$option[ $key ] = $value;
		return update_site_option( $this->name, $option );
	}
	
	/**
	 * Deletes the array index on a *site* (i.e. network wide) option named as per this plugin.
	 *
	 * @param string $key A string 
	 * @return bool False if option was not updated and true if option was updated.
	 * @author Simon Wheatley
	 **/
	protected function delete_option( $key ) {
		$option = get_site_option( $this->name );
		if ( isset( $option[ $key ] ) )
			unset( $option[ $key ] );
		return update_site_option( $this->name, $option );
	}

	/**
	 * Constructs and returns a no editing error message for users.
	 *
	 * @return string The no editing error message for users.
	 * @author Simon Wheatley
	 **/
	protected function no_editing_message( $taxonomy ) {
		$index_blog_id = $this->get_option( 'index_blog_id' );
		switch_to_blog( $index_blog_id );
		$edit_url = esc_attr( admin_url( "/edit-tags.php?taxonomy=$taxonomy" ) );
		restore_current_blog();
		return "Please do not edit this taxonomy on this site, use the <a href='$edit_url'>index site editing screen</a>.";
	}
	
	/**
	 * Determines whether we are AJAX or regular HTML form submission, and 
	 * dies in an appropriate way with a helpful message.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	protected function no_editing_die( $taxonomy ) {
		if ( defined( 'DOING_AJAX' ) ) {
			$x = new WP_Ajax_Response();
			$x->add( array(
				'what' => 'taxonomy',
				'data' => new WP_Error('error', $this->no_editing_message( $taxonomy ) )
			) );
			$x->send();
			exit; // Pure paranoia, the send method should end with die().
		}

		wp_die( $this->no_editing_message( $taxonomy ) );
	}

} // END UniversalTaxonomy class 

$universal_taxonomy = new UniversalTaxonomy();

?>