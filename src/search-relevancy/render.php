<?php
/**
 * Server-side rendering of the `prc-ep/facet-search-relevancy` block.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

wp_interactivity_state(
	'prc-ep/facet-search-relevancy',
	array(
		'epSortByDate' => get_query_var( 'ep_sort__by_date', false ),
	)
);

$block_wrapper_attrs = get_block_wrapper_attributes(
	array(
		'data-wp-interactive' => wp_json_encode(
			array(
				'namespace' => 'prc-ep/facet-search-relevancy',
			)
		),
		'data-wp-init'        => 'callbacks.onInit',
	)
);

echo wp_sprintf(
	'<div %1$s>%2$s</div>',
	$block_wrapper_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$content, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
);
