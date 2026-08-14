<?php
/**
 * Facets Context Provider.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

/**
 * This class is responsible for providing both a server level and client level context for facets data. This supplies facet data to innerblocks within. The server level context is used to pre-fetch data and the client level context is used to manage the state of the facets.
 */
class Context_Provider {
	/**
	 * The facets data.
	 *
	 * @var array
	 */
	public $facets = false;

	/**
	 * The pagination data.
	 *
	 * @var array
	 */
	public $pagination = false;

	/**
	 * The selected facets.
	 *
	 * @var array
	 */
	public $selected = array();

	/**
	 * Whether facet controls are disabled (ES degraded mode).
	 *
	 * @var bool
	 */
	public $is_disabled = false;

	/**
	 * The constructor.
	 *
	 * @param string $loader The loader.
	 */
	public function __construct( $loader ) {
		$this->init( $loader );
	}

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $loader The loader.
	 */
	public function init( $loader = null ) {
		if ( null !== $loader ) {
			$loader->add_action( 'init', $this, 'block_init' );
			$loader->add_filter( 'pre_render_block', $this, 'hoist_facet_data_to_pre_render_stage', 10, 3 );
			$loader->add_filter( 'render_block_context', $this, 'add_facet_data_to_context', 10, 2 );
		}
	}

	/**
	 * Register the block.
	 *
	 * @hook init
	 */
	public function block_init() {
		register_block_type_from_metadata(
			PRC_ELASTICPRESS_DIR . '/build/context-provider',
			array(
				'render_callback' => array( $this, 'render_block_callback' ),
			)
		);
	}

	/**
	 * Fetch the facets data ONCE and store on server memory.
	 *
	 * @hook pre_render_block
	 *
	 * @param mixed $pre_render The pre render.
	 * @param mixed $parsed_block The parsed block.
	 * @param mixed $parent_block_instance The parent block instance.
	 * @return null
	 */
	public function hoist_facet_data_to_pre_render_stage( $pre_render, $parsed_block, $parent_block_instance ) {
		unset( $parent_block_instance );
		if ( 'prc-ep/facets-context-provider' === $parsed_block['blockName'] ) {
			global $wp_query;
			$facets_api       = new ElasticPress_Facets_API( $wp_query->query );
			$this->facets     = $facets_api->get_facets();
			$this->pagination = $facets_api->get_pagination();
			$this->selected   = $facets_api->selected;
			$this->is_disabled = (bool) $facets_api->is_degraded;
		}
		return null;
	}

	/**
	 * Construct structured tokens from selected facets.
	 *
	 * @return array
	 */
	public function get_tokens_from_selected_facets() {
		$tokens = array();
		foreach ( $this->selected as $selected_facet => $selected_values ) {
			if ( ! is_array( $selected_values ) ) {
				continue;
			}
			foreach ( $selected_values as $selected_value ) {
				$label = $selected_value;
				if ( 'time_since' === $selected_facet ) {
					$labels = ElasticPress_Middleware::get_time_since_choices();
					$label  = $labels[ $selected_value ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $selected_value ) );
				} elseif ( taxonomy_exists( $selected_facet ) ) {
					$term = get_term_by( 'slug', $selected_value, $selected_facet );
					if ( $term && ! is_wp_error( $term ) ) {
						$label = $term->name;
					} else {
						$label = ucwords( str_replace( array( '-', '_' ), ' ', $selected_value ) );
					}
				} else {
					$label = ucwords( str_replace( array( '-', '_' ), ' ', $selected_value ) );
				}
				$tokens[] = array(
					'value' => $selected_facet,
					'slug'  => sanitize_title( $selected_value ),
					'label' => format_label( $label ),
				);
			}
		}
		return $tokens;
	}

	/**
	 * Get the facet data from server memory and apply it to the block context.
	 *
	 * @hook render_block_context
	 *
	 * @param mixed $context The context.
	 * @param mixed $parsed_block The parsed block.
	 * @return mixed
	 */
	public function add_facet_data_to_context( $context, $parsed_block ) {
		if ( ! in_array(
			$parsed_block['blockName'],
			array(
				'prc-ep/facets-context-provider',
				'prc-ep/facet-template',
			),
			true
		) ) {
			return $context;
		}

		$context['prc-ep/facets-context-provider'] = array(
			'selected'     => (object) $this->selected,
			'tokens'       => $this->get_tokens_from_selected_facets(),
			'facets'       => $this->facets,
			'pagination'   => $this->pagination,
			'prefetched'   => array(),
			'isProcessing' => false,
			'isDisabled'   => $this->is_disabled,
			'urlKey'       => 'ep_filter_',
		);

		return $context;
	}

	/**
	 * Render the block callback.
	 *
	 * @param mixed $attributes The attributes.
	 * @param string $content The content.
	 * @param mixed $block The block.
	 * @return mixed
	 */
	public function render_block_callback( $attributes, $content, $block ) {
		unset( $attributes );
		wp_enqueue_script( 'wp-api-fetch' );

		// Add facet data into client memory.
		wp_interactivity_state(
			'prc-ep/facets-context-provider',
			$block->context['prc-ep/facets-context-provider']
		);

		// Find the core/query block and add directives to watch for new facet changes, EP sorting changes, and a class for when facets are processing.
		$tag = new \WP_HTML_Tag_Processor( $content );
		while ( $tag->next_tag() ) {
			if ( $tag->has_class( 'wp-block-query' ) ) {
				$tag->set_attribute( 'data-wp-class--is-processing', 'prc-ep/facets-context-provider::state.isProcessing' );
				$tag->set_attribute( 'data-wp-watch--on-selection', 'prc-ep/facets-context-provider::callbacks.onSelection' );
				$tag->set_attribute( 'data-wp-watch--on-ep-sort-by-update', 'prc-ep/facets-context-provider::callbacks.onEpSortByUpdate' );
			}
			if ( $tag->has_class( 'wp-block-prc-block-tokens-list' ) ) {
				$tag->set_attribute( 'data-wp-class--is-processing', 'prc-ep/facets-context-provider::state.isProcessing' );
			}
			if ( $tag->has_class( 'wp-block-prc-ep-facets-results-info' ) ) {
				$tag->set_attribute( 'data-wp-class--is-processing', 'prc-ep/facets-context-provider::state.isProcessing' );
			}
		}
		$content = $tag->get_updated_html();

		return wp_sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes(
				array(
					'data-wp-class--is-degraded' => 'prc-ep/facets-context-provider::state.isDisabled',
				),
			),
			$content,
		);
	}
}
