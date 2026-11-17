<?php
/**
 * Middleware for ElasticPress integration.
 *
 * @package PRC\Platform
 */

namespace PRC\Platform\ElasticPress;

/**
 * Middleware for ElasticPress integration.
 */
class ElasticPress_Middleware {
	/**
	 * Debug mode flag.
	 *
	 * @var bool
	 */
	private static $debug_mode = null;

	/**
	 * The ElasticPress Facets class.
	 *
	 * @var \ElasticPress\Feature\Facets\Facets
	 */
	protected $ep_facets;

	/**
	 * VIP Enterprise Search / Elasticsearch max result window (from + size).
	 *
	 * Requests past this window fall back to MySQL. Cap navigable pages here;
	 * do not cap found_posts — results-info still shows the true public total.
	 *
	 * @see https://docs.wpvip.com/enterprise-search/es-limitations/
	 */
	const MAX_RESULT_WINDOW = 10000;

	/**
	 * Deep-page threshold for skipping facet aggregations.
	 *
	 * Result pagination still follows MAX_RESULT_WINDOW via get_max_paginated_pages().
	 */
	const MAX_NAVIGABLE_PAGE = 100;

	/**
	 * Terms aggregation size for taxonomies with more than 100 terms.
	 *
	 * Live term counts (read-only REST totals, 2026-11-16): bylines 630,
	 * category 437 (18 top-level), formats 23, research-teams 14,
	 * regions-countries 21. Facet UI shows only top-level terms, but child terms
	 * still take bucket slots, so category needs room for all of them.
	 */
	const FACET_TAXONOMY_SIZES = array(
		'bylines'  => 1000,
		'category' => 500,
	);

	/**
	 * Terms aggregation size for every other taxonomy.
	 */
	const DEFAULT_FACET_TAXONOMY_SIZE = 100;

	/**
	 * Check if debug mode is enabled.
	 *
	 * @return bool True if debug mode is enabled.
	 */
	private static function is_debug_mode() {
		if ( null === self::$debug_mode ) {
			self::$debug_mode = defined( 'PRC_ELASTICPRESS_DEBUG' ) && PRC_ELASTICPRESS_DEBUG;
		}
		return self::$debug_mode;
	}

	/**
	 * Log debug information.
	 *
	 * @param string $message The message to log.
	 * @param mixed  $data    Optional data to log.
	 */
	private static function debug_log( $message, $data = null ) {
		if ( ! self::is_debug_mode() ) {
			return;
		}

		$log_message = '[PRC Facets - ElasticPress] ' . $message;
		if ( null !== $data ) {
			$log_message .= ' | Data: ' . wp_json_encode( $data );
		}

		error_log( $log_message ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Constructor.
	 *
	 * @param object $loader The loader object.
	 */
	public function __construct( $loader ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-elasticpress-facets-api.php';

		// ElasticPress.
		if ( class_exists( '\ElasticPress\Feature\Facets\Facets' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-date-facet-type.php';
			$this->ep_facets = new \ElasticPress\Feature\Facets\Facets();
			$loader->add_action( 'pre_get_posts', $this, 'take_over_pub_listing_queries', 5, 1 );
			// After isPubListingQuery + pub-listing args (priorities 1 / 11).
			$loader->add_action( 'pre_get_posts', $this, 'flag_pub_listing_pages_past_ep_window', 12, 1 );
			$loader->add_filter( 'posts_pre_query', $this, 'short_circuit_pub_listing_pages_past_ep_window', 10, 2 );
			$loader->add_filter( 'the_posts', $this, 'cap_pub_listing_max_num_pages', 10, 2 );
			$loader->add_action( 'template_redirect', $this, 'not_found_pub_listing_pages_past_ep_window', 0 );
			// Before EP's facet_query (10) reads the ep_is_facetable query arg.
			$loader->add_action( 'pre_get_posts', $this, 'ensure_pub_listing_facetable', 9, 1 );
			$loader->add_filter( 'ep_facet_types', $this, 'register_date_facet_type' );
			$loader->add_filter( 'ep_facet_include_taxonomies', $this, 'register_facets' );
			$loader->add_filter( 'ep_post_formatted_args', $this, 'add_filters_to_query', 10, 3 );
			// After EP's set_agg_filters (10) and _post_visibility enforcement (20) have settled post_filter.
			$loader->add_filter( 'ep_post_formatted_args', $this, 'finalize_facet_aggregations', 30, 3 );
			$loader->add_filter( 'ep_valid_response', $this, 'include_date_aggregation_in_response', 19, 4 );
			$loader->add_filter( 'ep_valid_response', $this, 'remap_disjunctive_taxonomy_aggs', 20, 4 );
			$loader->add_filter( 'ep_facet_taxonomies_size', $this, 'set_facet_taxonomies_size', 10, 2 );
			// Last word on the facet aggregation list, after every EP facet type has added its own.
			$loader->add_filter( 'ep_facet_wp_query_aggs_facet', $this, 'remove_unused_aggregations', 99 );
			$loader->add_filter( 'ep_set_sort', $this, 'sort_ep_by_date', 20, 2 );
			$loader->add_filter( 'query_vars', $this, 'register_query_vars' );

			self::debug_log( 'ElasticPress Middleware initialized' );
		} else {
			self::debug_log( 'ElasticPress Facets class not available - skipping initialization' );
		}
	}

	/**
	 * Max navigable pages for a given posts_per_page within MAX_RESULT_WINDOW.
	 *
	 * Does not apply MAX_NAVIGABLE_PAGE — that only skips facet aggregations.
	 *
	 * @param int $posts_per_page Posts per page.
	 * @return int
	 */
	public static function get_max_paginated_pages( $posts_per_page ) {
		$posts_per_page = (int) $posts_per_page;
		if ( $posts_per_page < 1 ) {
			$posts_per_page = (int) get_option( 'posts_per_page' );
		}
		if ( $posts_per_page < 1 ) {
			$posts_per_page = 10;
		}
		return (int) floor( self::MAX_RESULT_WINDOW / $posts_per_page );
	}

	/**
	 * Resolve posts_per_page for a query (falls back to Reading setting).
	 *
	 * @param \WP_Query $query Query.
	 * @return int
	 */
	public static function get_query_posts_per_page( $query ) {
		$posts_per_page = (int) $query->get( 'posts_per_page' );
		if ( $posts_per_page < 1 ) {
			$posts_per_page = (int) get_option( 'posts_per_page' );
		}
		if ( $posts_per_page < 1 ) {
			$posts_per_page = 10;
		}
		return $posts_per_page;
	}

	/**
	 * Flag main pub-listing queries whose paged offset would exceed the ES window.
	 *
	 * @hook pre_get_posts
	 * @param \WP_Query $query The query.
	 */
	public function flag_pub_listing_pages_past_ep_window( $query ) {
		if ( ! $query->get( 'isPubListingQuery' ) || ! $query->is_main_query() ) {
			return;
		}

		$paged = (int) $query->get( 'paged' );
		if ( $paged < 1 ) {
			return;
		}

		$max_pages = self::get_max_paginated_pages( self::get_query_posts_per_page( $query ) );
		if ( $paged > $max_pages ) {
			self::debug_log(
				'Pub listing page exceeds EP max result window',
				array(
					'paged'     => $paged,
					'max_pages' => $max_pages,
				)
			);
			$query->set( 'prc_facets_ep_page_cap_exceeded', true );
			// Avoid a deep MySQL fallback scan if ES rejects the window.
			$query->set( 'ep_integrate', false );
		}
	}

	/**
	 * Short-circuit over-cap pub listing queries before MySQL runs.
	 *
	 * @hook posts_pre_query
	 *
	 * @param array|null $posts Posts (null to continue).
	 * @param \WP_Query  $query Query.
	 * @return array|null
	 */
	public function short_circuit_pub_listing_pages_past_ep_window( $posts, $query ) {
		if ( ! $query->get( 'prc_facets_ep_page_cap_exceeded' ) ) {
			return $posts;
		}
		$query->found_posts   = 0;
		$query->max_num_pages = 0;
		return array();
	}

	/**
	 * Cap max_num_pages for pager UI without capping found_posts (results count).
	 *
	 * @hook the_posts
	 *
	 * @param array     $posts Posts.
	 * @param \WP_Query $query Query.
	 * @return array
	 */
	public function cap_pub_listing_max_num_pages( $posts, $query ) {
		if ( ! $query->get( 'isPubListingQuery' ) ) {
			return $posts;
		}
		if ( $query->get( 'prc_facets_ep_page_cap_exceeded' ) ) {
			return $posts;
		}

		$max_pages = self::get_max_paginated_pages( self::get_query_posts_per_page( $query ) );
		if ( (int) $query->max_num_pages > $max_pages ) {
			$query->max_num_pages = $max_pages;
		}

		return $posts;
	}

	/**
	 * Return HTTP 404 for pub-listing requests past the ES result window.
	 *
	 * @hook template_redirect
	 */
	public function not_found_pub_listing_pages_past_ep_window() {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			return;
		}
		if ( ! $wp_query->get( 'prc_facets_ep_page_cap_exceeded' ) ) {
			return;
		}
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Enforce ElasticPress integration for all publication listing queries.
	 *
	 * @hook pre_get_posts
	 * @param \WP_Query $query The query.
	 */
	public function take_over_pub_listing_queries( $query ) {
		if ( $query->get( 'isPubListingQuery' ) ) {
			self::debug_log(
				'Taking over publication listing query with ElasticPress',
				array(
					'is_search'  => $query->is_search(),
					'query_vars' => $query->query_vars,
				)
			);
			$query->set( 'ep_integrate', true );
		}
	}

	/**
	 * Ensure pub-listing and dataset archive queries are facetable.
	 *
	 * Sets the `ep_is_facetable` WP_Query arg (ElasticPress 5.3.0+). An explicit
	 * value set by another caller wins. Other plugins can opt a query in, or opt
	 * a default query out, with the `prc_elasticpress_is_facetable` filter.
	 *
	 * @hook pre_get_posts
	 *
	 * @param \WP_Query $query The query.
	 */
	public function ensure_pub_listing_facetable( $query ) {
		if ( null !== $query->get( 'ep_is_facetable', null ) ) {
			return;
		}

		$default = $query->get( 'isPubListingQuery' ) || ( $query->is_main_query() && $query->is_post_type_archive( 'dataset' ) );

		/**
		 * Filters whether a query should be marked facetable for ElasticPress.
		 *
		 * @param bool      $is_facetable Whether the query is facetable. True for pub-listing queries and the dataset archive.
		 * @param \WP_Query $query        The query.
		 */
		$is_facetable = (bool) apply_filters( 'prc_elasticpress_is_facetable', $default, $query );

		if ( $is_facetable ) {
			$query->set( 'ep_is_facetable', true );
		} elseif ( $default ) {
			$query->set( 'ep_is_facetable', false );
		}
	}

	/**
	 * Set the size of the taxonomy facets, how many buckets ES returns.
	 *
	 * ES returns exact counts only when size covers every term. Terms outside the
	 * returned buckets would render as (0), so bylines and category are sized above
	 * their term counts (630 bylines, 437 category terms including children).
	 *
	 * @hook ep_facet_taxonomies_size
	 * @param int                $size     The size.
	 * @param WP_Taxonomy|string $taxonomy The taxonomy object (as passed by ElasticPress) or slug.
	 * @return int $size The size.
	 */
	public function set_facet_taxonomies_size( $size, $taxonomy ) {
		unset( $size );
		$slug = is_object( $taxonomy ) ? $taxonomy->name : $taxonomy;
		return self::FACET_TAXONOMY_SIZES[ $slug ] ?? self::DEFAULT_FACET_TAXONOMY_SIZE;
	}

	/**
	 * Drop aggregations no PRC facet block reads.
	 *
	 * EP adds a `post_type` terms aggregation (size 10000). The PRC facets UI
	 * never renders it and no `ep_filter_post_type` URL is used.
	 *
	 * @hook ep_facet_wp_query_aggs_facet
	 * @param array $facet_aggs Facet aggregations.
	 * @return array
	 */
	public function remove_unused_aggregations( $facet_aggs ) {
		if ( is_array( $facet_aggs ) ) {
			unset( $facet_aggs['post_type'] );
		}
		return $facet_aggs;
	}

	/**
	 * Get the facet UI type for a given taxonomy.
	 *
	 * @param string $facet_slug The facet slug.
	 * @return string The facet type.
	 */
	public static function get_facet_type( $facet_slug ) {
		switch ( $facet_slug ) {
			case 'category':
			case 'formats':
				return 'checkbox';
			case 'bylines':
			case 'research-teams':
				return 'dropdown';
			case 'regions-countries':
				return 'radio';
			case 'years':
				return 'dropdown';
			case 'time_since':
				return 'radio';
			default:
				return 'checkbox';
		}
	}

	/**
	 * Add taxonomy aggregations to ElasticPress.
	 *
	 * @hook ep_facet_include_taxonomies
	 *
	 * @return array $taxonomies The taxonomies.
	 */
	public static function get_facets_settings() {
		self::debug_log( 'Getting ElasticPress facets settings' );
		$to_return = array();

		$category = get_taxonomy( 'category' );
		if ( $category ) {
			$category->facet_type  = self::get_facet_type( 'category' );
			$to_return['category'] = $category;
		}

		$formats = get_taxonomy( 'formats' );
		if ( $formats ) {
			$formats->facet_type  = self::get_facet_type( 'formats' );
			$to_return['formats'] = $formats;
		}

		$bylines = get_taxonomy( 'bylines' );
		if ( $bylines ) {
			$bylines->facet_type  = self::get_facet_type( 'bylines' );
			$to_return['bylines'] = $bylines;
		}

		$research_teams = get_taxonomy( 'research-teams' );
		if ( $research_teams ) {
			$research_teams->facet_type  = self::get_facet_type( 'research-teams' );
			$to_return['research-teams'] = $research_teams;
		}

		$regions_countries = get_taxonomy( 'regions-countries' );
		if ( $regions_countries ) {
			$regions_countries->facet_type  = self::get_facet_type( 'regions-countries' );
			$to_return['regions-countries'] = $regions_countries;
		}

		$to_return['years'] = (object) array(
			'name'       => 'years',
			'label'      => 'Years',
			'facet_type' => self::get_facet_type( 'years' ),
		);

		$to_return['time_since'] = (object) array(
			'name'       => 'time_since',
			'label'      => 'Date',
			'facet_type' => self::get_facet_type( 'time_since' ),
		);

		self::debug_log( 'Configured facets', array_keys( $to_return ) );
		return $to_return;
	}

	/**
	 * Register the facets with ElasticPress query.
	 *
	 * @param array $taxonomies The taxonomies.
	 * @return array $taxonomies The taxonomies.
	 */
	public function register_facets( $taxonomies ) {
		$facets = self::get_facets_settings();
		// Only register real taxonomies with EP's Facets feature.
		$taxonomy_facets = array();
		foreach ( $facets as $slug => $facet ) {
			if ( taxonomy_exists( $slug ) ) {
				$taxonomy_facets[ $slug ] = $facet;
			}
		}
		self::debug_log( 'Registering facets with ElasticPress', array_keys( $taxonomy_facets ) );
		return $taxonomy_facets;
	}

	/**
	 * Register the date facet type so it owns the ep_date_ URL prefix.
	 *
	 * @hook ep_facet_types
	 * @param array $types Facet type slug => class name.
	 * @return array
	 */
	public function register_date_facet_type( $types ) {
		$types['prc-date'] = Date_Facet_Type::class;
		return $types;
	}

	/**
	 * Register the query vars.
	 *
	 * @hook query_vars
	 * @param array $qvars The query vars.
	 * @return array $qvars The query vars.
	 */
	public function register_query_vars( $qvars ) {
		$qvars[] = 'ep_sort__by_date';
		return $qvars;
	}

	/**
	 * Sort ElasticPress results by date.
	 *
	 * @hook ep_set_sort
	 * @param array  $sort The sort.
	 * @param string $order The order.
	 * @return array $sort The sort.
	 */
	public function sort_ep_by_date( $sort, $order ) {
		if ( ! get_query_var( 'ep_sort__by_date' ) ) {
			return $sort;
		}
		self::debug_log( 'Sorting ElasticPress results by date', array( 'order' => $order ) );
		$sort = array(
			array(
				'post_date' => array(
					'order' => $order,
				),
			),
		);
		return $sort;
	}

	/**
	 * Ensure post_filter.bool.must exists and return a reference path via the args array.
	 *
	 * @param array $args Formatted ES args.
	 * @return array
	 */
	private function ensure_post_filter_must( array $args ) {
		if ( ! isset( $args['post_filter'] ) || ! is_array( $args['post_filter'] ) ) {
			$args['post_filter'] = array();
		}
		if ( ! isset( $args['post_filter']['bool'] ) || ! is_array( $args['post_filter']['bool'] ) ) {
			$args['post_filter']['bool'] = array();
		}
		if ( ! isset( $args['post_filter']['bool']['must'] ) || ! is_array( $args['post_filter']['bool']['must'] ) ) {
			$args['post_filter']['bool']['must'] = array();
		}
		return $args;
	}

	/**
	 * Registered facet taxonomy slugs (excludes visibility / non-facet taxonomies).
	 *
	 * @return array<string, true>
	 */
	private function get_facet_taxonomy_slugs() {
		static $slugs = null;
		if ( null !== $slugs ) {
			return $slugs;
		}
		$slugs = array();
		foreach ( self::get_facets_settings() as $slug => $facet ) {
			unset( $facet );
			if ( taxonomy_exists( $slug ) ) {
				$slugs[ $slug ] = true;
			}
		}
		return $slugs;
	}

	/**
	 * Match ES field keys like terms.formats.slug → formats (facet taxonomies only).
	 *
	 * @param string $field ES field name.
	 * @return string|null Taxonomy slug or null.
	 */
	private function taxonomy_slug_from_field( $field ) {
		if ( ! is_string( $field ) ) {
			return null;
		}
		if ( preg_match( '/^terms\.([^.]+)\.slug$/', $field, $matches ) ) {
			$slug = $matches[1];
			if ( isset( $this->get_facet_taxonomy_slugs()[ $slug ] ) ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Extract facet-taxonomy field key from a term/terms clause.
	 *
	 * @param array $clause ES clause.
	 * @return string|null Field key or null.
	 */
	private function taxonomy_field_from_clause( array $clause ) {
		foreach ( array( 'terms', 'term' ) as $type ) {
			if ( empty( $clause[ $type ] ) || ! is_array( $clause[ $type ] ) ) {
				continue;
			}
			$field = key( $clause[ $type ] );
			if ( $this->taxonomy_slug_from_field( (string) $field ) ) {
				return (string) $field;
			}
		}
		return null;
	}

	/**
	 * Recursively collect positive facet-taxonomy term clauses from a filter tree.
	 *
	 * Skips must_not so visibility exclusions are never treated as facet selections.
	 *
	 * @param mixed $node Filter node.
	 * @param array $found Collected clauses.
	 * @return void
	 */
	private function collect_taxonomy_term_clauses( $node, array &$found ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		$field = $this->taxonomy_field_from_clause( $node );
		if ( null !== $field ) {
			$found[] = array(
				'field'  => $field,
				'clause' => $node,
			);
			return;
		}

		// Do not walk must_not — e.g. terms._post_visibility.slug exclusions.
		foreach ( array( 'must', 'should', 'filter' ) as $bool_key ) {
			if ( empty( $node['bool'][ $bool_key ] ) || ! is_array( $node['bool'][ $bool_key ] ) ) {
				continue;
			}
			$children = $node['bool'][ $bool_key ];
			// EP sometimes emits a single clause object instead of a list.
			if ( $this->is_assoc_array( $children ) ) {
				$children = array( $children );
			}
			foreach ( $children as $child ) {
				$this->collect_taxonomy_term_clauses( $child, $found );
			}
		}
	}

	/**
	 * Whether an array is associative (not a list of clauses).
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private function is_assoc_array( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Rebuild post_filter: OR within each facet taxonomy, AND across taxonomies.
	 *
	 * Preserves non-facet clauses (post_type, status, post_parent, visibility must_not).
	 *
	 * @param array $post_filter Existing post_filter.
	 * @return array
	 */
	private function rebuild_post_filter_or_within_and_across( array $post_filter ) {
		$found = array();
		$this->collect_taxonomy_term_clauses( $post_filter, $found );
		if ( empty( $found ) ) {
			return $post_filter;
		}

		$by_field = array();
		foreach ( $found as $item ) {
			$by_field[ $item['field'] ][] = $item['clause'];
		}

		$preserved_must = array();
		if ( ! empty( $post_filter['bool']['must'] ) && is_array( $post_filter['bool']['must'] ) ) {
			foreach ( $post_filter['bool']['must'] as $clause ) {
				if ( ! is_array( $clause ) ) {
					$preserved_must[] = $clause;
					continue;
				}
				// Drop only clauses that nest positive facet-taxonomy term filters.
				$nested = array();
				$this->collect_taxonomy_term_clauses( $clause, $nested );
				if ( empty( $nested ) ) {
					$preserved_must[] = $clause;
				}
			}
		}

		$must = $preserved_must;
		foreach ( $by_field as $clauses ) {
			$unique = array();
			foreach ( $clauses as $clause ) {
				$unique[ wp_json_encode( $clause ) ] = $clause;
			}
			$should = array_values( $unique );
			$must[] = array(
				'bool' => array(
					'should'               => $should,
					'minimum_should_match' => 1,
				),
			);
		}

		$post_filter['bool']         = isset( $post_filter['bool'] ) && is_array( $post_filter['bool'] ) ? $post_filter['bool'] : array();
		$post_filter['bool']['must'] = $must;
		return $post_filter;
	}

	/**
	 * Clone post_filter with positive facet clauses for a taxonomy removed.
	 *
	 * Preserves visibility must_not and other non-facet constraints.
	 *
	 * @param array  $post_filter Post filter.
	 * @param string $taxonomy    Taxonomy slug.
	 * @return array
	 */
	private function post_filter_excluding_taxonomy( array $post_filter, $taxonomy ) {
		$found = array();
		$this->collect_taxonomy_term_clauses( $post_filter, $found );

		$by_field = array();
		foreach ( $found as $item ) {
			$slug = $this->taxonomy_slug_from_field( $item['field'] );
			if ( $slug === $taxonomy ) {
				continue;
			}
			$by_field[ $item['field'] ][] = $item['clause'];
		}

		$preserved_must = array();
		if ( ! empty( $post_filter['bool']['must'] ) && is_array( $post_filter['bool']['must'] ) ) {
			foreach ( $post_filter['bool']['must'] as $clause ) {
				if ( ! is_array( $clause ) ) {
					$preserved_must[] = $clause;
					continue;
				}
				$nested = array();
				$this->collect_taxonomy_term_clauses( $clause, $nested );
				if ( empty( $nested ) ) {
					$preserved_must[] = $clause;
				}
			}
		}

		$must = $preserved_must;
		foreach ( $by_field as $clauses ) {
			$unique = array();
			foreach ( $clauses as $clause ) {
				$unique[ wp_json_encode( $clause ) ] = $clause;
			}
			$should = array_values( $unique );
			$must[] = array(
				'bool' => array(
					'should'               => $should,
					'minimum_should_match' => 1,
				),
			);
		}

		// Keep must_not / filter / should (e.g. _post_visibility exclusions) from the
		// original post_filter; only rebuild positive must facet clauses.
		$bool = ( ! empty( $post_filter['bool'] ) && is_array( $post_filter['bool'] ) )
			? $post_filter['bool']
			: array();
		if ( ! empty( $must ) ) {
			$bool['must'] = $must;
		} else {
			unset( $bool['must'] );
		}

		if ( empty( $bool ) ) {
			return array( 'match_all' => new \stdClass() );
		}

		return array(
			'bool' => $bool,
		);
	}

	/**
	 * Append date facet filters and restructure taxonomy OR/AND groups.
	 *
	 * @hook ep_post_formatted_args
	 *
	 * @param array     $args       The args.
	 * @param array     $query_args The query args.
	 * @param \WP_Query $wp_query   The WP query.
	 * @return array $args The args.
	 */
	public function add_filters_to_query( $args, $query_args, $wp_query ) {
		unset( $query_args );

		// OR within each taxonomy field; AND across taxonomies (FacetWP checkbox parity).
		if ( ! empty( $args['post_filter'] ) && is_array( $args['post_filter'] ) ) {
			$args['post_filter'] = $this->rebuild_post_filter_or_within_and_across( $args['post_filter'] );
		}

		// Date facets are ANDed after the taxonomy rebuild so they work without taxonomy facets selected.
		$facets = \ElasticPress\Features::factory()->get_registered_feature( 'facets' );
		if ( ! $facets->is_facetable( $wp_query ) ) {
			return $args;
		}

		$clauses = Date_Facets::get_filter_clauses( Date_Facets::get_selected_values( $facets->get_selected() ) );
		if ( empty( $clauses ) ) {
			return $args;
		}

		$args = $this->ensure_post_filter_must( $args );
		foreach ( $clauses as $clause ) {
			$args['post_filter']['bool']['must'][] = $clause;
		}

		return $args;
	}

	/**
	 * Give each facet aggregation the final post_filter minus that facet's own selection.
	 *
	 * Counts then show what each option would return, while every other facet
	 * stays applied. VIP EP applies post_filter to all term aggs and EP's
	 * set_agg_filters() overwrites the parent filter, so each child carries its own.
	 *
	 * @hook ep_post_formatted_args
	 *
	 * @param array     $formatted_args Formatted ES args.
	 * @param array     $args           WP query args.
	 * @param \WP_Query $wp_query       Query.
	 * @return array
	 */
	public function finalize_facet_aggregations( $formatted_args, $args, $wp_query ) {
		unset( $wp_query );

		// EP builds the aggregation filter by formatting the query a second time.
		if ( ! empty( $args['ep_facet_adding_agg_filters'] ) ) {
			return $formatted_args;
		}

		if ( empty( $formatted_args['aggs']['terms']['aggs'] ) || ! is_array( $formatted_args['aggs']['terms']['aggs'] ) ) {
			return $formatted_args;
		}

		$post_filter = isset( $formatted_args['post_filter'] ) && is_array( $formatted_args['post_filter'] )
			? $formatted_args['post_filter']
			: array();

		// Parent filter must not re-apply all facet filters onto every child agg.
		$formatted_args['aggs']['terms']['filter'] = array( 'match_all' => new \stdClass() );

		foreach ( $formatted_args['aggs']['terms']['aggs'] as $agg_name => $agg_body ) {
			if ( ! is_array( $agg_body ) ) {
				continue;
			}

			if ( isset( Date_Facets::AGGREGATIONS[ $agg_name ] ) ) {
				$agg_body['filter'] = empty( $post_filter )
					? array( 'match_all' => new \stdClass() )
					: Date_Facets::post_filter_without_facet( $post_filter, Date_Facets::AGGREGATIONS[ $agg_name ] );

				$formatted_args['aggs']['terms']['aggs'][ $agg_name ] = $agg_body;
				continue;
			}

			if ( ! taxonomy_exists( $agg_name ) ) {
				continue;
			}

			// Already wrapped.
			if ( isset( $agg_body['filter'] ) && isset( $agg_body['aggs'] ) ) {
				continue;
			}

			$formatted_args['aggs']['terms']['aggs'][ $agg_name ] = array(
				'filter' => $this->post_filter_excluding_taxonomy( $post_filter, $agg_name ),
				'aggs'   => array(
					$agg_name => $agg_body,
				),
			);
		}

		return $formatted_args;
	}

	/**
	 * Merge facet buckets into the aggregations stored on the query object.
	 *
	 * EP's own get_aggs() (priority 10) stores the flat aggregations first. The
	 * hooks below add the nested disjunctive buckets and the date buckets.
	 *
	 * @param \WP_Query $query_object Query that ran.
	 * @param array     $buckets      Facet slug => ( key => count ).
	 */
	private function merge_query_aggregations( $query_object, array $buckets ) {
		if ( empty( $buckets ) ) {
			return;
		}
		$existing = $this->ep_facets->get_query_aggregations( $query_object );
		$this->ep_facets->set_query_aggregations(
			$query_object,
			array_merge( is_array( $existing ) ? $existing : array(), $buckets )
		);
	}

	/**
	 * Remap nested disjunctive taxonomy buckets onto the query's aggregations.
	 *
	 * @hook ep_valid_response
	 *
	 * @param array $response     ES response.
	 * @param array $query        Prepared query.
	 * @param array $query_args   WP query args.
	 * @param mixed $query_object Query object.
	 * @return array
	 */
	public function remap_disjunctive_taxonomy_aggs( $response, $query, $query_args, $query_object ) {
		unset( $query, $query_args );

		if ( empty( $query_object ) || 'WP_Query' !== get_class( $query_object ) || ! $this->ep_facets->is_facetable( $query_object ) ) {
			return $response;
		}

		$remapped   = array();
		$terms_aggs = $response['aggregations']['terms'] ?? array();
		foreach ( $terms_aggs as $tax => $payload ) {
			if ( ! taxonomy_exists( $tax ) || ! is_array( $payload ) ) {
				continue;
			}
			$buckets = null;
			if ( isset( $payload[ $tax ]['buckets'] ) && is_array( $payload[ $tax ]['buckets'] ) ) {
				$buckets = $payload[ $tax ]['buckets'];
			} elseif ( isset( $payload['buckets'] ) && is_array( $payload['buckets'] ) ) {
				$buckets = $payload['buckets'];
			}
			if ( null === $buckets ) {
				continue;
			}
			$remapped[ $tax ] = array();
			foreach ( $buckets as $bucket ) {
				if ( ! isset( $bucket['key'] ) ) {
					continue;
				}
				$remapped[ $tax ][ $bucket['key'] ] = (int) ( $bucket['doc_count'] ?? 0 );
			}
		}

		$this->merge_query_aggregations( $query_object, $remapped );

		return $response;
	}

	/**
	 * Add the date aggregations (years, time_since) to the query's aggregations.
	 *
	 * @hook ep_valid_response
	 * @param  array $response ES response.
	 * @param  array $query Prepared Elasticsearch query.
	 * @param  array $query_args Current WP Query arguments.
	 * @param  mixed $query_object Could be WP_Query, WP_User_Query, etc.
	 * @return array
	 */
	public function include_date_aggregation_in_response( $response, $query, $query_args, $query_object ) {
		unset( $query, $query_args );

		if ( empty( $query_object ) || 'WP_Query' !== get_class( $query_object ) || ! $this->ep_facets->is_facetable( $query_object ) ) {
			return $response;
		}

		$date_buckets = array();

		if ( isset( $response['aggregations']['terms']['date_histogram']['years']['buckets'] ) ) {
			$date_buckets['years'] = array();
			foreach ( $response['aggregations']['terms']['date_histogram']['years']['buckets'] as $bucket ) {
				$date_buckets['years'][ $bucket['key'] ] = $bucket['doc_count'];
			}
		}

		if ( isset( $response['aggregations']['terms']['time_since'] ) ) {
			$date_buckets['time_since'] = array();
			foreach ( array_keys( Date_Facets::TIME_SINCE_RANGES ) as $slug ) {
				$date_buckets['time_since'][ $slug ] = (int) ( $response['aggregations']['terms']['time_since'][ $slug ]['doc_count'] ?? 0 );
			}
		}

		$this->merge_query_aggregations( $query_object, $date_buckets );

		return $response;
	}
}
