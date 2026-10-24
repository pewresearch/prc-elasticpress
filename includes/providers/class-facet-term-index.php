<?php
/**
 * Per-taxonomy term index used to resolve facet slugs.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

/**
 * Resolves facet term slugs from one object-cache entry per taxonomy.
 *
 * A facet render needs only each term's id, parent and name. Those change only
 * when a term is created, edited or deleted, so the entry is not keyed on the
 * global terms `last_changed` version. Object-term writes (byline backfills,
 * post saves, term recounts) bump that version many times a minute and would
 * otherwise turn every facet render into one query and one cache set per term.
 *
 * Freshness:
 * - Term create, edit and delete mark the entry stale (they keep the terms so
 *   readers can still serve them while one request rebuilds).
 * - An entry also goes stale FRESH_FOR seconds after it was built, which covers
 *   writes that bypass the term hooks (direct SQL, term splits).
 * - Only the request that wins the rebuild lock queries the database. Other
 *   requests serve the stale copy, so a cold taxonomy costs one query, not one
 *   per concurrent request.
 */
class Facet_Term_Index {
	/**
	 * Object-cache group for index entries and rebuild locks.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'prc-ep-facet-term-index';

	/**
	 * Bump to orphan every stored entry after changing the entry shape.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Storage TTL. Longer than FRESH_FOR so a stale copy is usually available
	 * to serve while one request rebuilds.
	 *
	 * @var int
	 */
	const TTL = WEEK_IN_SECONDS;

	/**
	 * Age after which an entry is rebuilt even without a term write.
	 *
	 * @var int
	 */
	const FRESH_FOR = HOUR_IN_SECONDS;

	/**
	 * Rebuild lock TTL. Bounds how long a crashed rebuild blocks others.
	 *
	 * @var int
	 */
	const LOCK_TTL = 30;

	/**
	 * Per-request copies, keyed by "{blog_id}:{taxonomy}".
	 *
	 * @var array<string, array<string, array{0:int,1:int,2:string}>>
	 */
	protected static $runtime = array();

	/**
	 * Constructor.
	 *
	 * @param object|null $loader The loader.
	 */
	public function __construct( $loader = null ) {
		if ( null !== $loader ) {
			$loader->add_action( 'created_term', $this, 'handle_term_change', 10, 3 );
			$loader->add_action( 'edited_term', $this, 'handle_term_change', 10, 3 );
			$loader->add_action( 'delete_term', $this, 'handle_term_change', 10, 3 );
		}
	}

	/**
	 * Mark the taxonomy's index stale after a term create, edit or delete.
	 *
	 * @hook created_term
	 * @hook edited_term
	 * @hook delete_term
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function handle_term_change( $term_id, $tt_id, $taxonomy ) {
		unset( $term_id, $tt_id );
		self::invalidate( (string) $taxonomy );
	}

	/**
	 * Object-cache key for a taxonomy's index entry.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	public static function cache_key( $taxonomy ) {
		return 'v' . self::SCHEMA_VERSION . ':' . $taxonomy;
	}

	/**
	 * All term slugs in the taxonomy, in get_terms() default order (name ASC).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[]|null Null when the taxonomy does not exist or the query failed.
	 */
	public static function get_slugs( $taxonomy ) {
		$terms = self::get_terms( $taxonomy );
		if ( null === $terms ) {
			return null;
		}
		// Integer-like slugs become int array keys; get_terms( fields=slugs ) returns strings.
		return array_map( 'strval', array_keys( $terms ) );
	}

	/**
	 * Resolve a slug the way get_term_by( 'slug' ) does.
	 *
	 * @param string     $taxonomy Taxonomy slug.
	 * @param string|int $slug     Term slug.
	 * @return array{term_id:int,parent:int,name:string}|null
	 */
	public static function find( $taxonomy, $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return null;
		}
		$terms = self::get_terms( $taxonomy );
		if ( null === $terms ) {
			return null;
		}
		if ( ! isset( $terms[ $slug ] ) ) {
			$slug = sanitize_title( $slug );
			if ( '' === $slug || ! isset( $terms[ $slug ] ) ) {
				return null;
			}
		}
		return array(
			'term_id' => $terms[ $slug ][0],
			'parent'  => $terms[ $slug ][1],
			'name'    => $terms[ $slug ][2],
		);
	}

	/**
	 * Mark a taxonomy's index stale for every request on this site.
	 *
	 * The stored terms stay in place so readers can serve them while one
	 * request rebuilds. The rebuild compares built_at against invalidated_at,
	 * so a write that lands during a rebuild is not lost.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function invalidate( $taxonomy ) {
		unset( self::$runtime[ self::runtime_key( $taxonomy ) ] );
		$key   = self::cache_key( $taxonomy );
		$entry = wp_cache_get( $key, self::CACHE_GROUP, true );
		$entry = is_array( $entry ) ? $entry : array();

		$entry['invalidated_at'] = microtime( true );
		wp_cache_set( $key, $entry, self::CACHE_GROUP, self::TTL );
	}

	/**
	 * Drop per-request copies (long-running CLI processes, tests).
	 */
	public static function reset_runtime() {
		self::$runtime = array();
	}

	/**
	 * Get the slug => [ term_id, parent, name ] map for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string, array{0:int,1:int,2:string}>|null
	 */
	protected static function get_terms( $taxonomy ) {
		$taxonomy = (string) $taxonomy;
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$runtime_key = self::runtime_key( $taxonomy );
		if ( isset( self::$runtime[ $runtime_key ] ) ) {
			return self::$runtime[ $runtime_key ];
		}

		$key       = self::cache_key( $taxonomy );
		$entry     = wp_cache_get( $key, self::CACHE_GROUP );
		$has_terms = is_array( $entry ) && isset( $entry['terms'] ) && is_array( $entry['terms'] );

		if ( $has_terms && ! self::is_stale( $entry ) ) {
			self::$runtime[ $runtime_key ] = $entry['terms'];
			return $entry['terms'];
		}

		$lock_key = $key . ':lock';
		if ( ! wp_cache_add( $lock_key, 1, self::CACHE_GROUP, self::LOCK_TTL ) ) {
			if ( $has_terms ) {
				self::$runtime[ $runtime_key ] = $entry['terms'];
				return $entry['terms'];
			}
			// Cold and another request holds the lock: build for this request only.
			$terms = self::query_terms( $taxonomy );
			if ( null !== $terms ) {
				self::$runtime[ $runtime_key ] = $terms;
			}
			return $terms;
		}

		$built_at = microtime( true );
		$terms    = self::query_terms( $taxonomy );
		if ( null === $terms ) {
			wp_cache_delete( $lock_key, self::CACHE_GROUP );
			if ( $has_terms ) {
				self::$runtime[ $runtime_key ] = $entry['terms'];
				return $entry['terms'];
			}
			return null;
		}

		$current        = wp_cache_get( $key, self::CACHE_GROUP, true );
		$invalidated_at = is_array( $current ) && isset( $current['invalidated_at'] ) ? (float) $current['invalidated_at'] : 0.0;
		wp_cache_set(
			$key,
			array(
				'terms'          => $terms,
				'built_at'       => $built_at,
				'invalidated_at' => $invalidated_at,
			),
			self::CACHE_GROUP,
			self::TTL
		);
		wp_cache_delete( $lock_key, self::CACHE_GROUP );

		self::$runtime[ $runtime_key ] = $terms;
		return $terms;
	}

	/**
	 * Whether an entry must be rebuilt.
	 *
	 * @param array $entry Stored entry.
	 * @return bool
	 */
	protected static function is_stale( $entry ) {
		$built_at       = isset( $entry['built_at'] ) ? (float) $entry['built_at'] : 0.0;
		$invalidated_at = isset( $entry['invalidated_at'] ) ? (float) $entry['invalidated_at'] : 0.0;
		// built_at is taken before the query, so an equal stamp may have read pre-write rows.
		if ( $invalidated_at >= $built_at ) {
			return true;
		}
		return ( microtime( true ) - $built_at ) >= self::FRESH_FOR;
	}

	/**
	 * Read every term in the taxonomy with one database query.
	 *
	 * The `suppress_filter` arg matches get_term_by(), which skips the
	 * `get_terms` filter (the platform's underscore-to-comma name rewrite hooks
	 * there). The `cache_results` arg is off because the term-queries cache is
	 * keyed on the global last_changed version and would only add a set per
	 * rebuild.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string, array{0:int,1:int,2:string}>|null
	 */
	protected static function query_terms( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'               => $taxonomy,
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
				'cache_results'          => false,
				'suppress_filter'        => true,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return null;
		}
		$index = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term || isset( $index[ $term->slug ] ) ) {
				continue;
			}
			$index[ $term->slug ] = array( (int) $term->term_id, (int) $term->parent, (string) $term->name );
		}
		return $index;
	}

	/**
	 * Per-request cache key; object-cache keys are already blog-scoped.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	protected static function runtime_key( $taxonomy ) {
		return get_current_blog_id() . ':' . $taxonomy;
	}
}
