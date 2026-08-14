<?php
/**
 * Plugin class.
 *
 * @package    PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

use WP_Error;

/**
 * Plugin class.
 *
 * @package    PRC\Platform\ElasticPress
 */
class Plugin {
	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Max characters allowed in a search term (security + performance).
	 *
	 * @var int
	 */
	const SEARCH_TERM_MAX_LENGTH = 100;

	/**
	 * Max posts per page for search RSS feeds (crawler hardening).
	 *
	 * @var int
	 */
	const SEARCH_FEED_POSTS_PER_PAGE = 20;

	/**
	 * Define the core functionality of the platform as initialized by hooks.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version     = '1.0.0';
		$this->plugin_name = 'prc-elasticpress';

		$this->load_dependencies();
		$this->init_dependencies();
	}


	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		// Load plugin loading class.
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';

		// Initialize the loader.
		$this->loader = new Loader();

		require_once plugin_dir_path( __DIR__ ) . '/includes/providers/class-elasticpress-middleware.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-rest-api.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-block-migrations.php';

		require_once PRC_ELASTICPRESS_DIR . '/build/context-provider/class-context-provider.php';
		require_once PRC_ELASTICPRESS_DIR . '/build/results-info/class-results-info.php';
		require_once PRC_ELASTICPRESS_DIR . '/build/search-relevancy/class-search-relevancy.php';
		require_once PRC_ELASTICPRESS_DIR . '/build/template/class-template.php';
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$this->loader->add_filter( 'robots_txt', $this, 'manage_robots_txt', 10, 2 );
		$this->loader->add_filter( 'wp_robots', $this, 'manage_robots', 10, 1 );
		$this->loader->add_action( 'pre_get_posts', $this, 'sanitize_search_term', 1, 1 );
		// After take_over_pub_listing_queries (5) and integrate_search_queries (6) so ep_integrate=false sticks.
		$this->loader->add_action( 'pre_get_posts', $this, 'reject_nonsense_search_term', 7, 1 );
		$this->loader->add_action( 'pre_get_posts', $this, 'integrate_search_queries', 6, 1 );
		$this->loader->add_filter( 'posts_pre_query', $this, 'short_circuit_nonsense_search', 10, 2 );
		$this->loader->add_action( 'template_redirect', $this, 'not_found_nonsense_search', 0 );
		$this->loader->add_filter( 'rest_post_query', $this, 'integrate_rest_post_search', 5, 2 );
		$this->loader->add_filter( 'ep_set_sort', $this, 'ep_sort_by_date', 10, 2 );
		$this->loader->add_filter( 'ep_highlight_should_add_clause', $this, 'ep_enable_highlighting', 10, 4 );

		new Rest_API( $this->get_loader() );
		new ElasticPress_Middleware( $this->get_loader() );
		new Block_Migrations( $this->get_loader() );

		\wp_register_block_metadata_collection(
			PRC_ELASTICPRESS_DIR . '/build',
			PRC_ELASTICPRESS_DIR . '/build/blocks-manifest.php'
		);

		new Context_Provider( $this->get_loader() );
		new Results_Info( $this->get_loader() );
		new Search_Relevancy( $this->get_loader() );
		new Template( $this->get_loader() );

		// Disable WordPress date archives - faceted search handles date filtering instead.
		$this->loader->add_action( 'template_redirect', $this, 'disable_date_archives' );
	}

	/**
	 * Disable WordPress date archives (month, day only).
	 *
	 * PRC uses faceted search for date-based filtering instead of WordPress's
	 * built-in date archives. This prevents thin content pages and ensures
	 * all date-based navigation goes through the faceted search system.
	 *
	 * Note: Year archives are excluded here because they are redirected to
	 * /publications/?ep_filter_years=YYYY by publication-listing archive redirects.
	 *
	 * @hook template_redirect
	 * @return void
	 */
	public function disable_date_archives() {
		// Only 404 month and day archives; year archives are redirected elsewhere.
		if ( is_month() || is_day() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Manage the robots.txt file and hide /search pages from Googlebot.
	 *
	 * @param string $output The output.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function manage_robots_txt( $output, $public ) {
		// Blocking internal /search pages from Googlebot.
		$output .= 'User-agent: Googlebot' . PHP_EOL;
		$output .= 'Disallow: /search/' . PHP_EOL;
		$output .= 'Disallow: /search' . PHP_EOL;
		$output .= 'Disallow: /?s=' . PHP_EOL;
		return $output;
	}

	/**
	 * Manage the robots meta for search.
	 *
	 * @hook wp_robots
	 * @param array $robots_directives The robots directives.
	 * @return array
	 */
	public function manage_robots( $robots_directives ) {
		if ( is_search() ) {
			$robots_directives['noindex']  = true;
			$robots_directives['nofollow'] = true;
		}
		return $robots_directives;
	}

	/**
	 * Sanitizes search term early if present and limit to 100 characters both as a security and performance measure.
	 *
	 * Covers frontend main queries (including search feeds) and REST search queries.
	 *
	 * @hook pre_get_posts
	 *
	 * @param \WP_Query $query The query.
	 */
	public function sanitize_search_term( $query ) {
		$is_frontend_search = ! is_admin() && $query->is_main_query() && $query->is_search();
		$is_rest_search     = defined( 'REST_REQUEST' ) && REST_REQUEST && $query->is_search();

		if ( ! $is_frontend_search && ! $is_rest_search ) {
			return;
		}

		$raw = $query->get( 's' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			$raw = isset( $query->query['s'] ) && is_string( $query->query['s'] ) ? $query->query['s'] : '';
		}

		$query->set( 's', $this->sanitize_search_string( $raw ) );
	}

	/**
	 * Flag nonsense frontend search terms so we skip EP + MySQL work.
	 *
	 * Mirrors vip-config/redirects.php; defensive if a request reaches WordPress.
	 * Runs after pub-listing / feed EP force-enable hooks so ep_integrate stays false.
	 *
	 * @hook pre_get_posts
	 *
	 * @param \WP_Query $query The query.
	 */
	public function reject_nonsense_search_term( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$raw = $query->get( 's' );
		if ( ! is_string( $raw ) ) {
			$raw = '';
		}

		if ( ! is_nonsense_search_term( $raw ) ) {
			return;
		}

		$query->set( 'prc_nonsense_search', true );
		$query->set( 'ep_integrate', false );
	}

	/**
	 * Short-circuit nonsense search queries before MySQL / ES runs.
	 *
	 * @hook posts_pre_query
	 *
	 * @param array|null $posts Posts (null to continue).
	 * @param \WP_Query  $query Query.
	 * @return array|null
	 */
	public function short_circuit_nonsense_search( $posts, $query ) {
		if ( ! $query->get( 'prc_nonsense_search' ) ) {
			return $posts;
		}
		$query->found_posts   = 0;
		$query->max_num_pages = 0;
		return array();
	}

	/**
	 * Return HTTP 404 for nonsense search requests that reached WordPress.
	 *
	 * @hook template_redirect
	 */
	public function not_found_nonsense_search() {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			return;
		}
		if ( ! $wp_query->get( 'prc_nonsense_search' ) ) {
			return;
		}
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Truncate and sanitize a search string.
	 *
	 * @param string $term Raw search term.
	 * @return string
	 */
	private function sanitize_search_string( string $term ): string {
		return substr( sanitize_text_field( $term ), 0, self::SEARCH_TERM_MAX_LENGTH );
	}

	/**
	 * Route REST and search-feed queries through ElasticPress.
	 *
	 * Frontend HTML /search* already gets ep_integrate via facets middleware.
	 * REST CPT controllers and search RSS feeds do not, so they fall through to
	 * expensive MySQL LIKE scans — force EP here.
	 *
	 * @hook pre_get_posts
	 *
	 * @param \WP_Query $query The query.
	 */
	public function integrate_search_queries( $query ) {
		if ( ! $query->is_search() ) {
			return;
		}

		$s = $query->get( 's' );
		if ( ! is_string( $s ) || '' === $s ) {
			return;
		}

		$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$is_feed = $query->is_feed();

		if ( ! $is_rest && ! $is_feed ) {
			return;
		}

		$query->set( 'ep_integrate', true );

		if ( $is_feed ) {
			$per_page = (int) $query->get( 'posts_per_page' );
			if ( $per_page <= 0 || $per_page > self::SEARCH_FEED_POSTS_PER_PAGE ) {
				$query->set( 'posts_per_page', self::SEARCH_FEED_POSTS_PER_PAGE );
			}
		}
	}

	/**
	 * Force ElasticPress on REST /wp/v2/posts?search=… and harden the term.
	 *
	 * Does not hook rest_chart_query — chart search clears `s` and uses meta.
	 *
	 * @hook rest_post_query
	 *
	 * @param array            $args    WP_Query arguments.
	 * @param \WP_REST_Request $request REST request.
	 * @return array
	 */
	public function integrate_rest_post_search( $args, $request ) {
		$search = $request->get_param( 'search' );
		if ( ! is_string( $search ) || '' === $search ) {
			return $args;
		}

		$sanitized = $this->sanitize_search_string( $search );
		if ( '' === $sanitized ) {
			$args['s'] = '';
			return $args;
		}

		$args['s']            = $sanitized;
		$args['ep_integrate'] = true;

		return $args;
	}

	/**
	 * Enable ElasticPress highlighting
	 *
	 * @hook ep_highlight_should_add_clause
	 * 
	 * @param mixed $add_highlight_clause The add highlight clause.
	 * @param mixed $formatted_args The formatted args.
	 * @param mixed $args The args.
	 * @return true
	 */
	public function ep_enable_highlighting( $add_highlight_clause, $formatted_args, $args ) {
		return true;
	}

	/**
	 * Force ElasticPress results to sort by date
	 *
	 * @hook ep_set_sort
	 * 
	 * @param mixed $sort The sort.
	 * @param mixed $order The order.
	 * @return mixed
	 */
	public function ep_sort_by_date( $sort, $order ) {
		// Only enable this when __search_sort_by is set to 'date', otherwise default to relevancy.
		if ( isset( $_GET['_ep_sort_by'] ) && 'date' === $_GET['_ep_sort_by'] ) {
			$sort = array(
				array(
					'post_date' => array(
						'order' => $order,
					),
				),
			);
		}
		return $sort;
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    PRC\Platform\ElasticPress\Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
