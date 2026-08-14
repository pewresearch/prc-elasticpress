<?php
/**
 * Server redirects owned by @prc/elasticpress.
 *
 * Pure PHP only — loaded from vip-config before WordPress boots.
 * No WordPress APIs, Composer autoload, or plugin bootstrap.
 *
 * Handles:
 * - Legacy ?s= search → /search/{term}
 * - Legacy FacetWP underscore query params → ep_filter_*
 *
 * @package PRC\Platform\ElasticPress
 */

if ( ! function_exists( 'prc_elasticpress_server_redirects' ) ) {
	/**
	 * Run ElasticPress-owned pre-WordPress redirects.
	 *
	 * @param string $http_host    Request host.
	 * @param string $request_uri  Full request URI (path + query).
	 * @param string $full_url     Absolute URL built from host + URI.
	 * @param string $request_path Request path without query string.
	 * @return void
	 */
	function prc_elasticpress_server_redirects( $http_host, $request_uri, $full_url, $request_path ) {
		prc_elasticpress_redirect_legacy_search( $http_host, $request_uri, $full_url, $request_path );
		prc_elasticpress_redirect_legacy_facet_params( $http_host, $request_uri, $full_url, $request_path );
	}
}

if ( ! function_exists( 'prc_elasticpress_redirect_legacy_search' ) ) {
	/**
	 * Canonicalize legacy ?s= search queries to /search/{term} permalinks.
	 *
	 * Preserves ElasticPress facet query args (ep_filter_*).
	 *
	 * @param string $http_host    Request host.
	 * @param string $request_uri  Full request URI (path + query).
	 * @param string $full_url     Absolute URL built from host + URI.
	 * @param string $request_path Request path without query string.
	 * @return void
	 */
	function prc_elasticpress_redirect_legacy_search( $http_host, $request_uri, $full_url, $request_path ) {
		unset( $http_host, $request_uri );

		// Skip wp-admin requests only (path check; avoid matching search terms/query args).
		if ( strpos( $full_url, '?s=' ) === false || strpos( $request_path, '/wp-admin' ) !== false ) {
			return;
		}

		$matches = array();
		if ( ! preg_match( '/\?s=([^&]+)/', $full_url, $matches ) ) {
			return;
		}

		$search_term = $matches[1];
		// Canonicalize to site-root /search/{term} (not nested under the current path).
		// Preserve any ep_filter_* query params using native PHP (avoid WP helpers here).
		$target    = '/search/' . $search_term;
		$ep_params = array();
		foreach ( $_GET as $param_key => $param_value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 0 === strpos( (string) $param_key, 'ep_filter_' ) && '' !== $param_value ) {
				$ep_params[ $param_key ] = $param_value;
			}
		}
		if ( ! empty( $ep_params ) ) {
			$query_string = http_build_query( $ep_params, '', '&', PHP_QUERY_RFC3986 );
			$target      .= '/?' . $query_string;
		}

		header( 'Location: ' . $target, true, 301 );
		exit;
	}
}

if ( ! function_exists( 'prc_elasticpress_redirect_legacy_facet_params' ) ) {
	/**
	 * 301 legacy FacetWP facet query params to ep_filter_* equivalents.
	 *
	 * @param string $http_host    Request host.
	 * @param string $request_uri  Full request URI (path + query).
	 * @param string $full_url     Absolute URL built from host + URI.
	 * @param string $request_path Request path without query string.
	 * @return void
	 */
	function prc_elasticpress_redirect_legacy_facet_params( $http_host, $request_uri, $full_url, $request_path ) {
		unset( $http_host, $full_url, $request_uri );

		if ( empty( $_GET ) || ! is_array( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$map = array(
			'_categories'        => 'ep_filter_category',
			'_authors'           => 'ep_filter_bylines',
			'_research_teams'    => 'ep_filter_research-teams',
			'_regions_countries' => 'ep_filter_regions-countries',
			'_formats'           => 'ep_filter_formats',
			'_years'             => 'ep_filter_years',
			'_time_since'        => 'ep_filter_time_since',
		);

		$strip_keys = array(
			'_date_range' => true,
		);

		$has_legacy = false;
		foreach ( array_keys( $map ) as $legacy_key ) {
			if ( array_key_exists( $legacy_key, $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$has_legacy = true;
				break;
			}
		}
		if ( ! $has_legacy ) {
			foreach ( array_keys( $strip_keys ) as $strip_key ) {
				if ( array_key_exists( $strip_key, $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$has_legacy = true;
					break;
				}
			}
		}
		if ( ! $has_legacy ) {
			return;
		}

		$new_params = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key = (string) $key;
			if ( isset( $strip_keys[ $key ] ) ) {
				continue;
			}
			if ( isset( $map[ $key ] ) ) {
				$new_params[ $map[ $key ] ] = $value;
				continue;
			}
			$new_params[ $key ] = $value;
		}

		// Reset pagination when rewriting facet URLs.
		$path = preg_replace( '#/page/\d+/?#', '/', (string) $request_path );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$query_string = http_build_query( $new_params, '', '&', PHP_QUERY_RFC3986 );
		$target       = $path;
		if ( '' !== $query_string ) {
			$target .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . $query_string;
		}

		header( 'Location: ' . $target, true, 301 );
		exit;
	}
}
