/**
 * External Dependencies
 */
import { getBlockGapSupportValue } from '@prc/functions';
import clsx from 'clsx';

/**
 * WordPress Dependencies
 */
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

/**
 * Save the block.
 * @param {Object} props            Properties passed to the function.
 * @param {Object} props.attributes Available block attributes.
 * @return {WPElement} Element to render.
 */
export default function Save({ attributes }) {
	const { facetType } = attributes;
	const blockProps = useBlockProps.save({
		className: `is-type-${facetType}`,
		style: {
			'--block-gap': getBlockGapSupportValue(attributes),
		},
	});
	const innerBlocksProps = useInnerBlocksProps.save();
	return <div {...blockProps}>{innerBlocksProps.children}</div>;
}
