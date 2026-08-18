<?php
/**
 * ElasticPress policy for DataViews admin list search.
 *
 * @package PRC\Platform\ElasticPress
 */

declare(strict_types=1);

namespace PRC\Platform\ElasticPress;

/**
 * Indexes unpublished (non-trash) statuses and shapes admin-list search fields.
 */
class Admin_Dataview_Search {
	/**
	 * Extra statuses indexed for editorial search. Trash stays on MySQL.
	 *
	 * @var string[]
	 */
	public const INDEXABLE_STATUSES = array( 'draft', 'pending', 'private', 'future' );

	/**
	 * Default DataViews search fields (title/slug weighted above body).
	 *
	 * Do not include `post_id`. Elasticsearch maps it as a number, so a text
	 * term like "confidence" throws `number_format_exception` and the search
	 * returns empty. Numeric IDs use WP_Query `post__in` instead.
	 *
	 * @var string[]
	 */
	public const SEARCH_FIELDS = array(
		'post_title^3',
		'post_name^2',
		'post_excerpt',
		'post_content',
	);

	/**
	 * Elasticsearch path for chart design_slug meta.
	 */
	public const CHART_DESIGN_SLUG_FIELD = 'meta.design_slug.value';

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( $loader ) {
		$loader->add_filter( 'ep_indexable_post_status', $this, 'indexable_post_status', 20, 1 );
		$loader->add_filter( 'ep_search_fields', $this, 'search_fields', 10, 2 );
		$loader->add_filter( 'ep_formatted_args', $this, 'enforce_editable_perm', 15, 2 );
		$loader->add_filter( 'vip_search_post_meta_allow_list', $this, 'allow_chart_design_slug', 10, 1 );
	}

	/**
	 * Index draft/pending/private/future. Never index trash.
	 *
	 * @hook ep_indexable_post_status
	 *
	 * @param mixed $statuses Current statuses.
	 * @return string[]
	 */
	public function indexable_post_status( $statuses ): array {
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}

		$merged = array_unique(
			array_merge(
				array_map( 'strval', $statuses ),
				self::INDEXABLE_STATUSES
			)
		);

		return array_values(
			array_filter(
				$merged,
				static function ( $status ) {
					return 'trash' !== $status && 'auto-draft' !== $status;
				}
			)
		);
	}

	/**
	 * Expand search fields for DataViews list queries.
	 *
	 * @hook ep_search_fields
	 *
	 * @param mixed $fields Current fields.
	 * @param mixed $args   WP_Query args.
	 * @return mixed
	 */
	public function search_fields( $fields, $args ) {
		if ( ! is_array( $args ) || empty( $args['prc_wp_admin_dataview'] ) ) {
			return $fields;
		}

		$search_fields = self::SEARCH_FIELDS;
		if ( $this->query_includes_charts( $args ) ) {
			$search_fields[] = self::CHART_DESIGN_SLUG_FIELD;
		}

		return $search_fields;
	}

	/**
	 * Index chart design_slug so admin search can match it.
	 *
	 * @hook vip_search_post_meta_allow_list
	 *
	 * @param mixed $allow Allow list.
	 * @return mixed
	 */
	public function allow_chart_design_slug( $allow ) {
		if ( ! is_array( $allow ) ) {
			return $allow;
		}
		$allow['design_slug'] = true;
		return $allow;
	}

	/**
	 * Approximate WP_Query `perm=editable` for DataViews ES queries.
	 *
	 * Users who cannot edit others' posts only see their own.
	 *
	 * @hook ep_formatted_args
	 *
	 * @param mixed $formatted_args ES args.
	 * @param mixed $args           WP_Query args.
	 * @return mixed
	 */
	public function enforce_editable_perm( $formatted_args, $args ) {
		if ( ! is_array( $formatted_args ) || ! is_array( $args ) ) {
			return $formatted_args;
		}
		if ( empty( $args['prc_wp_admin_dataview'] ) ) {
			return $formatted_args;
		}
		if ( 'editable' !== ( $args['perm'] ?? '' ) ) {
			return $formatted_args;
		}
		if ( $this->can_edit_others_for_query( $args ) ) {
			return $formatted_args;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id < 1 ) {
			return $formatted_args;
		}

		if ( ! isset( $formatted_args['post_filter'] ) || ! is_array( $formatted_args['post_filter'] ) ) {
			$formatted_args['post_filter'] = array();
		}
		if ( ! isset( $formatted_args['post_filter']['bool'] ) || ! is_array( $formatted_args['post_filter']['bool'] ) ) {
			$formatted_args['post_filter']['bool'] = array();
		}
		if ( ! isset( $formatted_args['post_filter']['bool']['must'] ) || ! is_array( $formatted_args['post_filter']['bool']['must'] ) ) {
			$formatted_args['post_filter']['bool']['must'] = array();
		}

		$formatted_args['post_filter']['bool']['must'][] = array(
			'term' => array(
				'post_author.id' => $user_id,
			),
		);

		return $formatted_args;
	}

	/**
	 * Whether the query includes the chart post type.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return bool
	 */
	private function query_includes_charts( array $args ): bool {
		$post_type = $args['post_type'] ?? '';
		if ( is_array( $post_type ) ) {
			return in_array( 'chart', $post_type, true );
		}
		return 'chart' === $post_type;
	}

	/**
	 * Whether the current user can edit others' posts for this query.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return bool
	 */
	private function can_edit_others_for_query( array $args ): bool {
		$post_types = $args['post_type'] ?? 'post';
		if ( ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		}

		foreach ( $post_types as $post_type ) {
			if ( ! is_string( $post_type ) || '' === $post_type ) {
				continue;
			}
			$pto = function_exists( 'get_post_type_object' ) ? get_post_type_object( $post_type ) : null;
			$cap = ( $pto && isset( $pto->cap->edit_others_posts ) )
				? (string) $pto->cap->edit_others_posts
				: 'edit_others_posts';
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( $cap ) ) {
				return false;
			}
		}

		return true;
	}
}
