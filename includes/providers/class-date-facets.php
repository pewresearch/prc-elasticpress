<?php
/**
 * Registry for the date facets (years, time_since).
 *
 * They are not taxonomies, so they live under their own URL prefix and never
 * reach ElasticPress's taxonomy facet type.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

/**
 * Filters, aggregations and URL params for the date facets.
 */
class Date_Facets {
	/**
	 * URL prefix for date facet params, e.g. ?ep_date_years=2024.
	 *
	 * @var string
	 */
	const URL_PREFIX = 'ep_date_';

	/**
	 * Key under which ElasticPress groups date selections in get_selected().
	 *
	 * @var string
	 */
	const FILTER_TYPE = 'prc_date';

	/**
	 * Time-since URL slug to relative date string for ES range filters.
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
	 * ES aggregation name to the facet it counts.
	 *
	 * @var array<string, string>
	 */
	const AGGREGATIONS = array(
		'date_histogram' => 'years',
		'time_since'     => 'time_since',
	);

	/**
	 * Range start per time_since slug, fixed for the request so the filter and
	 * the bucket counts use the same instant.
	 *
	 * @var array<string, string>
	 */
	private static $range_starts = array();

	/**
	 * Date facet slugs.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_values( self::AGGREGATIONS );
	}

	/**
	 * Whether the slug is a date facet.
	 *
	 * @param string $slug Facet slug.
	 * @return bool
	 */
	public static function is_date_facet( $slug ) {
		return in_array( $slug, self::slugs(), true );
	}

	/**
	 * URL param for a date facet.
	 *
	 * @param string $slug Facet slug.
	 * @return string
	 */
	public static function url_param( $slug ) {
		return self::URL_PREFIX . $slug;
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
	 * ES clause name for a facet, used to leave a facet out of its own counts.
	 *
	 * @param string $slug Facet slug.
	 * @return string
	 */
	private static function clause_name( $slug ) {
		return 'prc_facet_' . $slug;
	}

	/**
	 * Start of a time_since range, rounded down to the start of the UTC day so
	 * the ES query body repeats all day and stays cacheable.
	 *
	 * @param string $range_slug A key of TIME_SINCE_RANGES.
	 * @return string
	 */
	private static function range_start( $range_slug ) {
		if ( ! isset( self::$range_starts[ $range_slug ] ) ) {
			self::$range_starts[ $range_slug ] = gmdate( 'Y-m-d 00:00:00', strtotime( self::TIME_SINCE_RANGES[ $range_slug ] ) );
		}
		return self::$range_starts[ $range_slug ];
	}

	/**
	 * Date facet values out of ElasticPress's get_selected() shape.
	 *
	 * @param array $ep_selected Result of Facets::get_selected().
	 * @return array<string, string[]> Facet slug => values.
	 */
	public static function get_selected_values( array $ep_selected ) {
		$values = array();
		foreach ( $ep_selected[ self::FILTER_TYPE ] ?? array() as $slug => $filter ) {
			$values[ $slug ] = array_map( 'strval', array_keys( $filter['terms'] ) );
		}
		return $values;
	}

	/**
	 * ES filter clauses for the selected date facets, ANDed by the caller.
	 *
	 * @param array $selected Facet slug => values.
	 * @return array<string, array> Facet slug => clause.
	 */
	public static function get_filter_clauses( array $selected ) {
		$clauses = array();

		$years = array_filter( array_map( 'intval', (array) ( $selected['years'] ?? array() ) ) );
		if ( ! empty( $years ) ) {
			$should = array();
			foreach ( $years as $year ) {
				$should[] = array(
					'term' => array(
						'date_terms.year' => $year,
					),
				);
			}
			$clauses['years'] = array(
				'bool' => array(
					'should'               => $should,
					'minimum_should_match' => 1,
					'_name'                => self::clause_name( 'years' ),
				),
			);
		}

		foreach ( (array) ( $selected['time_since'] ?? array() ) as $value ) {
			$range_slug = sanitize_title( (string) $value );
			if ( ! isset( self::TIME_SINCE_RANGES[ $range_slug ] ) ) {
				continue;
			}
			$clauses['time_since'] = array(
				'bool' => array(
					'filter' => array(
						array(
							'range' => array(
								'post_date' => array(
									'gte' => self::range_start( $range_slug ),
								),
							),
						),
					),
					'_name'  => self::clause_name( 'time_since' ),
				),
			);
			break;
		}

		return $clauses;
	}

	/**
	 * Date aggregations. Their filters are set once the final post_filter exists.
	 *
	 * @return array<string, array>
	 */
	public static function get_aggregations() {
		$ranges = array();
		foreach ( array_keys( self::TIME_SINCE_RANGES ) as $range_slug ) {
			$ranges[ $range_slug ] = array(
				'filter' => array(
					'range' => array(
						'post_date' => array(
							'gte' => self::range_start( $range_slug ),
						),
					),
				),
			);
		}

		return array(
			'date_histogram' => array(
				'filter' => array( 'match_all' => new \stdClass() ),
				'aggs'   => array(
					'years' => array(
						'terms' => array(
							'field' => 'date_terms.year',
							'order' => array( '_key' => 'desc' ),
							'size'  => 75,
						),
					),
				),
			),
			'time_since'     => array(
				'filter' => array( 'match_all' => new \stdClass() ),
				'aggs'   => $ranges,
			),
		);
	}

	/**
	 * Copy of a post_filter without one date facet's own clause.
	 *
	 * @param array  $post_filter Final post_filter.
	 * @param string $slug        Facet slug.
	 * @return array
	 */
	public static function post_filter_without_facet( array $post_filter, $slug ) {
		if ( empty( $post_filter['bool']['must'] ) || ! is_array( $post_filter['bool']['must'] ) ) {
			return $post_filter;
		}

		$name = self::clause_name( $slug );
		$must = array();
		foreach ( $post_filter['bool']['must'] as $clause ) {
			if ( is_array( $clause ) && ( $clause['bool']['_name'] ?? null ) === $name ) {
				continue;
			}
			$must[] = $clause;
		}

		$post_filter['bool']['must'] = $must;
		return $post_filter;
	}
}
