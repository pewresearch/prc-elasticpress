/**
 * External Dependencies
 */

/**
 * WordPress Dependencies
 */
import { useMemo } from '@wordpress/element';
import { InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { createBlocksFromInnerBlocksTemplate } from '@wordpress/blocks';

/**
 * Internal Dependencies
 */
import useFacetSettings from '../context-provider/use-facet-settings';

const getTemplateForType = (type, name) => {
	const defaultAttrs = {
		interactiveNamespace: 'prc-ep/facets-context-provider',
	};
	const label = `${name
		.replace(/_/g, ' ')
		.replace(/\w\S*/g, (w) =>
			w.replace(/^\w/, (c) => c.toUpperCase())
		)} Value`;
	switch (type) {
		case 'checkbox':
			return [
				[
					'prc-block/form-input-checkbox',
					{
						type: 'checkbox',
						label,
						interactiveSubsumption: true,
						...defaultAttrs,
					},
				],
			];
		case 'dropdown':
			return [
				[
					'prc-block/form-input-select',
					{
						placeholder: label,
						displayLabel: false,
						metadata: {
							name,
						},
						interactiveSubsumption: true,
						...defaultAttrs,
					},
				],
			];
		case 'range':
			return [
				[
					'prc-block/form-input-select',
					{
						placeholder: label,
						displayLabel: false,
						metadata: {
							name,
						},
						interactiveSubsumption: true,
						...defaultAttrs,
					},
				],
				[
					'prc-block/form-input-select',
					{
						placeholder: label,
						displayLabel: false,
						metadata: {
							name,
						},
						interactiveSubsumption: true,
						...defaultAttrs,
					},
				],
			];
		case 'search':
			return [
				[
					'prc-block/form-input-text',
					{
						type: 'text',
						label,
						interactiveSubsumption: true,
						...defaultAttrs,
					},
				],
			];
		default:
			// Default to Radio
			return [
				[
					'prc-block/form-input-checkbox',
					{
						type: 'radio',
						label,
						interactiveSubsumption: true,
						...defaultAttrs,
					},
				],
			];
	}
};

export default function Controls({
	attributes,
	setAttributes,
	context,
	clientId,
}) {
	const { replaceInnerBlocks } = useDispatch('core/block-editor');

	const { facetName, facetLimit } = attributes;

	const { facetsContextProvider, templateSlug } = context;
	const hasProviderContext = Boolean(facetsContextProvider);
	const { settings: restSettings, isLoading } = useFacetSettings(
		templateSlug || 'archive',
		!hasProviderContext
	);
	const facetSettings = facetsContextProvider || restSettings;

	const options = useMemo(() => {
		if (isLoading && !hasProviderContext) {
			return [
				{
					label: 'Loading facets…',
					value: '',
				},
			];
		}
		if (!facetSettings) {
			return [
				{
					label: 'No Facets Found',
					value: '',
				},
			];
		}
		const newOptions = [
			{
				label: 'Select a Facet',
				value: '',
			},
		];
		Object.keys(facetSettings).forEach((facetKey) => {
			newOptions.push({
				label: facetSettings[facetKey].label,
				value: facetSettings[facetKey].name,
			});
		});
		return newOptions;
	}, [facetSettings, hasProviderContext, isLoading]);

	return (
		<InspectorControls>
			<PanelBody title="Facet Template">
				<div>
					<SelectControl
						label="Facet"
						help="Select a facet registered with the PRC Platform. Updating this will reset the template and any style changes."
						options={options}
						value={facetName}
						disabled={
							(isLoading && !hasProviderContext) || !facetSettings
						}
						onChange={(value) => {
							const name = value;
							if (!name || !facetSettings?.[name]) {
								return;
							}
							const { type, label } = facetSettings[name];
							setAttributes({
								facetName: name,
								facetType: type,
								facetLabel: label,
							});
							const defaultTemplate = getTemplateForType(
								type,
								name
							);
							replaceInnerBlocks(
								clientId,
								createBlocksFromInnerBlocksTemplate(
									defaultTemplate
								),
								false
							);
						}}
					/>
					<NumberControl
						label="Limit"
						help="Number of choices to display. Additional choices will be hidden behind a 'More' button."
						value={facetLimit}
						onChange={(value) =>
							setAttributes({ facetLimit: value })
						}
					/>
				</div>
			</PanelBody>
		</InspectorControls>
	);
}
