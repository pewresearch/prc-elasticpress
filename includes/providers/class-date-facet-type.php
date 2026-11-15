<?php
/**
 * ElasticPress facet type for the date facets.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

use ElasticPress\Feature\Facets\FacetType;

/**
 * Owns the ep_date_ URL prefix so date params never reach the taxonomy type.
 *
 * Filter clauses are not added through add_query_filters(). In "any" match
 * mode ElasticPress ORs every type's clauses, and the middleware needs these
 * ANDed with the taxonomy filters.
 */
class Date_Facet_Type extends FacetType {
	/**
	 * Setup hooks and filters.
	 */
	public function setup() {
		add_filter( 'ep_facet_wp_query_aggs_facet', array( $this, 'set_wp_query_aggs' ) );
	}

	/**
	 * Get the facet filter name (URL prefix).
	 *
	 * @return string
	 */
	public function get_filter_name(): string {
		return Date_Facets::URL_PREFIX;
	}

	/**
	 * Get the facet filter type.
	 *
	 * @return string
	 */
	public function get_filter_type(): string {
		return Date_Facets::FILTER_TYPE;
	}

	/**
	 * Format selected values. Ignores params that are not date facets.
	 *
	 * @param string $facet   Facet slug taken from the param name.
	 * @param mixed  $value   Param value.
	 * @param array  $filters Selected filters.
	 * @return array
	 */
	public function format_selected( string $facet, $value, array $filters ) {
		if ( ! Date_Facets::is_date_facet( $facet ) || ! is_string( $value ) ) {
			return $filters;
		}

		$terms = array_filter( explode( ',', trim( $value, ',' ) ), 'strlen' );
		if ( empty( $terms ) ) {
			return $filters;
		}

		$filters[ $this->get_filter_type() ][ $facet ] = array(
			'terms' => array_fill_keys( array_map( $this->get_sanitize_callback(), $terms ), true ),
		);

		return $filters;
	}

	/**
	 * Add selected filters to the query string.
	 *
	 * @param array $query_params Existing query parameters.
	 * @param array $filters      Selected filters.
	 * @return array
	 */
	public function add_query_params( array $query_params, array $filters ): array {
		foreach ( $filters[ $this->get_filter_type() ] ?? array() as $facet => $filter ) {
			if ( ! empty( $filter['terms'] ) ) {
				$query_params[ Date_Facets::url_param( $facet ) ] = implode( ',', array_keys( $filter['terms'] ) );
			}
		}

		return $query_params;
	}

	/**
	 * Add the date aggregations to the facet aggregations.
	 *
	 * @hook ep_facet_wp_query_aggs_facet
	 *
	 * @param array $facet_aggs Facet aggregations.
	 * @return array
	 */
	public function set_wp_query_aggs( $facet_aggs ) {
		return array_merge( $facet_aggs, Date_Facets::get_aggregations() );
	}
}
