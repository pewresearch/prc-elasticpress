/**
 * Facet Template Block
 *
 * Renders individual facet interfaces based on configuration. Supports multiple
 * facet types including checkboxes, radio buttons, dropdowns, ranges, and search inputs.
 * This block must be nested within the Facets Context Provider block.
 *
 * @package
 * @since 2.5.0
 */

/**
 * External Dependencies
 */

/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal Dependencies
 */

import './style.scss';
import './editor.scss';
import edit from './edit';
import save from './save';
import icon from './icon';

import metadata from './block.json';

const { name } = metadata;

/**
 * Block settings configuration.
 * Includes custom icon, edit component, and save component.
 */
const settings = {
	icon,
	edit,
	save,
};

/**
 * Register the Facet Template block.
 *
 * Supports multiple facet types:
 * - checkbox: Multi-select with checkboxes
 * - radio: Single-select with radio buttons
 * - dropdown: Compact single-select dropdown
 * - range: Numeric or date range selection
 * - search: Text-based filtering
 */
registerBlockType(name, { ...metadata, ...settings });
