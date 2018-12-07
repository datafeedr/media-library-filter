<?php
/*
Plugin Name: Media Library Filter
Description: Filter the media in your library by the post type and taxonomy of which they are associated.
Author: datafeedr.com
Author URI: http://www.datafeedr.com
Plugin URI: http://www.datafeedr.com
License: GPLv2 or later
Requires at least: 4.4
Tested up to: 5.0
Version: 1.0.3

Datafeedr API Plugin
Copyright (C) 2019, Datafeedr - help@datafeedr.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define constants.
 */
define( 'MLF_VERSION', '1.0.3' );
define( 'MLF_URL', plugin_dir_url( __FILE__ ) );
define( 'MLF_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLF_BASENAME', plugin_basename( __FILE__ ) );
define( 'MLF_DOMAIN', 'media-library-filter' );

/**
 * Fires when the plugin is activated.
 *
 * @since 1.0.0
 */
register_activation_hook( __FILE__, 'mlf_activate' );
function mlf_activate() {
	/**
	 * This should be reviewed and possibly written in pure MySQL so as to create a unique index name.
	 *
	 * global $wpdb;
	 * require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
	 * add_clean_index( $wpdb->term_taxonomy, 'term_id' );
	 * add_clean_index( $wpdb->posts, 'post_type' );
	 * add_clean_index( $wpdb->posts, 'post_status' );
	 */
}

/**
 * Fires when the plugin is deactivated.
 *
 * @since 1.0.0
 */
register_deactivation_hook( __FILE__, 'mlf_deactivate' );
function mlf_deactivate() {
	/**
	 * This should be reviewed and possibly written in pure MySQL so as to create a unique index name.
	 *
	 * global $wpdb;
	 * require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
	 * drop_index( $wpdb->term_taxonomy, 'term_id' );
	 * drop_index( $wpdb->posts, 'post_type' );
	 * drop_index( $wpdb->posts, 'post_status' );
	 */
}

/**
 * Add Javascript to upload.php file.
 *
 * @since 1.0.0
 */
add_action( 'admin_enqueue_scripts', 'mlf_admin_enqueue_scripts' );
function mlf_admin_enqueue_scripts( $hook ) {
	if ( 'upload.php' != $hook ) {
		return;
	}

	wp_enqueue_script( 'mlf_javascript', plugin_dir_url( __FILE__ ) . 'js/mlf.js', array( 'jquery' ) );
}

/**
 * Add link to upload.php?mode=list to plugin action links.
 *
 * @since 1.0.0
 */
add_filter( 'plugin_action_links_' . MLF_BASENAME, 'mlf_action_links' );
function mlf_action_links( $links ) {
	return array_merge(
		$links,
		array(
			'filtermedia' => '<a href="' . add_query_arg( array( 'mode' => 'list' ),
					admin_url( 'upload.php' ) ) . '">' . __( 'Filter Media', MLF_DOMAIN ) . '</a>',
		)
	);
}

/**
 * Adds additional JOIN statements to the main query.
 *
 * This adds additional JOIN statements to get term information for the queried attachments.
 *
 * @since 1.0.0
 *
 * @global object $wpdb Database class.
 *
 * @param string $sql Current string of JOINs.
 *
 * @return string More SQL.
 */
add_filter( 'posts_join', 'mlf_join' );
function mlf_join( $sql ) {

	// If we're not on the upload.php screen, return.
	if ( ! mlf_is_media_library() ) {
		return $sql;
	}

	global $wpdb;

	$mlf_taxonomy = mlf_get_selected_taxonomy();
	$mlf_term_id  = mlf_get_selected_term_id();

	if ( ! $mlf_taxonomy ) {
		return $sql;
	}

	$taxonomy_sql = $wpdb->prepare( " AND $wpdb->term_taxonomy.taxonomy = %s ", $mlf_taxonomy );
	$term_sql     = ( $mlf_term_id ) ? $wpdb->prepare( " AND $wpdb->terms.term_id = %d ", $mlf_term_id ) : " ";

	$sql .= " ";
	$sql .= "INNER JOIN $wpdb->term_relationships ON ( $wpdb->posts.post_parent = $wpdb->term_relationships.object_id ) ";
	$sql .= "INNER JOIN $wpdb->term_taxonomy ON ( $wpdb->term_relationships.term_taxonomy_id = $wpdb->term_taxonomy.term_taxonomy_id ) ";
	$sql .= $taxonomy_sql;
	$sql .= "INNER JOIN $wpdb->terms ON ( $wpdb->terms.term_id = $wpdb->term_taxonomy.term_id ) ";
	$sql .= $term_sql;

	return $sql;
}

/**
 * Adds GROUP BY statement to the main query.
 *
 * This adds a GROUP BY statement to the queried attachments.
 *
 * @since 1.0.0
 *
 * @global object $wpdb Database class.
 *
 * @param string $groupby Current $groupby.
 *
 * @return string Modified GROUP BY string.
 */
add_filter( 'posts_groupby', 'mlf_groupby' );
function mlf_groupby( $groupby ) {

	// If we're not on the upload.php screen, return.
	if ( ! mlf_is_media_library() ) {
		return $groupby;
	}

	$mlf_taxonomy = mlf_get_selected_taxonomy();

	if ( ! $mlf_taxonomy ) {
		return $groupby;
	}

	global $wpdb;

	$groupby = $wpdb->posts . ".ID";

	return $groupby;
}

/**
 * Add a taxonomy and term dropdown menu to upload.php.
 *
 * This adds a <select> tag to the upload.php page which displays a dropdown menu
 * listing all of the available taxonomies and their respective terms if a taxonomy
 * is selected..
 *
 * @since 1.0.0
 *
 * @return string HTML to display the dropdown menus.
 */
add_action( 'restrict_manage_posts', 'mlf_dropdowns' );
function mlf_dropdowns() {

	// If we're not on the upload.php screen, return.
	if ( ! mlf_is_media_library() ) {
		return;
	}

	$taxonomies = mlf_get_taxonomies();

	if ( empty( $taxonomies ) ) {
		return;
	}

	// Initialize $html variable.
	$html = '';

	// Get selected taxonomy (if any).
	$selected_taxonomy = mlf_get_selected_taxonomy();

	// Get taxonomy to ignore.
	$ignored_taxonomies = mlf_ignored_taxonomies();

	// Begin HTML output for taxonomy drop down.
	$html .= '<select name="mlf_taxonomy" id="mlf_taxonomy_dd" class="postform">';
	$html .= '<option value="">' . __( 'All taxonomies', MLF_DOMAIN ) . '</option>';
	foreach ( $taxonomies as $taxonomy ) {
		if ( in_array( $taxonomy->name, $ignored_taxonomies ) ) {
			continue;
		}
		$tax  = get_taxonomy( $taxonomy->name );
		$html .= '<option class="level-0" value="' . $taxonomy->name . '"' . selected( $selected_taxonomy,
				$taxonomy->name, false ) . '>' . $tax->label . ' (' . $taxonomy->total . ' ' . __( 'items',
				MLF_DOMAIN ) . ')</option>';
	}
	$html .= '</select>';

	// Begin HTML output for term drop down if there is a $selected_taxonomy and terms exist.
	if ( $selected_taxonomy ) {

		// Query terms for this taxonomy.
		$terms = mlf_get_terms( $selected_taxonomy );

		// If terms exist...
		if ( ! empty( $terms ) ) {

			// Get selected Term ID (if any).
			$selected_term_id = mlf_get_selected_term_id();

			// Build HTML for term drop down.
			$html .= '<select name="mlf_term_id" id="mlf_term_dd" class="postform">';
			$html .= '<option value="">' . __( 'All terms', MLF_DOMAIN ) . '</option>';
			foreach ( $terms as $term ) {
				$html .= '<option class="level-0" value="' . $term->term_id . '"' . selected( $selected_term_id,
						$term->term_id, false ) . '>' . $term->name . ' (' . $term->total . ' ' . __( 'items',
						MLF_DOMAIN ) . ')</option>';
			}
			$html .= '</select>';
		}
	}

	echo $html;
}

/**
 * Get all taxonomies.
 *
 * Get all taxonomies given the current filters in place.
 *
 * @since 1.0.0
 *
 * @global object $wpdb DB class.
 *
 * @return array Object containing all taxonomies returned in query.
 */
function mlf_get_taxonomies() {

	global $wpdb;

	$date_sql   = mlf_get_date_sql();
	$filter_sql = mlf_get_attachment_filter_sql();
	$search_sql = mlf_get_search_sql();

	$sql = "
		SELECT
			tt.taxonomy AS 'name',
			COUNT( DISTINCT( child.ID ) ) AS 'total'
		FROM $wpdb->posts AS child
			LEFT JOIN $wpdb->posts AS parent ON parent.ID = child.post_parent
		    LEFT JOIN $wpdb->term_relationships AS tr ON tr.object_id = parent.ID
		    INNER JOIN $wpdb->term_taxonomy AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		WHERE 1 = 1
		$date_sql
		$filter_sql
		$search_sql
		AND child.post_type = 'attachment'
		AND ( child.post_status = 'inherit' OR child.post_status = 'private' )
		GROUP BY tt.taxonomy
		ORDER BY tt.taxonomy ASC
		";

	$taxonomies = $wpdb->get_results( $sql );

	return $taxonomies;
}

/**
 * Get all terms.
 *
 * Get all terms given the current filters in place and currently selected taxonomy.
 *
 * @since 1.0.0
 *
 * @global object $wpdb DB class.
 *
 * @param string $taxonomy The currently selected taxonomy.
 *
 * @return array Object containing all terms returned in query.
 */
function mlf_get_terms( $taxonomy ) {

	global $wpdb;

	$taxonomy_sql = $wpdb->prepare( " AND tt.taxonomy = %s ", $taxonomy );
	$date_sql     = mlf_get_date_sql();
	$filter_sql   = mlf_get_attachment_filter_sql();
	$search_sql   = mlf_get_search_sql();

	$sql = "
		SELECT
			tt.taxonomy AS 'taxonomy',
			t.name AS 'name',
			t.term_id AS 'term_id',
			COUNT( DISTINCT( child.ID ) ) AS 'total'
		FROM $wpdb->posts AS child
			LEFT JOIN $wpdb->posts AS parent ON parent.ID = child.post_parent
		    LEFT JOIN $wpdb->term_relationships AS tr ON tr.object_id = parent.ID
		    INNER JOIN $wpdb->term_taxonomy AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		        $taxonomy_sql
		    LEFT JOIN $wpdb->terms AS t ON t.term_id = tt.term_id
		WHERE 1 = 1
		$date_sql
		$filter_sql
		$search_sql
		AND child.post_type = 'attachment'
		AND ( child.post_status = 'inherit' OR child.post_status = 'private' )
		GROUP BY t.term_id
		ORDER BY t.name ASC
		";

	$terms = $wpdb->get_results( $sql );

	return $terms;
}

/**
 * Returns selected taxonomy type.
 *
 * This returns the selected taxonomy type or false if no type is selected..
 *
 * @since 1.0.0
 *
 * @return string|boolean Returns taxonomy type value or false if it does not exist.
 */
function mlf_get_selected_taxonomy() {
	$type = filter_input( INPUT_GET, 'mlf_taxonomy', FILTER_SANITIZE_STRING );
	$type = trim( $type );
	if ( ! empty( $type ) ) {
		return $type;
	}

	return false;
}

/**
 * Returns selected term ID.
 *
 * This returns the selected term ID or false if no term is selected..
 *
 * @since 1.0.0
 *
 * @return string|boolean Returns term ID or false if it does not exist.
 */
function mlf_get_selected_term_id() {
	$id = filter_input( INPUT_GET, 'mlf_term_id', FILTER_SANITIZE_NUMBER_INT );
	$id = intval( trim( $id ) );
	if ( $id > 0 ) {
		return $id;
	}

	return false;
}

/**
 * Returns selected date in array array( "y" => "2016", "m" => "01") format.
 *
 * This returns the selected date or false if no term is selected..
 *
 * @since 1.0.0
 *
 * @return array|boolean Returns date or false if it does not exist.
 */
function mlf_get_selected_date() {
	$m = filter_input( INPUT_GET, 'm', FILTER_SANITIZE_NUMBER_INT );
	$m = intval( trim( $m ) );
	if ( $m > 0 ) {
		return array( 'y' => substr( $m, 0, 4 ), 'm' => substr( $m, - 2 ) );
	}

	return false;
}

/**
 * Returns selected date in SQL format.
 *
 * @since 1.0.0
 *
 * @return string Date to be used in SQL format.
 */
function mlf_get_date_sql() {

	$selected_date = mlf_get_selected_date();

	$y = esc_sql( $selected_date['y'] );
	$m = esc_sql( $selected_date['m'] );

	return ( $selected_date ) ? " AND YEAR( child.post_date ) = $y AND MONTH( child.post_date ) = $m " : " ";
}

/**
 * Returns selected attachment filter in SQL format.
 *
 * @since 1.0.0
 *
 * @global object $wpdb DB class.
 *
 * @return string attachment filter to be used in SQL format.
 */
function mlf_get_attachment_filter_sql() {

	$filter = filter_input( INPUT_GET, 'attachment-filter', FILTER_SANITIZE_ENCODED );

	if ( 'detached' == $filter ) {
		return " AND child.post_parent = 0 ";
	}

	$filter = urldecode( $filter );
	$filter = explode( ":", $filter );

	if ( 2 != count( $filter ) ) {
		return "";
	}

	global $wpdb;

	$value = $filter[1] . '/%';

	return $wpdb->prepare( " AND ( child.post_mime_type LIKE %s ) ", $value );
}

/**
 * Returns current search query in SQL format.
 *
 * @since 1.0.0
 *
 * @global object $wpdb DB class.
 *
 * @return string attachment filter to be used in SQL format.
 */
function mlf_get_search_sql() {

	$s = filter_input( INPUT_GET, 's', FILTER_SANITIZE_ENCODED );
	$s = trim( $s );

	if ( empty( $s ) ) {
		return " ";
	}

	global $wpdb;

	$s = '%' . $wpdb->esc_like( $s ) . '%';

	return $wpdb->prepare( " AND ( ( child.post_title LIKE %s ) OR ( child.post_content LIKE %s ) ) ", $s, $s );
}

/**
 * Return array of taxonomies to ignore.
 *
 * @since 1.0.0
 *
 * @return array Returns array of taxonomies that should be ignored (not listed in dropdown).
 */
function mlf_ignored_taxonomies() {

	$taxonomies = array(
		'nav_menu',
		'link_category',
		'post_format',
		'product_shipping_class', // WooCommerce
	);

	return apply_filters( 'mlf_ignored_taxonomies', $taxonomies );
}

/**
 * Returns true if current page is "upload.php". False otherwise.
 *
 * @return bool
 */
function mlf_is_media_library() {
	$page = basename( $_SERVER["SCRIPT_FILENAME"], '.php' );
	if ( 'upload' == $page ) {
		return true;
	}

	return false;
}




