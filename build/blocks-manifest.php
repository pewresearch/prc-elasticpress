<?php
// This file is generated. Do not modify it manually.
return array(
	'context-provider' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-ep/facets-context-provider',
		'version' => '1.0.0',
		'title' => 'Facets Context Provider',
		'description' => 'Handles passing facets context to query blocks and facet UI blocks.',
		'category' => 'theme',
		'keywords' => array(
			'facets',
			'query',
			'loop'
		),
		'supports' => array(
			'anchor' => false,
			'html' => false,
			'reusable' => false,
			'multiple' => false,
			'interactivity' => array(
				'clientNavigation' => true
			)
		),
		'usesContext' => array(
			'postType',
			'templateSlug',
			'previewPostType',
			'facetsContextProvider'
		),
		'textdomain' => 'prc-ep-facets-context-provider',
		'editorScript' => 'file:./index.js',
		'style' => 'file:./style-index.css'
	),
	'results-info' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-ep/facets-results-info',
		'version' => '1.0.0',
		'title' => 'Facets Results Info',
		'category' => 'theme',
		'description' => 'Display the number of results and the range of results being displayed.',
		'attributes' => array(
			'interactiveNamespace' => array(
				'type' => 'string',
				'default' => 'prc-ep/facets-context-provider'
			)
		),
		'supports' => array(
			'anchor' => true,
			'html' => false,
			'multiple' => false,
			'reusable' => false,
			'color' => array(
				'text' => true,
				'link' => true
			),
			'spacing' => array(
				'blockGap' => true,
				'margin' => array(
					'top',
					'bottom'
				),
				'padding' => true,
				'__experimentalDefaultControls' => array(
					'padding' => true
				)
			),
			'typography' => array(
				'fontSize' => true,
				'lineHeight' => true,
				'__experimentalFontFamily' => true,
				'__experimentalLetterSpacing' => true,
				'__experimentalDefaultControls' => array(
					'fontSize' => true,
					'__experimentalFontFamily' => true
				)
			),
			'interactivity' => array(
				'clientNavigation' => true
			)
		),
		'usesContext' => array(
			'postType',
			'templateSlug',
			'previewPostType',
			'facetsContextProvider'
		),
		'textdomain' => 'facets-pager',
		'editorScript' => 'file:./index.js',
		'style' => 'file:./style-index.css',
		'render' => 'file:./render.php',
		'viewScriptModule' => 'file:./view.js'
	),
	'search-relevancy' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-ep/facet-search-relevancy',
		'version' => '0.1.0',
		'title' => 'Facet Search Relevancy',
		'category' => 'theme',
		'description' => 'Toggle search sorting by relevancy or by datetime',
		'attributes' => array(
			'allowedBlocks' => array(
				'type' => 'array',
				'default' => array(
					'prc-block/form-input-checkbox'
				)
			),
			'orientation' => array(
				'type' => 'string',
				'default' => 'vertical'
			)
		),
		'supports' => array(
			'anchor' => true,
			'html' => false,
			'multiple' => false,
			'spacing' => array(
				'blockGap' => true,
				'margin' => array(
					'top',
					'bottom'
				),
				'padding' => true,
				'__experimentalDefaultControls' => array(
					'padding' => true
				)
			),
			'typography' => array(
				'fontSize' => true,
				'__experimentalFontFamily' => true,
				'__experimentalDefaultControls' => array(
					'fontSize' => true,
					'__experimentalFontFamily' => true
				)
			),
			'interactivity' => true
		),
		'textdomain' => 'facet-search-relevancy',
		'editorScript' => 'file:./index.js',
		'editorStyle' => 'file:./index.css',
		'style' => 'file:./style-index.css',
		'render' => 'file:./render.php',
		'viewScriptModule' => 'file:./view.js'
	),
	'template' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-ep/facet-template',
		'version' => '2.5.0',
		'title' => 'Facet Template',
		'category' => 'theme',
		'description' => 'Construct a facet interface using its slug, type, and label as a block. Depending on the type, the block will serve as a template for rendering repeated facet values. For instance, a checkbox facet will display a checkbox for each value, a radio facet will show a radio button for each value, and a dropdown facet will present a dropdown with the values.',
		'attributes' => array(
			'facetName' => array(
				'type' => 'string',
				'default' => ''
			),
			'facetType' => array(
				'type' => 'string',
				'enum' => array(
					'checkbox',
					'radio',
					'dropdown',
					'range',
					'search'
				)
			),
			'facetLabel' => array(
				'type' => 'string'
			),
			'facetLimit' => array(
				'type' => 'number',
				'default' => 10
			),
			'interactiveNamespace' => array(
				'type' => 'string',
				'default' => 'prc-ep/facets-context-provider'
			)
		),
		'supports' => array(
			'anchor' => true,
			'html' => false,
			'interactivity' => true,
			'spacing' => array(
				'blockGap' => true,
				'margin' => array(
					'top',
					'bottom'
				),
				'padding' => true,
				'__experimentalDefaultControls' => array(
					'padding' => true
				)
			),
			'__experimentalBorder' => array(
				'color' => true,
				'width' => true,
				'radius' => true
			),
			'typography' => array(
				'fontSize' => true,
				'lineHeight' => true,
				'__experimentalFontFamily' => true,
				'__experimentalFontWeight' => true,
				'__experimentalFontStyle' => true,
				'__experimentalTextTransform' => true,
				'__experimentalTextDecoration' => true,
				'__experimentalLetterSpacing' => true,
				'__experimentalDefaultControls' => array(
					'fontSize' => true,
					'__experimentalFontFamily' => true
				)
			)
		),
		'selectors' => array(
			'root' => '.wp-block-prc-ep-facet-template',
			'typography' => 'h5'
		),
		'usesContext' => array(
			'postType',
			'templateSlug',
			'previewPostType',
			'facetsContextProvider'
		),
		'providesContext' => array(
			'prc-ep/template/facetType' => 'facetType',
			'prc-ep/template/facetName' => 'facetName',
			'prc-ep/template/facetLabel' => 'facetLabel'
		),
		'styles' => array(
			array(
				'name' => 'default',
				'label' => 'Default',
				'isDefault' => true
			),
			array(
				'name' => 'no-label',
				'label' => 'No Label'
			)
		),
		'ancestor' => array(
			'prc-ep/facets-context-provider'
		),
		'textdomain' => 'facet-template',
		'editorScript' => 'file:./index.js',
		'editorStyle' => 'file:./index.css',
		'style' => 'file:./style-index.css',
		'viewScriptModule' => 'file:./view.js'
	)
);
