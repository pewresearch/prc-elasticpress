/**
 * Facets Context Provider Block
 *
 * This block provides faceting context to child blocks and manages the overall facet state.
 * It acts as a wrapper that enables facet functionality for query loops and facet UI blocks.
 *
 * @package
 * @since 1.0.0
 */

/**
 * External Dependencies
 */

/**
 * WordPress Dependencies
 */
import { registerBlockType, unregisterBlockType } from '@wordpress/blocks';

/**
 * Internal Dependencies
 */
import icon from './icon';
import edit from './edit';
import save from './save';
import './style.scss';

import metadata from './block.json';

const { name } = metadata;

const settings = {
	icon,
	edit,
	save,
};

/**
 * Register the Facets Context Provider block.
 *
 * This block provides context for facet operations and must be present
 * on the page for facets to function properly. It supports only a single
 * instance per page and enables client-side navigation.
 *
 * @see https://developer.wordpress.org/block-editor/developers/block-api/#registering-a-block
 */
registerBlockType(name, { ...metadata, ...settings });

// Unregister ElasticPress facet block to avoid conflicts
unregisterBlockType('elasticpress/facet');
