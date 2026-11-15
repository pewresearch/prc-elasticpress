/**
 * Pure helpers for the facet template view script.
 *
 * These functions hold no store state so that `view.js` can stay focused on
 * the Interactivity API store.
 *
 * @package
 */

/**
 * WordPress Dependencies
 */
import { getQueryArgs } from '@wordpress/url';

/**
 * Format facet count for display.
 * Shows "250+" for counts >= 250 to indicate many results.
 *
 * @param {number} count - The count to format
 * @return {string|number} Formatted count
 */
export function formatCount(count) {
	return 250 <= count ? '250+' : count;
}

/**
 * Get a property value from an array of objects by searching for a specific value.
 *
 * @param {string} property    - The property to retrieve
 * @param {*}      searchValue - The value to search for
 * @param {Array}  values      - Array of objects to search
 * @return {*} The property value or null if not found
 */
export function getPropertyFromObjects(property, searchValue, values) {
	const choice = values.find((c) => c.value === searchValue);
	return choice ? choice[property] : null;
}

/**
 * Check whether two URLs carry the same facet (ep_*) query args.
 * Other query args (utm_*, gclid, etc.) and /page/N/ do not change facet results.
 *
 * @param {string} a - First URL
 * @param {string} b - Second URL
 * @return {boolean} True if the facet query args match
 */
export function hasSameFacetQueryArgs(a, b) {
	const facetArgs = (url) =>
		JSON.stringify(
			Object.entries(getQueryArgs(url))
				.filter(([key]) => key.startsWith('ep_'))
				.sort(([x], [y]) => x.localeCompare(y))
		);
	return facetArgs(a) === facetArgs(b);
}

/**
 * Check if debug mode is enabled.
 * Enable by setting window.prcFacetsDebug = true in browser console.
 *
 * @return {boolean} True if debug mode is enabled
 */
export function isDebugMode() {
	return window.prcFacetsDebug === true;
}

/**
 * Log debug information to console.
 * Only logs if debug mode is enabled.
 *
 * @param {string} message - The message to log
 * @param {*}      data    - Optional data to log
 */
export function debugLog(message, data = null) {
	if (!isDebugMode()) {
		return;
	}

	const timestamp = new Date().toISOString();
	const prefix = '[PRC Facets]';

	if (data !== null) {
		// eslint-disable-next-line no-console
		console.log(`${prefix} [${timestamp}] ${message}`, data);
	} else {
		// eslint-disable-next-line no-console
		console.log(`${prefix} [${timestamp}] ${message}`);
	}
}

/**
 * Log performance metrics.
 *
 * @param {string} operation - The operation being measured
 * @param {number} startTime - Start time from performance.now()
 */
export function debugPerformance(operation, startTime) {
	if (!isDebugMode()) {
		return;
	}

	const duration = performance.now() - startTime;
	debugLog(`Performance: ${operation}`, `${duration.toFixed(2)}ms`);
}

/**
 * Sort dropdown choices in reverse: numeric labels descending, others Z to A.
 *
 * @param {Array} choices - Facet choices
 * @return {Array} A sorted copy of the choices
 */
export function sortDropdownChoices(choices) {
	return [...choices].sort((a, b) => {
		const aNum = Number(a.label);
		const bNum = Number(b.label);
		if (!isNaN(aNum) && !isNaN(bNum)) {
			return bNum - aNum;
		}
		return b.label.localeCompare(a.label);
	});
}

/**
 * Sort choices with selected ones first, then by count descending.
 *
 * @param {Array} choices  - Facet choices
 * @param {Array} selected - Selected values for the facet
 * @return {Array} A sorted copy of the choices
 */
export function sortChoicesBySelection(choices, selected) {
	return [...choices].sort((a, b) => {
		const aSelected = selected.includes(a.value);
		const bSelected = selected.includes(b.value);
		if (aSelected !== bSelected) {
			return bSelected - aSelected;
		}
		return b.count - a.count;
	});
}

/**
 * Filter choices by a search term. Falls back to all choices when nothing matches.
 *
 * @param {Array}  choices    - Facet choices
 * @param {string} searchTerm - Text typed into the dropdown input
 * @return {Array} The matching choices, or all choices when none match
 */
export function filterChoicesBySearch(choices, searchTerm) {
	if (!searchTerm || searchTerm.length === 0 || !choices) {
		return choices;
	}
	const needle = searchTerm.toLowerCase();
	const filtered = choices.filter((choice) =>
		choice.label.toLowerCase().includes(needle)
	);
	return filtered.length > 0 ? filtered : choices;
}

/**
 * Turn a slug value into a display label ("past-12-months" to "Past 12 Months").
 *
 * @param {string|number} value - Selected value
 * @return {string} Title-cased label
 */
export function slugToLabel(value) {
	return value
		.toString()
		.replace(/-/g, ' ')
		.replace(/\b\w/g, (l) => l.toUpperCase());
}

/**
 * Highlight a choice in the dropdown listbox and scroll it into view.
 *
 * @param {Element}       ref   - The input element that has focus
 * @param {string|number} value - Value of the choice to highlight
 */
export function highlightListboxChoice(ref, value) {
	const listbox = ref.parentElement.parentElement.querySelector(
		'.wp-block-prc-block-form-input-select__list'
	);
	const activeItem = listbox.querySelector(`[data-ref-value="${value}"]`);
	if (!activeItem) {
		return;
	}
	const previousActive = listbox.querySelector('.is-selected');
	if (previousActive) {
		previousActive.classList.remove('is-selected');
	}
	activeItem.classList.add('is-selected');
	activeItem.scrollIntoView({
		block: 'nearest',
	});
}

/**
 * Leave a research team's path-scoped datasets page for the unscoped archive.
 * Clearing research-teams on `/{team}/datasets` navigates to `/datasets/` so
 * path-scoped preload stays consistent.
 *
 * @param {string}      facetSlug  - Facet being cleared
 * @param {string|null} facetValue - Value being cleared, if any
 * @return {boolean} True when a navigation started
 */
export function leaveTeamDatasetsPath(facetSlug, facetValue) {
	if (
		facetSlug !== 'research-teams' ||
		facetValue ||
		typeof window === 'undefined' ||
		!/\/[^/]+\/datasets\/?$/.test(window.location.pathname)
	) {
		return false;
	}
	window.location.assign(
		window.location.pathname.replace(/\/[^/]+\/datasets\/?$/, '/datasets/')
	);
	return true;
}

/**
 * Work out the next active listbox index, wrapping at both ends.
 *
 * @param {number|null} activeIndex - Current index
 * @param {number}      direction   - 1 for down, -1 for up
 * @param {number}      length      - Number of options
 * @return {number} The next index
 */
export function nextActiveIndex(activeIndex, direction, length) {
	const next =
		activeIndex === null || isNaN(activeIndex)
			? 0
			: activeIndex + direction;
	if (next < 0) {
		return length - 1;
	}
	return next >= length ? 0 : next;
}

/**
 * Toggle one clicked value in a facet's selection.
 * Empty selection adds it, a selected value is removed, a radio replaces,
 * and a checkbox appends.
 *
 * @param {Array}         current - Currently selected values
 * @param {string|number} value   - The clicked value
 * @param {string}        type    - Choice type ("radio", "checkbox", ...)
 * @return {Array} The next selection
 */
export function toggleSelection(current, value, type) {
	if (!current || current.length === 0) {
		return [value];
	}
	if (current.includes(value)) {
		return current.filter((item) => item !== value);
	}
	return 'radio' === type ? [value] : [...current, value];
}

/**
 * Log the debug banner and initial state when debug mode is on.
 *
 * @param {Object} state - The store state
 */
export function announceDebugMode(state) {
	if (!isDebugMode()) {
		return;
	}
	debugLog('PRC Facets Debug Mode Enabled', {
		version: '2.5.0',
		instructions: 'Set window.prcFacetsDebug = false to disable',
		availableLogs: [
			'Facet state changes',
			'URL construction',
			'Results updates',
			'Performance metrics',
			'Facet selections',
			'Cache operations',
		],
	});
	debugLog('Initial facets state', {
		facets: Object.keys(state.facets || {}),
		selected: state.selected || {},
		isProcessing: state.isProcessing,
	});
}

/**
 * Collapse a facet's listbox after a short delay.
 * The on-blur event fires before the click event, so closing right away would
 * swallow the click. 150 ms is enough to let the click land first.
 *
 * @param {Object} context - The Interactivity API context for the facet
 */
export function collapseAfterDelay(context) {
	setTimeout(() => {
		context.expanded = false;
	}, 150);
}
