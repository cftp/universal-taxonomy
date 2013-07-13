<?php

/*
Plugin Name: Universal Taxonomy: Fix Duplicates in Index
Plugin URI: http://simonwheatley.co.uk/wordpress/ut-fdii
Description: Fixes duplicates in the Index site caused by escaping of GUIDs brought in in WP 3.1.3
Version: 0.2
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

require_once( 'plugin.php' );

/**
 * 
 * 
 * @package UTFixDupes
 * @author Simon Wheatley
 **/
class UTFixDupes extends UniversalTaxonomy_Plugin {
	

	/**
	 * Initiate!
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function __construct() {
		$this->setup( 'utfdiii' );
		if ( is_admin() ) {
			$this->add_action( 'admin_menu' );
			$this->add_action( 'load-tools_page_utfdii', 'load_admin_page' );
		}
	}
	
	/**
	 * Adds an admin page.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	function admin_menu() {
		add_management_page( 'Fix Duplicates', 'Fix Duplicates', 'edit_posts', 'utfdii', array( $this, 'admin_page' ) );
	}

	/**
	 * On load of the admin page.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function load_admin_page() {
		$do_something = @ (bool) $_POST[ '_utfdii_nonce' ];
		if ( ! $do_something )
			return;
		check_ajax_referer( 'utfdii', '_utfdii_nonce' );
		
		$duplicates = (array) @ $_POST[ 'duplicates' ];
		foreach ( $duplicates as $post_ID ) {
			$res = wp_delete_post( $post_ID, true );
			if ( $res === false )
				$this->set_admin_error( "Failed to delete duplicate post, ID [$post_ID]." );
			else
				$this->set_admin_notice( "Deleted duplicate post, ID [$post_ID]." );
		}
		wp_redirect( admin_url( 'tools.php?page=utfdii' ) );
		exit;
	}

	/**
	 * Callback function providing HTML for the admin page.
	 *
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function admin_page() {
		global $wpdb;
		echo "<div class='wrap'><form action='' method='POST'>";
		wp_nonce_field( 'utfdii', '_utfdii_nonce' );
		echo "<h2>Fix Duplicates</h2>";

		$sql = "SELECT meta_value, COUNT(*) AS num FROM $wpdb->postmeta, $wpdb->posts WHERE $wpdb->posts.ID = $wpdb->postmeta.post_ID AND $wpdb->posts.post_status = 'publish' AND meta_key = 'permalink' GROUP BY meta_value HAVING num > 1";
		$permalinks = $wpdb->get_col( $sql );
		echo "<ol>";
		$args = array( 
			'post_type' => 'any',
			'orderby' => 'modified',
			'order' => 'desc',
			'meta_query' => array(
				array(
					'key' => 'permalink',
				),
			),
		);
		foreach ( $permalinks as $permalink ) {
			echo "<li>";
			$args[ 'meta_query' ][ 0 ][ 'value' ] = $permalink;
			// var_dump( $args );
			echo "<p><strong>$permalink</strong></p>";
			echo "<ul>";
			$dupes = new WP_Query( $args );
			// echo "<p>$dupes->request</p>";
			while ( $dupes->have_posts() ) {
				$dupes->the_post();

				// Work out Defra taxonomy cats
				$terms = get_the_terms( get_the_ID(), 'defra' );
				if ( ! empty( $terms ) ) {
					$post = get_post( get_the_ID() );
					$out = array();
					foreach ( $terms as $c ) {
						$out[] = sprintf( '<a href="%s">%s</a>',
							esc_url( add_query_arg( array( 'post_type' => $post->post_type, 'defra' => $c->slug ), 'edit.php' ) ),
							esc_html( sanitize_term_field( 'name', $c->name, $c->term_id, 'terms', 'display' ) )
						);
					}
					$cats = join( ', ', $out );
				} else {
					$cats = 'No categories';
				}

				echo "<li><label><input type='checkbox' name='duplicates[]' value='" . get_the_ID() . "' /> Index ID: " . get_the_ID() . ", Date: " . get_post_field( 'post_modified_gmt', get_the_ID() ) . ", &quot;" . get_post_field( 'post_title', get_the_ID() ) . "&quot;</label>, Cats: " . $cats . "</li>";
			}
			echo "</ul></li>";
		}
		echo "</ol>";
		echo "<hr /><p><em>Make sure you leave one post unchecked in each batch of duplicates.</em></p><p><input type='submit' class='button-primary' value='Delete Checked Posts from Index' /></p></form>";

		echo "</div>";
	}

} // END UTFixDupes class 

$ut_fix_dupes = new UTFixDupes();


?>