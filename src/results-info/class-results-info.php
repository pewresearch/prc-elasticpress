<?php
/**
 * Facets Results Info.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

/**
 * Block Name:        Facet Results info
 * Description:       Display the number of results and the range of results being displayed
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Seth Rubenstein
 *
 * @package           prc-platform
 */
class Results_Info {
	/**
	 * The constructor.
	 *
	 * @param object $loader The loader.
	 */
	public function __construct( $loader ) {
		$this->init( $loader );
	}

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param object $loader The loader.
	 */
	public function init( $loader = null ) {
		if ( null !== $loader ) {
			$loader->add_action( 'init', $this, 'block_init' );
		}
	}

	/**
	 * Register the block.
	 *
	 * @hook init
	 */
	public function block_init() {
		register_block_type_from_metadata(
			PRC_ELASTICPRESS_DIR . '/build/results-info'
		);
	}
}
