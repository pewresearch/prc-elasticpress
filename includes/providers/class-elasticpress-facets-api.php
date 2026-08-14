<?php
/**
 * A PHP based API for interacting with ElasticPress aggregations data.
 *
 * @package    PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

/**
 * A PHP based API for interacting with ElasticPress aggregations data.
 */
class ElasticPress_Facets_API {
	/**
	 * Whether to enable caching of the facets data.
	 *
	 * @var bool
	 */
	protected $enable_cache = true;

	/**
	 * The cache key.
	 *
	 * @var string
	 */
	public $cache_key;

	/**
	 * The cache group.
	 *
	 * @var string
	 */
	public $cache_group;

	/**
	 * The selected facets.
	 *
	 * @var array
	 */
	public $selected = array();

	/**
	 * The ElasticPress facets instance, or null when Facets feature is unavailable.
	 *
	 * @var \ElasticPress\Feature\Facets\Facets|null
	 */
	protected $ep_facets = null;

	/**
	 * The query arguments.
	 *
	 * @var array
	 */
	public $query_args;

	/**
	 * The query ID.
	 *
	 * @var string
	 */
	public $query_id;

	/**
	 * The query.
	 *
	 * @var array
	 */
	public $query;

	/**
	 * Whether ES aggregations were unavailable (degraded mode).
	 *
	 * @var bool
	 */
	public $is_degraded = false;

	/**
	 * The constructor.
	 *
	 * @param array $query The query.
	 */
	public function __construct( $query ) {
		if ( class_exists( '\ElasticPress\Feature\Facets\Facets' ) ) {
			$this->ep_facets = new \ElasticPress\Feature\Facets\Facets();
		} else {
			// VIP Search / EP Facets not loaded (e.g. local env without elasticsearch).
			$this->is_degraded = true;
			$this->ep_facets   = null;
		}
		$this->selected    = $this->get_selected( null, true );
		$this->cache_key   = construct_cache_key( $query, $this->selected );
		$this->cache_group = construct_cache_group() . '-ep-v2';
	}

	/**
	 * Build the URL.
	 *
	 * @param array $filters The filters.
	 * @return string The URL.
	 */
	public function build_url( $filters = array() ) {
		if ( null === $this->ep_facets ) {
			return '';
		}
		return $this->ep_facets->build_query_url( $filters );
	}

	/**
	 * Merge non-taxonomy ep_filter_* values from $_GET into selected.
	 *
	 * @param array $selected Selected facets.
	 * @return array
	 */
	protected function merge_non_taxonomy_selected_from_request( $selected ) {
		$custom_keys = array( 'years', 'time_since' );
		foreach ( $custom_keys as $facet_slug ) {
			$param = 'ep_filter_' . $facet_slug;
			$value = get_query_var( $param, null );
			if ( null === $value || '' === $value || false === $value ) {
				if ( isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$value = sanitize_text_field( wp_unslash( $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				} else {
					continue;
				}
			}
			if ( is_string( $value ) && '' !== $value ) {
				$selected[ $facet_slug ] = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
			}
		}
		return $selected;
	}

	/**
	 * Get the selected facets.
	 *
	 * @param string $key The key.
	 * @param bool   $failover_to_all The failover to all.
	 * @return array The selected facets.
	 */
	public function get_selected( $key = null, $failover_to_all = false ) {
		$selected = array();
		if ( null !== $this->ep_facets ) {
			$selected = $this->ep_facets->get_selected();
			// If s key is set, then we're on a search page. Lets remove it we dont need it in the facets.
			if ( array_key_exists( 's', $selected ) ) {
				unset( $selected['s'] );
			}
			// Move $selected['taxonomies'] to the top level.
			if ( array_key_exists( 'taxonomies', $selected ) ) {
				$taxonomies = array_keys( $selected['taxonomies'] );
				// Condense the 'terms' sub object.
				foreach ( $taxonomies as $taxonomy ) {
					$selected[ $taxonomy ] = array_keys( $selected['taxonomies'][ $taxonomy ]['terms'] );
				}
				unset( $selected['taxonomies'] );
			}
		}

		$selected = $this->merge_non_taxonomy_selected_from_request( $selected );

		if ( null !== $key && array_key_exists( $key, $selected ) ) {
			return $selected[ $key ];
		} elseif ( $failover_to_all ) {
			return $selected;
		}
		return array();
	}

	/**
	 * This function is the main way to get "aggregations" (facets) data from ES.
	 * Returns a list of taxonomies with values and counts.
	 *
	 * @return array|null
	 */
	public function get_aggregations() {
		if ( null === $this->ep_facets ) {
			do_action( 'qm/debug', 'Facets_API::get_aggregations:: ElasticPress Facets class unavailable, bail.' );
			$this->is_degraded = true;
			return null;
		}
		global $wp_query;
		if ( empty( $wp_query->elasticsearch_success ) ) {
			do_action( 'qm/debug', 'Facets_API::get_aggregations:: Unsuccessful ES request, bail.' );
			$this->is_degraded = true;
			return null;
		}
		global $ep_facet_aggs;
		$aggs = is_array( $ep_facet_aggs ) ? $ep_facet_aggs : array();

		foreach ( $aggs as $facet_slug => $facets_data ) {
			// Handle Year / time_since.
			if ( in_array(
				$facet_slug,
				array(
					'years',
					'year',
					'months',
					'time_since',
				),
				true
			) ) {
				$aggs[ $facet_slug ] = $facets_data;
				continue;
			}
			// Handle Taxonomy.
			$matched_terms     = $facets_data;
			$matched_term_keys = array_keys( $matched_terms );
			// Get all the terms for this taxonomy.
			$taxonomy_terms = get_terms(
				array(
					'taxonomy'   => $facet_slug,
					'hide_empty' => false,
					'fields'     => 'slugs',
				)
			);
			if ( is_wp_error( $taxonomy_terms ) || ! is_array( $taxonomy_terms ) ) {
				continue;
			}
			$new_terms = array();
			// Recreate the EP aggregations array but merged with the data from all taxonomy terms from WP.
			foreach ( $taxonomy_terms as $term_slug ) {
				// Recreate the term_slug => post_count array, but with 0 for those that don't exist in the current aggregation set.
				if ( in_array( $term_slug, $matched_term_keys, true ) ) {
					$new_terms[ $term_slug ] = $matched_terms[ $term_slug ];
				} else {
					$new_terms[ $term_slug ] = 0;
				}
			}
			// Reorder new_terms so that those with counts are on top based on value.
			$new_terms = array_merge( array_flip( $matched_term_keys ), $new_terms );
			// Replace the old terms with the new terms.
			$aggs[ $facet_slug ] = $new_terms;
		}
		return $aggs;
	}

	/**
	 * Process a taxonomy facet.
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param array  $terms The terms.
	 * @param bool   $disabled Whether choices should render disabled (degraded mode).
	 * @return array|false The processed taxonomy facet.
	 */
	protected function process_taxonomy_facet( $taxonomy, $terms, $disabled = false ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$taxonomy_facet = array(
			'choices'         => array(),
			'expandedChoices' => array(),
			'selected'        => $this->get_selected( $taxonomy ),
			'facetSlug'       => $taxonomy,
		);
		foreach ( $terms as $slug => $count ) {
			$term_obj = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term_obj || is_wp_error( $term_obj ) ) {
				continue;
			}
			// Only allow top level terms.
			if ( 0 !== (int) $term_obj->parent ) {
				continue;
			}
			$selected                    = $taxonomy_facet['selected'];
			$choice_disabled             = $disabled || 0 === (int) $count;
			$taxonomy_facet['choices'][] = array(
				'count'      => $count,
				'label'      => format_label( $term_obj->name ),
				'slug'       => $slug,
				'facetSlug'  => $taxonomy,
				'term_id'    => $term_obj->term_id,
				'value'      => $slug,
				'isSelected' => in_array( $slug, $selected, true ),
				'isRequired' => false,
				'isDisabled' => $choice_disabled,
				'disabled'   => $choice_disabled,
				'type'       => \PRC\Platform\ElasticPress\ElasticPress_Middleware::get_facet_type( $taxonomy ),
			);
		}
		return $taxonomy_facet;
	}

	/**
	 * Process a datetime facet (years).
	 *
	 * @param string $facet_slug The facet slug.
	 * @param array  $facets_data The facets data.
	 * @param bool   $disabled Whether choices should render disabled.
	 * @return array The processed datetime facet.
	 */
	protected function process_datetime_facet( $facet_slug, $facets_data, $disabled = false ) {
		$datetime_facet = array(
			'choices'         => array(),
			'expandedChoices' => array(),
			'selected'        => $this->get_selected( $facet_slug ),
			'facetSlug'       => $facet_slug,
		);
		foreach ( $facets_data as $year => $count ) {
			$selected                    = $datetime_facet['selected'];
			$choice_disabled             = $disabled || 0 === (int) $count;
			$datetime_facet['choices'][] = array(
				'count'      => $count,
				'label'      => format_label( (string) $year ),
				'slug'       => (string) $year,
				'facetSlug'  => $facet_slug,
				'value'      => (string) $year,
				'isSelected' => in_array( (string) $year, $selected, true ),
				'isRequired' => false,
				'isDisabled' => $choice_disabled,
				'disabled'   => $choice_disabled,
				'type'       => \PRC\Platform\ElasticPress\ElasticPress_Middleware::get_facet_type( $facet_slug ),
			);
		}
		return $datetime_facet;
	}

	/**
	 * Process time_since facet with fixed choice labels.
	 *
	 * @param array $facets_data Slug => count.
	 * @param bool  $disabled Whether choices should render disabled.
	 * @return array
	 */
	protected function process_time_since_facet( $facets_data, $disabled = false ) {
		$labels = ElasticPress_Middleware::get_time_since_choices();
		$facet  = array(
			'choices'         => array(),
			'expandedChoices' => array(),
			'selected'        => $this->get_selected( 'time_since' ),
			'facetSlug'       => 'time_since',
		);
		foreach ( $labels as $slug => $label ) {
			$count             = isset( $facets_data[ $slug ] ) ? (int) $facets_data[ $slug ] : 0;
			$choice_disabled    = $disabled || 0 === $count;
			$facet['choices'][] = array(
				'count'      => $count,
				'label'      => $label,
				'slug'       => $slug,
				'facetSlug'  => 'time_since',
				'value'      => $slug,
				'isSelected' => in_array( $slug, $facet['selected'], true ),
				'isRequired' => false,
				'isDisabled' => $choice_disabled,
				'disabled'   => $choice_disabled,
				'type'       => 'radio',
			);
		}
		return $facet;
	}

	/**
	 * Build degraded-mode facet shells (disabled controls, no counts).
	 *
	 * @return array
	 */
	protected function get_degraded_facet_shells() {
		$settings = ElasticPress_Middleware::get_facets_settings();
		$facets   = array();
		foreach ( $settings as $slug => $setting ) {
			if ( 'years' === $slug ) {
				$facets[ $slug ] = $this->process_datetime_facet( $slug, array(), true );
				continue;
			}
			if ( 'time_since' === $slug ) {
				$facets[ $slug ] = $this->process_time_since_facet( array(), true );
				continue;
			}
			if ( taxonomy_exists( $slug ) ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $slug,
						'hide_empty' => false,
						'parent'     => 0,
						'fields'     => 'slugs',
						'number'     => 100,
					)
				);
				$zero  = array();
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term_slug ) {
						$zero[ $term_slug ] = 0;
					}
				}
				$processed = $this->process_taxonomy_facet( $slug, $zero, true );
				if ( $processed ) {
					$facets[ $slug ] = $processed;
				}
			}
		}
		return $facets;
	}

	/**
	 * Get the facets.
	 *
	 * @return array
	 */
	public function get_facets() {
		$failover = false;
		// If this is the main blog or a 404 page, we should failover to the default WP query.
		if ( 1 === get_current_blog_id() || is_404() ) {
			$failover = true;
		}
		// Deep pages past MAX_NAVIGABLE_PAGE should not run facet aggregations.
		if ( is_paged() && ElasticPress_Middleware::MAX_NAVIGABLE_PAGE < (int) get_query_var( 'paged' ) ) {
			$failover = true;
		}
		// Nonsense search terms (mirrors vip-config; defensive if request reaches WP).
		if ( is_search() ) {
			$search_term = get_query_var( 's' );
			if ( ! is_string( $search_term ) || '' === $search_term ) {
				$search_term = get_search_query( false );
			}
			if ( is_nonsense_search_term( $search_term ) ) {
				$failover = true;
			}
		}
		if ( $failover ) {
			return array();
		}

		// If cache is enabled, check if we have a cached version of the facets.
		if ( $this->enable_cache ) {
			$cached_facets = wp_cache_get( $this->cache_key, $this->cache_group );
			if ( false !== $cached_facets ) {
				return $cached_facets;
			}
		}

		$aggregations = $this->get_aggregations();
		if ( null === $aggregations ) {
			// ES failed: page may still render via MySQL; show disabled facet shells.
			return $this->get_degraded_facet_shells();
		}

		$facets = array();
		foreach ( $aggregations as $facet_slug => $facets_data ) {
			do_action( 'qm/debug', 'PRC Facets - EP - Processing Facet:: ' . $facet_slug );
			if ( 'time_since' === $facet_slug ) {
				$facets[ $facet_slug ] = $this->process_time_since_facet( $facets_data );
			} elseif ( in_array(
				$facet_slug,
				array(
					'years',
					'year',
					'months',
				),
				true
			) ) {
				$facets[ $facet_slug ] = $this->process_datetime_facet( $facet_slug, $facets_data );
			} else {
				$processed = $this->process_taxonomy_facet( $facet_slug, $facets_data );
				if ( $processed ) {
					$facets[ $facet_slug ] = $processed;
				}
			}
		}

		// Ensure time_since always present even if agg missing from response.
		if ( ! isset( $facets['time_since'] ) ) {
			$facets['time_since'] = $this->process_time_since_facet( array() );
		}

		if ( ! is_preview() || ! empty( $facets ) ) {
			// If cache is enabled, cache the facets for 30 minutes.
			if ( $this->enable_cache ) {
				wp_cache_set(
					$this->cache_key,
					$facets,
					$this->cache_group,
					30 * MINUTE_IN_SECONDS
				);
			}
		}

		return $facets;
	}

	/**
	 * Get the pagination.
	 *
	 * total_rows stays the true ES/public found_posts count (may exceed the
	 * navigable window). total_pages is the capped pager length so UI links
	 * never request offsets past VIP's ES max result window.
	 *
	 * @return array The pagination.
	 */
	public function get_pagination() {
		global $wp_query;

		$per_page = ElasticPress_Middleware::get_query_posts_per_page( $wp_query );
		$paged    = (int) $wp_query->get( 'paged' );
		$page     = max( 1, $paged );

		// Prefer the query's (already capped) max_num_pages; fall back to a local cap.
		$total_pages = (int) $wp_query->max_num_pages;
		$max_pages   = ElasticPress_Middleware::get_max_paginated_pages( $per_page );
		if ( $total_pages > $max_pages ) {
			$total_pages = $max_pages;
		}

		return array(
			// Uncapped public total for results-info ("of N results").
			'total_rows'  => (int) $wp_query->found_posts,
			'per_page'    => $per_page,
			// Capped navigable pages (ES max result window / per_page).
			'total_pages' => $total_pages,
			'page'        => $page,
		);
	}
}
