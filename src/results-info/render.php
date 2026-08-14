<?php
/**
 * Server-side rendering of the `prc-ep/facets-results-info` block.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

$target_namespace = array_key_exists( 'interactiveNamespace', $attributes ) && ! empty( $attributes['interactiveNamespace'] )
	? $attributes['interactiveNamespace']
	: 'prc-ep/facets-context-provider';

$block_wrapper_attrs = get_block_wrapper_attributes(
	array(
		'id'                  => wp_unique_id( 'prc-platform-facets-results-info-' ),
		'data-wp-interactive' => wp_json_encode(
			array(
				'namespace' => $target_namespace,
			)
		),
	)
);

echo wp_sprintf(
	'<div %1$s><span data-wp-text="state.resultsText">Displaying 1-10 of ? results</span></div>',
	$block_wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
);
