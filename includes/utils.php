<?php
/**
 * ElasticPress utility functions.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

/**
 * Whether a search term looks like spam / nonsense.
 *
 * Keep in sync with prc_vip_is_nonsense_search_term() in vip-config/redirects.php.
 * vip-config cannot depend on this plugin, so the heuristic is duplicated there.
 *
 * @param string $term Search term (spaces or '+' separators).
 * @return bool
 */
function is_nonsense_search_term( $term ) {
	if ( ! is_string( $term ) || '' === $term ) {
		return true;
	}

	// Consecutive '+' or spaces (e.g. is++mary+trump++a++democrat).
	if ( preg_match( '/\+\+|  /', $term ) ) {
		return true;
	}

	$normalized = str_replace( '+', ' ', $term );
	if ( preg_match( '/  /', $normalized ) ) {
		return true;
	}

	// Only punctuation / separators after stripping common URL noise.
	$stripped = preg_replace( '/[\+\-_\s]+/', '', $normalized );
	if ( '' === $stripped || ! preg_match( '/[A-Za-z0-9]/', $stripped ) ) {
		return true;
	}

	// Need at least one alphanumeric token of length >= 2 (preserves single-word search).
	preg_match_all( '/[A-Za-z0-9]{2,}/', $normalized, $token_matches );
	$token_count = isset( $token_matches[0] ) ? count( $token_matches[0] ) : 0;
	return $token_count < 1;
}

/**
 * Format a label.
 *
 * @param string $label The label to format.
 * @return string The formatted label.
 */
function format_label( $label ) {
	// If the label is a datetime let's check if its in the years only format and if so, return the year.
	if ( strtotime( $label ) !== false ) {
		return preg_match( '/^\d{4}$/', $label ) ? $label : gmdate( 'Y', strtotime( $label ) );
	}
	// Render any ampersands and such in the label.
	return html_entity_decode( $label );
}

/**
 * Constructs a cache key based on the current query and selected facets.
 *
 * @param array $query The current query.
 * @param array $selected The selected facets.
 * @return string The cache key.
 */
function construct_cache_key( $query = array(), $selected = array() ) {
	$invalidate = '07/20/2026-ep-only';

	// Ensure $query is an array.
	if ( ! is_array( $query ) ) {
		$query = array();
	}

	// Remove pagination from the query args.
	$query = array_merge(
		$query,
		array(
			'paged' => 1,
		)
	);

	// Ensure $selected is an array.
	if ( ! is_array( $selected ) ) {
		$selected = array();
	}

	// Construct an md5 hash of the query and selected facets and a quick invalidation method.
	return md5(
		wp_json_encode(
			array(
				'query'      => $query,
				'selected'   => $selected,
				'invalidate' => $invalidate,
			)
		)
	);
}

/**
 * Constructs a cache group based on the current URL.
 *
 * @return string|false The cache group, or false if the current URL is not valid.
 */
function construct_cache_group() {
	global $wp;
	// Construct an array of URL parameters from the current request to WP.
	$url_params = wp_parse_url( '/' . add_query_arg( array(), $wp->request . '/' ) );
	if ( ! is_array( $url_params ) || ! array_key_exists( 'path', $url_params ) ) {
		return false;
	}
	// Remove pagination from the cache group.
	return preg_replace( '/\/page\/[0-9]+/', '', $url_params['path'] );
}
