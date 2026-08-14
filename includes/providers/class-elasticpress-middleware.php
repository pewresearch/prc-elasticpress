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
	 * time_since URL slug → relative date string for ES range filters.
	 *
	 * Slugs match FacetWP safe_value(label) for bookmark/redirect continuity.
	 *
	 * @var array<string, string>
	 */
	const TIME_SINCE_RANGES = array(
		'past-month'     => '-30 days',
		'past-6-months'  => '-180 days',
		'past-12-months' => '-365 days',
		'past-2-years'   => '-730 days',
	);

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
			$this->ep_facets = new \ElasticPress\Feature\Facets\Facets();
			$loader->add_action( 'pre_get_posts', $this, 'take_over_pub_listing_queries', 5, 1 );
			// After isPubListingQuery + pub-listing args (priorities 1 / 11).
			$loader->add_action( 'pre_get_posts', $this, 'flag_pub_listing_pages_past_ep_window', 12, 1 );
			$loader->add_filter( 'posts_pre_query', $this, 'short_circuit_pub_listing_pages_past_ep_window', 10, 2 );
			$loader->add_filter( 'the_posts', $this, 'cap_pub_listing_max_num_pages', 10, 2 );
			$loader->add_action( 'template_redirect', $this, 'not_found_pub_listing_pages_past_ep_window', 0 );
			$loader->add_filter( 'ep_is_facetable', $this, 'ensure_pub_listing_facetable', 10, 2 );
			$loader->add_filter( 'ep_facet_include_taxonomies', $this, 'register_facets' );
			$loader->add_filter( 'ep_post_formatted_args', $this, 'add_filters_to_query', 10, 3 );
			$loader->add_filter( 'ep_formatted_args', $this, 'add_date_aggregations', 10, 3 );
			$loader->add_filter( 'ep_formatted_args', $this, 'make_taxonomy_aggregations_disjunctive', 20, 3 );
			$loader->add_filter( 'ep_valid_response', $this, 'include_date_aggregation_in_response', 19, 4 );
			$loader->add_filter( 'ep_valid_response', $this, 'remap_disjunctive_taxonomy_aggs', 20, 4 );
			$loader->add_filter( 'ep_facet_taxonomies_size', $this, 'set_facet_taxonomies_size', 10, 2 );
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
	 * Ensure pub-listing and dataset archive main queries are facetable.
	 *
	 * @hook ep_is_facetable
	 *
	 * @param bool      $is_facetable Whether the query is facetable.
	 * @param \WP_Query $query        The query.
	 * @return bool
	 */
	public function ensure_pub_listing_facetable( $is_facetable, $query ) {
		if ( $query->get( 'isPubListingQuery' ) ) {
			return true;
		}
		if ( $query->is_main_query() && $query->is_post_type_archive( 'dataset' ) ) {
			return true;
		}
		return $is_facetable;
	}

	/**
	 * Set the size of the taxonomy facets, how many records to return.
	 *
	 * @hook ep_facet_taxonomies_size
	 * @param int    $size The size.
	 * @param string $taxonomy The taxonomy.
	 * @return int $size The size.
	 */
	public function set_facet_taxonomies_size( $size, $taxonomy ) {
		$size = 100; // We don't really have that many terms to return for formats or categories, but bylines is a different story. That said, this should return the highest counts first so this works out in the end.
		return $size;
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
	 * Fixed time_since choices for UI + aggregations.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function get_time_since_choices() {
		return array(
			'past-month'     => 'Past Month',
			'past-6-months'  => 'Past 6 Months',
			'past-12-months' => 'Past 12 Months',
			'past-2-years'   => 'Past 2 Years',
		);
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
	 * Register the query vars.
	 *
	 * @hook query_vars
	 * @param array $qvars The query vars.
	 * @return array $qvars The query vars.
	 */
	public function register_query_vars( $qvars ) {
		$qvars[] = 'ep_sort__by_date';
		$qvars[] = 'ep_filter_years';
		$qvars[] = 'ep_filter_time_since';
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
	 * Append custom (non-taxonomy) facet filters and restructure taxonomy OR/AND groups.
	 *
	 * @hook ep_post_formatted_args
	 *
	 * @param array     $args       The args.
	 * @param array     $query_args The query args.
	 * @param \WP_Query $wp_query   The WP query.
	 * @return array $args The args.
	 */
	public function add_filters_to_query( $args, $query_args, $wp_query ) {
		unset( $query_args, $wp_query );

		$years_filter      = get_query_var( 'ep_filter_years' );
		$time_since_filter = get_query_var( 'ep_filter_time_since' );
		// Query vars / $_GET may be arrays (e.g. ?ep_filter_years[]=2024); only accept strings.
		if ( ! is_string( $years_filter ) ) {
			$years_filter = '';
		}
		if ( ! is_string( $time_since_filter ) ) {
			$time_since_filter = '';
		}
		if ( empty( $time_since_filter ) && isset( $_GET['ep_filter_time_since'] ) && is_string( $_GET['ep_filter_time_since'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$time_since_filter = sanitize_text_field( wp_unslash( $_GET['ep_filter_time_since'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( empty( $years_filter ) && isset( $_GET['ep_filter_years'] ) && is_string( $_GET['ep_filter_years'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$years_filter = sanitize_text_field( wp_unslash( $_GET['ep_filter_years'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// OR within each taxonomy field; AND across taxonomies (FacetWP checkbox parity).
		if ( ! empty( $args['post_filter'] ) && is_array( $args['post_filter'] ) ) {
			$args['post_filter'] = $this->rebuild_post_filter_or_within_and_across( $args['post_filter'] );
		}

		// Append years / time_since so they work without taxonomy facets selected.
		if ( ! empty( $years_filter ) ) {
			$args   = $this->ensure_post_filter_must( $args );
			$years  = array_filter( array_map( 'trim', explode( ',', (string) $years_filter ) ) );
			$should = array();
			foreach ( $years as $year ) {
				$should[] = array(
					'term' => array(
						'date_terms.year' => (int) $year,
					),
				);
			}
			if ( ! empty( $should ) ) {
				$args['post_filter']['bool']['must'][] = array(
					'bool' => array(
						'should'               => $should,
						'minimum_should_match' => 1,
					),
				);
			}
		}

		if ( ! empty( $time_since_filter ) ) {
			$slug = sanitize_title( (string) $time_since_filter );
			if ( isset( self::TIME_SINCE_RANGES[ $slug ] ) ) {
				$from = gmdate( 'Y-m-d H:i:s', strtotime( self::TIME_SINCE_RANGES[ $slug ] ) );
				$args = $this->ensure_post_filter_must( $args );
				$args['post_filter']['bool']['must'][] = array(
					'range' => array(
						'post_date' => array(
							'gte' => $from,
						),
					),
				);
			}
		}

		return $args;
	}

	/**
	 * Add years + time_since aggregations to the EP query.
	 *
	 * @hook ep_formatted_args
	 *
	 * @param array     $formatted_args The formatted args.
	 * @param array     $args           The args.
	 * @param \WP_Query $wp_query       The WP query.
	 * @return array $formatted_args The formatted args.
	 */
	public function add_date_aggregations( $formatted_args, $args, $wp_query ) {
		unset( $args, $wp_query );

		// Full post_filter (all taxonomy facets) so date buckets reflect current selections.
		$filter = $formatted_args['post_filter'] ?? array( 'match_all' => new \stdClass() );

		if ( ! isset( $formatted_args['aggs']['terms']['aggs'] ) ) {
			$formatted_args['aggs']['terms']['aggs'] = array();
		}

		$formatted_args['aggs']['terms']['aggs']['date_histogram'] = array(
			'filter' => $filter,
			'aggs'   => array(
				'years' => array(
					'terms' => array(
						'field' => 'date_terms.year',
						'order' => array( '_key' => 'desc' ),
						'size'  => 75,
					),
				),
			),
		);

		// Fixed time_since buckets as filters aggregation (doc counts per range).
		$ranges = array();
		foreach ( self::TIME_SINCE_RANGES as $slug => $relative ) {
			$ranges[ $slug ] = array(
				'filter' => array(
					'range' => array(
						'post_date' => array(
							'gte' => gmdate( 'Y-m-d H:i:s', strtotime( $relative ) ),
						),
					),
				),
			);
		}
		$formatted_args['aggs']['terms']['aggs']['time_since'] = array(
			'filter' => $filter,
			'aggs'   => $ranges,
		);

		return $formatted_args;
	}

	/**
	 * Make taxonomy aggregations disjunctive (exclude own taxonomy from agg filter).
	 *
	 * VIP EP 4.2.2 applies post_filter to all term aggs; without this, selecting
	 * one Formats term zeroes sibling format counts.
	 *
	 * @hook ep_formatted_args
	 *
	 * @param array     $formatted_args Formatted ES args.
	 * @param array     $args           WP query args.
	 * @param \WP_Query $wp_query       Query.
	 * @return array
	 */
	public function make_taxonomy_aggregations_disjunctive( $formatted_args, $args, $wp_query ) {
		unset( $args, $wp_query );

		if ( empty( $formatted_args['aggs']['terms']['aggs'] ) || ! is_array( $formatted_args['aggs']['terms']['aggs'] ) ) {
			return $formatted_args;
		}

		$post_filter = isset( $formatted_args['post_filter'] ) && is_array( $formatted_args['post_filter'] )
			? $formatted_args['post_filter']
			: array();

		// Parent filter must not re-apply all facet filters onto every child agg.
		$formatted_args['aggs']['terms']['filter'] = array( 'match_all' => new \stdClass() );

		$skip = array( 'date_histogram', 'time_since', 'years', 'year', 'months' );
		foreach ( $formatted_args['aggs']['terms']['aggs'] as $agg_name => $agg_body ) {
			if ( in_array( $agg_name, $skip, true ) ) {
				continue;
			}
			if ( ! taxonomy_exists( $agg_name ) ) {
				continue;
			}
			if ( ! is_array( $agg_body ) ) {
				continue;
			}

			// Already wrapped.
			if ( isset( $agg_body['filter'] ) && isset( $agg_body['aggs'] ) ) {
				continue;
			}

			$exclusive = $this->post_filter_excluding_taxonomy( $post_filter, $agg_name );
			$formatted_args['aggs']['terms']['aggs'][ $agg_name ] = array(
				'filter' => $exclusive,
				'aggs'   => array(
					$agg_name => $agg_body,
				),
			);
		}

		return $formatted_args;
	}

	/**
	 * Remap nested disjunctive taxonomy buckets into $GLOBALS['ep_facet_aggs'].
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

		if ( ! isset( $GLOBALS['ep_facet_aggs'] ) || ! is_array( $GLOBALS['ep_facet_aggs'] ) ) {
			$GLOBALS['ep_facet_aggs'] = array();
		}

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
			$GLOBALS['ep_facet_aggs'][ $tax ] = array();
			foreach ( $buckets as $bucket ) {
				if ( ! isset( $bucket['key'] ) ) {
					continue;
				}
				$GLOBALS['ep_facet_aggs'][ $tax ][ $bucket['key'] ] = (int) ( $bucket['doc_count'] ?? 0 );
			}
		}

		return $response;
	}

	/**
	 * Copy custom aggregations into $GLOBALS['ep_facet_aggs'] for the Facets API.
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

		if ( ! isset( $GLOBALS['ep_facet_aggs'] ) || ! is_array( $GLOBALS['ep_facet_aggs'] ) ) {
			$GLOBALS['ep_facet_aggs'] = array();
		}

		if ( isset( $response['aggregations']['terms']['date_histogram']['years']['buckets'] ) ) {
			$years = $response['aggregations']['terms']['date_histogram']['years']['buckets'] ?? array();

			$GLOBALS['ep_facet_aggs']['years'] = array();

			foreach ( $years as $bucket ) {
				$GLOBALS['ep_facet_aggs']['years'][ $bucket['key'] ] = $bucket['doc_count'];
			}
		}

		if ( isset( $response['aggregations']['terms']['time_since'] ) ) {
			$GLOBALS['ep_facet_aggs']['time_since'] = array();
			foreach ( array_keys( self::TIME_SINCE_RANGES ) as $slug ) {
				$count = $response['aggregations']['terms']['time_since'][ $slug ]['doc_count'] ?? 0;
				$GLOBALS['ep_facet_aggs']['time_since'][ $slug ] = (int) $count;
			}
		}

		return $response;
	}
}
