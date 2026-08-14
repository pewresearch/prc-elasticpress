<?php
/**
 * Temporary block name migrations from prc-platform/* facets to prc-ep/*.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

/**
 * Registers parse-time aliases so VIP DB-stored templates keep working until re-saved.
 */
class Block_Migrations {
	/**
	 * Old block name => new block name.
	 *
	 * @var array<string, string>
	 */
	const BLOCK_MAP = array(
		'prc-platform/facets-context-provider' => 'prc-ep/facets-context-provider',
		'prc-platform/facet-template'          => 'prc-ep/facet-template',
		'prc-platform/facets-results-info'     => 'prc-ep/facets-results-info',
		'prc-platform/facet-search-relevancy'  => 'prc-ep/facet-search-relevancy',
	);

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader.
	 */
	public function __construct( $loader ) {
		$loader->add_filter( 'render_block_data', $this, 'migrate_block_names', 5, 1 );
		$loader->add_action( 'init', $this, 'register_legacy_aliases', 30 );
	}

	/**
	 * Rewrite old facet block names during render/parse.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return array
	 */
	public function migrate_block_names( $parsed_block ) {
		if ( ! is_array( $parsed_block ) || empty( $parsed_block['blockName'] ) ) {
			return $parsed_block;
		}

		$old_name = $parsed_block['blockName'];
		if ( isset( self::BLOCK_MAP[ $old_name ] ) ) {
			$parsed_block['blockName'] = self::BLOCK_MAP[ $old_name ];
		}

		if ( ! empty( $parsed_block['attrs']['interactiveNamespace'] ) ) {
			$ns = $parsed_block['attrs']['interactiveNamespace'];
			if ( isset( self::BLOCK_MAP[ $ns ] ) ) {
				$parsed_block['attrs']['interactiveNamespace'] = self::BLOCK_MAP[ $ns ];
			}
		}

		return $parsed_block;
	}

	/**
	 * Expand parent/ancestor refs so legacy aliases accept unmigrated parents.
	 *
	 * @param string[]|null $refs Parent or ancestor block names.
	 * @return string[]|null
	 */
	protected function expand_legacy_block_refs( $refs ) {
		if ( ! is_array( $refs ) ) {
			return $refs;
		}

		$expanded = array();
		foreach ( $refs as $name ) {
			$expanded[] = $name;
			$old_name   = array_search( $name, self::BLOCK_MAP, true );
			if ( false !== $old_name ) {
				$expanded[] = $old_name;
			}
		}

		return array_values( array_unique( $expanded ) );
	}

	/**
	 * Register old block names as aliases of the new block types.
	 *
	 * @hook init
	 * @return void
	 */
	public function register_legacy_aliases() {
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( self::BLOCK_MAP as $old_name => $new_name ) {
			if ( $registry->is_registered( $old_name ) ) {
				continue;
			}

			$new_type = $registry->get_registered( $new_name );
			if ( ! $new_type instanceof \WP_Block_Type ) {
				continue;
			}

			$supports = is_array( $new_type->supports ) ? $new_type->supports : array();
			$supports['inserter'] = false;

			$args = array(
				'api_version'      => $new_type->api_version,
				'title'            => $new_type->title . ' (legacy)',
				'category'         => $new_type->category,
				'parent'           => $this->expand_legacy_block_refs( $new_type->parent ),
				'ancestor'         => $this->expand_legacy_block_refs( $new_type->ancestor ),
				'icon'             => $new_type->icon,
				'description'      => $new_type->description,
				'keywords'         => $new_type->keywords,
				'textdomain'       => $new_type->textdomain,
				'styles'           => $new_type->styles,
				'supports'         => $supports,
				'attributes'       => $new_type->attributes,
				'provides_context' => $new_type->provides_context,
				'uses_context'     => $new_type->uses_context,
				'render_callback'  => $new_type->render_callback,
				'editor_script'    => $new_type->editor_script,
				'editor_style'     => $new_type->editor_style,
				'style'            => $new_type->style,
				'view_script'      => $new_type->view_script,
			);

			if ( ! empty( $new_type->view_script_module_ids ) ) {
				// WP_Block_Type stores module handles on view_script_module_ids (string[]).
				$args['view_script_module_ids'] = $new_type->view_script_module_ids;
			}

			register_block_type( $old_name, $args );
		}
	}
}
