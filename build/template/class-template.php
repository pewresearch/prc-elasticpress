<?php
/**
 * Facet Template.
 *
 * @package PRC\Platform\ElasticPress
 */

namespace PRC\Platform\ElasticPress;

use WP_Block;
use WP_Block_Parser_Block;
use WP_HTML_Tag_Processor;

/**
 * Block Name:        Facet Template
 * Description:       Display a facet given its slug and type as a block
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Pew Research Center
 *
 * @package           prc-platform
 */
class Template {
	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader instance.
	 */
	public function __construct( $loader ) {
		$this->init( $loader );
	}

	/**
	 * Initialize the block.
	 *
	 * @param Loader $loader The loader instance.
	 */
	public function init( $loader = null ) {
		if ( null !== $loader ) {
			$loader->add_action( 'init', $this, 'block_init' );
		}
	}

	/**
	 * This function takes the innerblocks provided, renders the first bock as a template, and then modifies specific html attributes to render a dropdown facet.
	 *
	 * @param array $facet The facet data.
	 * @param array $inner_blocks The inner blocks to render.
	 * @return string
	 */
	public function render_dropdown_facet( $facet, $inner_blocks ) {
		$field_template = $inner_blocks[0]; // The innerblocks should contain the template for this block, we will render out the html for a default value and then use it as a template for the rest.
		// Force all form-input-select interactivity to be subsumed by facet-template.
		$field_template['attrs']['interactiveSubsumption'] = true;
		$field_template['attrs']['interactiveNamespace']   = 'prc-ep/facet-template';
		$parsed_template                                   = new WP_Block_Parser_Block(
			$field_template['blockName'],
			$field_template['attrs'],
			$field_template['innerBlocks'],
			$field_template['innerHTML'],
			$field_template['innerContent']
		);

		$rendered_template = (
			new WP_Block(
				(array) $parsed_template,
				array()
			)
		)->render();

		// Replace data-wp-each--option with data-wp-each--choice.
		$rendered_template = str_replace(
			'data-wp-each--option',
			'data-wp-each--choice',
			$rendered_template
		);
		// Find all instances of context.option and replace them with context.choice.
		$rendered_template = str_replace(
			'context.option',
			'context.choice',
			$rendered_template
		);

		return $rendered_template;
	}

	/**
	 * This function takes the innerblocks provided, renders the first bock as a template, and then modifies specific html attributes
	 * for ingestiong by the interactivity api, converting them to wp-directives.
	 *
	 * @param array  $inner_blocks The inner blocks to render.
	 * @param string $template_data_src The data source for the template.
	 * @return string
	 */
	public function render_checkbox_radio_facet_template( $inner_blocks, $template_data_src ) {
		$field_template = $inner_blocks[0]; // The innerblocks should contain the template for this block, we will render out the html for a default value and then use it as a template for the rest.

		$parsed_template = new WP_Block_Parser_Block(
			$field_template['blockName'],
			$field_template['attrs'],
			$field_template['innerBlocks'],
			$field_template['innerHTML'],
			$field_template['innerContent']
		);

		$rendered_template = (
			new WP_Block(
				(array) $parsed_template,
				array()
			)
		)->render();

		return wp_sprintf(
			'<template data-wp-each--choice="%s" data-wp-each-key="context.choice.value">%s</template>',
			$template_data_src,
			$rendered_template,
		);
	}

	/**
	 * Render the block callback.
	 *
	 * @param array    $attributes The block attributes.
	 * @param string   $content The block content.
	 * @param WP_Block $block The block instance.
	 * @return string
	 */
	public function render_block_callback( $attributes, $content, $block ) {
		$target_namespace = 'prc-ep/facets-context-provider';
		$facets           = $block->context[ $target_namespace ]['facets'];
		if ( empty( $facets ) ) {
			return '<!-- No facets data -->';
		}

		$facet_type  = array_key_exists( 'facetType', $attributes ) ? $attributes['facetType'] : 'checkbox';
		$facet_limit = array_key_exists( 'facetLimit', $attributes ) ? $attributes['facetLimit'] : 10;
		$facet_name  = array_key_exists( 'facetName', $attributes ) ? $attributes['facetName'] : null;
		$facet_label = array_key_exists( 'facetLabel', $attributes ) ? $attributes['facetLabel'] : '';
		$facet_slug  = $facet_name;

		if ( ! isset( $facets[ $facet_slug ] ) ) {
			return '<!-- No facet data -->';
		}

		$facet = $facets[ $facet_slug ];

		$standard_template = '';
		$expanded_template = '';

		// Set up the standard template.
		if ( in_array( $facet_type, array( 'dropdown' ) ) ) {
			$standard_template .= $this->render_dropdown_facet( $facet, $block->parsed_block['innerBlocks'] );
		} elseif ( in_array( $facet_type, array( 'range' ) ) ) {
			$standard_template .= '<!-- Range facets are not yet supported. -->';
		} else {
			$standard_template = $this->render_checkbox_radio_facet_template(
				$block->parsed_block['innerBlocks'],
				'state.choices',
			);
			$expanded_template = $this->render_checkbox_radio_facet_template(
				$block->parsed_block['innerBlocks'],
				'state.expandedChoices',
			);
		}

		// If there are expanded choices, set up the expanded template.
		$expanded_template = ! empty( $expanded_template ) ? wp_sprintf(
			'<button class="wp-block-prc-ep-facet-template__list-expanded-button" data-wp-on--click="%1$s" data-wp-text="%2$s"></button><div class="wp-block-prc-ep-facet-template__list-expanded">%3$s</div>',
			'actions.onExpand',
			'context.expandedLabel',
			$expanded_template
		) : '';

		if ( empty( $standard_template ) ) {
			return '<!-- Could not render this facet -->';
		}

		$tag = new WP_HTML_Tag_Processor( $content );
		$tag->next_tag( 'div' );
		$style     = $tag->get_attribute( 'style' );
		$classname = $tag->get_attribute( 'class' );
		// Find the placeholder attribute on the input element inside.
		$facet_placeholder = wp_strip_all_tags( $facet_label );
		while ( $tag->next_tag(
			array(
				'tag_name' => 'input',
			)
		) ) {
			$input_placeholder = $tag->get_attribute( 'placeholder' );
			if ( ! empty( $input_placeholder ) ) {
				$facet_placeholder = $input_placeholder;
				break;
			}
		}

		$block_wrapper_attrs = get_block_wrapper_attributes(
			array(
				'data-wp-interactive'                 => $target_namespace,
				'data-wp-key'                         => $facet_slug,
				'data-wp-context'                     => wp_json_encode(
					array(
						'expanded'      => false,
						'expandedLabel' => '+ More',
						'placeholder'   => $facet_placeholder,
						'limit'         => $facet_limit,
						'facetSlug'     => $facet_slug,
						'facetType'     => $facet_type,
					)
				),
				'data-wp-init'                        => 'callbacks.onTemplateInit',
				'data-wp-watch--on-expand'            => 'callbacks.onExpand',
				'data-wp-class--is-processing'        => 'state.isProcessing',
				'data-wp-class--has-choices'          => 'state.hasChoices',
				'data-wp-class--has-expanded-choices' => 'state.hasExpandedChoices',
				'data-wp-class--has-selections'       => 'state.hasSelections',
				'data-wp-class--is-expanded'          => 'state.isExpanded',
				'style'                               => $style,
				'class'                               => $classname,
			)
		);

		if ( in_array( $facet_type, array( 'dropdown', 'range' ) ) ) {
			$template = '<div %1$s>%2$s %3$s</div>';
		} else {
			$template = '<div %1$s>%2$s<div class="wp-block-prc-ep-facet-template__list">%3$s</div>%4$s</div>';
		}

		$clear_icon = \PRC\Platform\Icons\render( 'solid', 'circle-xmark' );

		$label = wp_sprintf(
			'<h5 class="wp-block-prc-ep-facet-template__label"><span>%1$s</span><span><button class="wp-block-prc-ep-facet-template__clear" data-wp-on--click="%2$s">%3$s</button></span></h5>',
			$facet_label,
			'actions.clearFacet',
			$clear_icon,
		);

		return wp_sprintf(
			$template,
			$block_wrapper_attrs,
			$label,
			$standard_template,
			$expanded_template,
		);
	}

	/**
	 * Register the block.
	 *
	 * @hook init
	 */
	public function block_init() {
		register_block_type_from_metadata(
			PRC_ELASTICPRESS_DIR . '/build/template',
			array(
				'render_callback' => array( $this, 'render_block_callback' ),
			)
		);
	}
}
