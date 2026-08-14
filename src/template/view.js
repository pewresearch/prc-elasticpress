/**
 * Facet Template Block View Script
 *
 * Handles client-side interactions for facet templates using the WordPress Interactivity API.
 * Manages facet selection, URL updates, and result refreshing.
 *
 * @package
 */

/**
 * WordPress Dependencies
 */
import {
	store,
	getContext,
	getServerState,
	withSyncEvent,
	getElement,
} from '@wordpress/interactivity';
import { addQueryArgs } from '@wordpress/url';

/**
 * Format facet count for display.
 * Shows "250+" for counts >= 250 to indicate many results.
 *
 * @param {number} count - The count to format
 * @return {string|number} Formatted count
 */
function formatCount(count) {
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
function getPropertyFromObjects(property, searchValue, values) {
	const choice = values.find((c) => c.value === searchValue);
	return choice ? choice[property] : null;
}

/**
 * Check if debug mode is enabled.
 * Enable by setting window.prcFacetsDebug = true in browser console.
 *
 * @return {boolean} True if debug mode is enabled
 */
function isDebugMode() {
	return window.prcFacetsDebug === true;
}

/**
 * Log debug information to console.
 * Only logs if debug mode is enabled.
 *
 * @param {string} message - The message to log
 * @param {*}      data    - Optional data to log
 */
function debugLog(message, data = null) {
	if (!isDebugMode()) {
		return;
	}

	const timestamp = new Date().toISOString();
	const prefix = '[PRC Facets]';

	if (data !== null) {
		console.log(`${prefix} [${timestamp}] ${message}`, data);
	} else {
		console.log(`${prefix} [${timestamp}] ${message}`);
	}
}

/**
 * Log performance metrics.
 *
 * @param {string} operation - The operation being measured
 * @param {number} startTime - Start time from performance.now()
 */
function debugPerformance(operation, startTime) {
	if (!isDebugMode()) {
		return;
	}

	const duration = performance.now() - startTime;
	debugLog(`Performance: ${operation}`, `${duration.toFixed(2)}ms`);
}

const { state, actions } = store('prc-ep/facets-context-provider', {
	state: {
		mouseEnterPreFetchTimer: 500,
		navigateTimer: 1000,
		epSortByDate: false,
		isProcessing: false,
		get hasPosts() {
			return state?.pagination?.total_rows > 0;
		},
		get postCount() {
			return state?.pagination?.total_rows || 0;
		},
		get getServerSelected() {
			return getServerState().selected;
		},
		get getUpdatedUrl() {
			if (undefined === state.selected) {
				return;
			}
			// Keep ep_sort__by_date in the URL when sort-by-date is active.
			// Initial loads set epSortByDate from the query var without adding
			// that key to state.selected; facet navigations must not drop it.
			const selected = { ...state.selected };
			if (state.epSortByDate) {
				selected.ep_sort__by_date = true;
			} else {
				delete selected.ep_sort__by_date;
			}
			return actions.constructNewUrl(selected);
		},
		get choices() {
			const startTime = performance.now();
			const context = getContext();
			const { facetSlug, limit, facetType } = context;

			debugLog('Getting choices for facet', {
				facetSlug,
				limit,
				facetType,
			});

			if (!facetSlug) {
				return [];
			}
			if (!state.facets[facetSlug]) {
				debugLog('Facet not found in state', facetSlug);
				return [];
			}
			if ('dropdown' === facetType) {
				return [...state.facets[facetSlug].choices].sort((a, b) => {
					// Check if both values are numbers
					const aNum = Number(a.label);
					const bNum = Number(b.label);
					if (!isNaN(aNum) && !isNaN(bNum)) {
						return bNum - aNum; // Reversed the order
					}
					// Otherwise sort alphabetically in reverse
					return b.label.localeCompare(a.label); // Reversed the order
				});
			}
			const choices = state.facets[facetSlug].choices;
			// Sort choices to put selected ones first and then by count
			const sortedChoices = [...choices].sort((a, b) => {
				const aSelected = state.facets[facetSlug].selected.includes(
					a.value
				);
				const bSelected = state.facets[facetSlug].selected.includes(
					b.value
				);
				// First sort by selection status
				if (aSelected !== bSelected) {
					return bSelected - aSelected; // Selected items come first
				}
				// Then sort by count
				return b.count - a.count;
			});
			const result = sortedChoices.slice(0, limit);

			debugPerformance('choices computation', startTime);
			debugLog('Returning choices', { count: result.length });

			return result;
		},
		get expandedChoices() {
			const context = getContext();
			const { facetSlug } = context;
			if (!state.facets[facetSlug]) {
				return [];
			}
			const choices = state.facets[facetSlug].choices;
			// Filter out both selected choices and choices already in the main list
			const filteredChoices = choices.filter((choice) => {
				const isSelected = state.facets[facetSlug].selected.includes(
					choice.value
				);
				const isInMainList = state.choices.includes(choice);
				return !isSelected && !isInMainList;
			});
			// Sort remaining choices by count
			return filteredChoices.sort((a, b) => b.count - a.count);
		},
		get hasChoices() {
			const context = getContext();
			const { facetSlug } = context;
			const { facets } = state;
			return facets[facetSlug].choices.length > 0;
		},
		get hasSelections() {
			const context = getContext();
			const { facetSlug } = context;
			const { facets } = state;
			return facets[facetSlug].selected.length > 0;
		},
		get hasExpandedChoices() {
			return state.expandedChoices && state.expandedChoices.length > 0;
		},
		get isExpanded() {
			const context = getContext();
			const { expanded } = context;
			return expanded;
		},
		// Input Elements State:
		get isInputChecked() {
			const context = getContext();
			const { choice, facetSlug } = context;
			const { value } = choice;
			const { facets } = state;
			return facets[facetSlug].selected.includes(value);
		},
		get isInputDisabled() {
			// ES degraded mode disables all controls.
			if (state.isDisabled) {
				return true;
			}
			const context = getContext();
			const { choice, facetType } = context;
			const { value, facetSlug, type } = choice || {};
			// Checkbox OR facets support ghosts (FacetWP parity): keep clickable at count 0.
			const choiceType = type || facetType;
			if (choiceType === 'checkbox') {
				return false;
			}
			const count = getPropertyFromObjects(
				'count',
				value,
				state.facets[facetSlug]?.choices || []
			);
			return count === 0;
		},
		get isInputError() {
			return false;
		},
		get isInputSuccess() {
			return false;
		},
		get isInputRequired() {
			return false;
		},
		get isInputProcessing() {
			return false;
		},
		get isInputHidden() {
			return false;
		},
		get isInputReadOnly() {
			return false;
		},
		get inputName() {
			const context = getContext();
			const { choice } = context;
			return choice.slug;
		},
		get inputLabel() {
			const context = getContext();
			const { choice } = context;
			const { value, label, facetSlug } = choice;
			const count = getPropertyFromObjects(
				'count',
				value,
				state.facets[facetSlug].choices
			);
			return `${label} (${formatCount(count)})`;
		},
		get inputId() {
			const context = getContext();
			const { choice } = context;
			const { slug, term_id } = choice;
			return `facet_${slug}_${term_id}`;
		},
		get inputValue() {
			const context = getContext();
			const { choice } = context;
			return choice.value;
		},
		get inputPlaceholder() {
			const context = getContext();
			const { placeholder, facetType, facetSlug } = context;
			let _placeholder = placeholder;
			if (
				'dropdown' === facetType &&
				state.facets[facetSlug].selected.length > 0
			) {
				// Get the first value out of selected as a string
				const firstValue =
					state.facets[facetSlug].selected[0].toString();
				_placeholder = firstValue
					.replace(/-/g, ' ')
					.replace(/\b\w/g, (l) => l.toUpperCase());
			}
			return _placeholder;
		},
		get isSelected() {
			const context = getContext();
			const { choice } = context;
			const { tokens } = state;
			const { value } = choice;
			return tokens.includes(value);
		},
		/**
		 * For Form-Input-Select Exclusively:
		 */
		get isOpen() {
			const { expanded } = getContext();
			return expanded;
		},
		get inputOptions() {
			const context = getContext();
			const { facetSlug, facetType, searchTerm } = context;
			const { choices } = getServerState().facets[facetSlug];
			let newChoices = choices;

			// Filter choices based on search term.
			if (searchTerm && searchTerm.length > 0 && choices) {
				const filteredChoices = choices.filter((choice) =>
					choice.label
						.toLowerCase()
						.includes(searchTerm.toLowerCase())
				);
				if (filteredChoices.length > 0) {
					newChoices = filteredChoices;
				} else {
					newChoices = choices;
				}
			}
			// Return the choices as an array of objects with the following properties:
			// - value: The value of the choice.
			// - label: The label of the choice.
			return newChoices.map((choice) => {
				return {
					value: choice.value,
					label: `${choice.label} (${formatCount(choice.count)})`,
				};
			});
		},
		get hasValue() {
			const context = getContext();
			const { facetSlug } = context;
			const { selected } = state.facets[facetSlug];
			return selected.length > 0;
		},
		get hasClearIcon() {
			return false;
		},
		get activeIndex() {
			const context = getContext();
			const { activeIndex } = context;
			return activeIndex || 0;
		},
	},
	actions: {
		/**
		 * Construct the new url to route to by adding the selected facets to the query args.
		 * @param {boolean|object} selected
		 * @return
		 */
		constructNewUrl(selected = false) {
			debugLog('Constructing new URL', { selected });

			const tmp = {};
			if (false === selected) {
				return;
			}
			// Construct a comma separated string for each selected facet.
			Object.keys(selected).forEach((key) => {
				// If the key already has ep_ prefixed (filters or sort) then add it directly
				if (key.startsWith('ep_')) {
					tmp[key] = selected[key];
				} else if (Array.isArray(selected[key])) {
					tmp[`${state.urlKey}${key}`] = selected[key].join(',');
				} else {
					tmp[`${state.urlKey}${key}`] = selected[key];
				}
			});
			// Double check tmp, if it has a key with empty value, remove it.
			Object.keys(tmp).forEach((key) => {
				// Check if tmp[key] is an empty string or an empty array.
				// CHeck if tmp[key] is equal to an object...
				if (tmp[key] === '' || typeof tmp[key] === 'object') {
					delete tmp[key];
				}
			});
			// Remove any existing query args from the url.
			const stableUrl = window.location.href.split('?')[0];
			// Remove any references to /page/1/ or /page/2/ etc,
			// we need to send the user back to the first page.
			const stableUrlClean = stableUrl.replace(/\/page\/\d+\//, '/');
			const finalUrl = addQueryArgs(stableUrlClean, tmp);

			debugLog('URL constructed', {
				original: window.location.href,
				final: finalUrl,
				params: tmp,
			});

			return finalUrl;
		},
		/**
		 * Update the results by using the router to navigate to the new url.
		 * Scroll's the user to the top of the page, gracefully.
		 */
		*updateResults() {
			const startTime = performance.now();
			const currentUrl = window.location.href;
			const newUrl = state.getUpdatedUrl;

			debugLog('Updating results', { currentUrl, newUrl });

			if (newUrl === currentUrl) {
				debugLog('No URL change, skipping update');
				return;
			}

			// console.log(
			// 	'Facets_Context_Provider -> updateResults::',
			// 	state,
			// 	currentUrl,
			// 	newUrl
			// );

			state.isProcessing = true;

			// Process the new url. This will hit the server and return the new state.
			const router = yield import('@wordpress/interactivity-router');
			yield router.actions.navigate(newUrl);

			// console.log(
			// 	'YIELD: Facets_Context_Provider <- updateResults::',
			// 	getServerState(),
			// 	currentUrl,
			// 	newUrl
			// );

			// Update local state with state from the server.
			const serverState = getServerState();
			state.facets = serverState.facets;
			state.tokens = serverState.tokens;

			debugLog('Facets state updated', {
				facets: Object.keys(state.facets),
				tokens: state.tokens,
			});

			// Scroll to the top of the page.
			const { ref } = getElement();
			if (ref) {
				ref.scrollIntoView({
					behavior: 'smooth',
					block: 'start',
				});
			} else {
				window.scrollTo({
					top: 0,
					behavior: 'smooth',
				});
			}

			state.isProcessing = false;

			debugPerformance('updateResults', startTime);
		},
		/**
		 * Check if the newUrl is already in the prefetched array, if not add
		 * it and then prefetch the newUrl.
		 * @param {string} newUrl
		 * @return
		 */
		*prefetch(newUrl) {
			const router = yield import('@wordpress/interactivity-router');
			if (state.prefetched.includes(newUrl)) {
				return;
			}
			state.prefetched.push(newUrl);
			yield router.actions.prefetch(newUrl);
		},
		/**
		 * Clear a facet or a facet value from the selected state.
		 * @param {string}     facetSlug
		 * @param {string|int} facetValue
		 * @return
		 */
		onClear: (facetSlug, facetValue = null) => {
			debugLog('Clearing facet', { facetSlug, facetValue });

			// Clearing research-teams on a /{team}/datasets path navigates to
			// the unscoped datasets archive so path-scoped preload stays consistent.
			if (
				facetSlug === 'research-teams' &&
				!facetValue &&
				typeof window !== 'undefined' &&
				/\/[^/]+\/datasets\/?$/.test(window.location.pathname)
			) {
				const nextPath = window.location.pathname.replace(
					/\/[^/]+\/datasets\/?$/,
					'/datasets/'
				);
				window.location.assign(nextPath);
				return;
			}

			// Because onClear actions occur after routing
			// has occured we need to get the selected from the server state.
			const currentlySelected = state.selected;

			// If there is no facetSlug then clear all selected facets and run updateResults.
			if (!facetSlug) {
				debugLog('Clearing all facets');
				state.selected = {};
				actions.updateResults();
				return;
			}

			// If there is a facet value remove it from the given
			// facetSlug but keep the other selected facets.
			if (facetValue) {
				currentlySelected[facetSlug] = currentlySelected[
					facetSlug
				].filter((item) => item !== facetValue);
				state.selected = { ...currentlySelected };

				debugLog('Removed facet value', {
					facetSlug,
					facetValue,
					remaining: currentlySelected[facetSlug],
				});

				return;
			}

			currentlySelected[facetSlug] = [];
			state.selected = { ...currentlySelected };
			return state.selected;
		},
		/**
		 * When clicking on the clear button, clear the facet from the selections.
		 */
		clearFacet: () => {
			const { facetSlug } = getContext();
			actions.onClear(facetSlug);
		},
		*onInputMouseEnter() {
			const context = getContext();
			const { facetSlug, value, selected } = context;
			const currentSelected = selected || [];
			const nextSelected = { ...currentSelected, [value]: value };
			const nextUrl = actions.constructNewUrl(nextSelected);
			// Prefetch the possible next url.
			yield actions.prefetch(nextUrl);
		},
		onInputMouseLeave: withSyncEvent((event) => {
			// console.log('onInputMouseLeave', event);
		}),
		onInputFocus: withSyncEvent((event) => {
			const context = getContext();
			const { facetType } = context;
			if ('dropdown' === facetType) {
				context.expanded = !context.expanded;
			}
		}),
		onInputBlur: withSyncEvent((event) => {
			// By default this runs on the on-blur directive on the input element
			// but we also use it as a shortcut to close the listbox on click,

			// Because the on-blur event fires before the click event
			// we need to slow things down a bit, 150 ms should do it...
			let isRunning = false;
			if (!isRunning) {
				isRunning = true;
				const context = getContext();
				const { facetType } = context;
				if ('dropdown' === facetType) {
					setTimeout(() => {
						context.expanded = false;
						isRunning = false;
					}, 150);
				}
			}
		}),
		onInputKeyDown: withSyncEvent((event) => {
			event.preventDefault();
			const context = getContext();
			if ('dropdown' !== context.facetType) {
				return;
			}
			if (event.key === 'Enter') {
				const { activeIndex, facetSlug } = context;
				const { inputOptions, selected } = state;
				const { value } = inputOptions[activeIndex];

				selected[facetSlug] = [value];

				setTimeout(() => {
					context.expanded = false;
				}, 150);
			}
		}),
		onInputKeyUp: withSyncEvent((event) => {
			const context = getContext();
			const { expanded, facetType } = context;
			if ('dropdown' !== facetType) {
				return;
			}
			// Update the search term.
			context.searchTerm = event.target.value;

			if (!expanded) {
				context.expanded = true;
			}
			if (event.keyCode === 40 && event.key === 'ArrowDown') {
				actions.moveThroughChoices(1, event.target);
				return;
			}
			if (event.keyCode === 38 && event.key === 'ArrowUp') {
				actions.moveThroughChoices(-1, event.target);
				return;
			}

			// if escape key, close the listbox
			if (event.key === 'Escape') {
				context.expanded = false;
			}
		}),
		onDropdownArrowClick: withSyncEvent((event) => {
			const context = getContext();
			const { facetType } = context;
			if ('dropdown' === facetType) {
				context.expanded = !context.expanded;
			}
		}),
		moveThroughChoices: (direction, ref) => {
			const { inputOptions } = state;
			const { activeIndex } = getContext();

			// Determine next active index.
			let nextActive = null;
			if (activeIndex === null || isNaN(activeIndex)) {
				nextActive = 0;
			} else {
				nextActive = activeIndex + direction;
			}
			if (nextActive < 0) {
				nextActive = inputOptions.length - 1;
			}
			if (nextActive >= inputOptions.length) {
				nextActive = 0;
			}

			// Get the next active value.
			const nextActiveValue = inputOptions[nextActive].value;
			// And then scroll the listbox to the active item.
			const listbox = ref.parentElement.parentElement.querySelector(
				'.wp-block-prc-block-form-input-select__list'
			);
			const activeItem = listbox.querySelector(
				`[data-ref-value="${nextActiveValue}"]`
			);
			if (activeItem) {
				// Remove the active class from the previous active item.
				const previousActive = listbox.querySelector('.is-selected');
				if (previousActive) {
					previousActive.classList.remove('is-selected');
				}
				activeItem.classList.add('is-selected');
				activeItem.scrollIntoView({
					block: 'nearest',
				});
			}

			getContext().activeIndex = nextActive;
		},
		onInputOptionClick: withSyncEvent((event) => {
			const context = getContext();
			const { facetSlug, choice } = context;
			const { selected } = state;
			const { value } = choice;
			selected[facetSlug] = [value];
		}),
		onInputCheckboxClick: withSyncEvent((event) => {
			const context = getContext();
			const { choice, facetSlug } = context;
			const { selected } = state;
			const { value, type } = choice;

			debugLog('Facet input clicked', { facetSlug, value, type });

			if (state.isInputDisabled) {
				debugLog('Input disabled, ignoring click');
				return;
			}
			if (!selected[facetSlug] || selected[facetSlug].length === 0) {
				selected[facetSlug] = [value];
				debugLog('Added first selection', { facetSlug, value });
			} else if (selected[facetSlug].includes(value)) {
				selected[facetSlug] = selected[facetSlug].filter(
					(item) => item !== value
				);
				debugLog('Removed selection', { facetSlug, value });
			} else if ('radio' === type) {
				selected[facetSlug] = [value];
				debugLog('Radio selection replaced', { facetSlug, value });
			} else {
				selected[facetSlug] = [...selected[facetSlug], value];
				debugLog('Added to selection', {
					facetSlug,
					value,
					total: selected[facetSlug].length,
				});
			}
		}),
		/**
		 * When clicking on the facet expanded button, toggle the expanded state.
		 */
		onExpand: () => {
			const context = getContext();
			context.expanded = !context.expanded;
		},
		onCollapse: () => {
			// By default this runs on the on-blur directive on the input element
			// but we also use it as a shortcut to close the listbox on click,
			// Because the on-blur event fires before the click event
			// we need to slow things down a bit, 150 ms should do it...
			const context = getContext();
			let isRunning = false;
			if (!isRunning) {
				isRunning = true;
				setTimeout(() => {
					context.expanded = false;
					isRunning = false;
				}, 150);
			}
		},
	},
	callbacks: {
		/**
		 * When a facet is selected, we need to update the results.
		 */
		onSelection() {
			const selected = state.selected;
			const keysLength = Object.keys(selected).length;

			debugLog('Facet selection changed', {
				selected,
				keysLength,
				totalSelections: Object.values(selected).flat().length,
			});

			// Skip refetch when selection is empty (initial load / fully cleared
			// via onClear which calls updateResults itself). Do not toggle
			// isDisabled here — that flag is reserved for ES degraded mode and
			// incorrectly disabled all facet inputs when nothing was selected.
			if (keysLength <= 0) {
				debugLog('No selections, skipping results update');
				return;
			}

			debugLog('Running results update for selections');
			actions.updateResults();
		},
		/**
		 * When the epSortByDate flag is toggled on add ep_sort__by_date
		 * to selected and run updateResults. This will hit the server
		 * and return the new post list sorted by date.
		 */
		onEpSortByUpdate() {
			// if epSortByDate is true then add to selected 'ep_sort__by_date' and run updateResults
			debugLog('ElasticPress sort changed', {
				sortByDate: state.epSortByDate,
			});

			if (state.epSortByDate) {
				state.selected.ep_sort__by_date = true;
			} else {
				delete state.selected.ep_sort__by_date;
			}

			actions.updateResults();
		},
		/**
		 * When the facet is expanded, update the label to be either More or Less.
		 */
		onExpand: () => {
			const context = getContext();
			const { expanded } = context;
			if (expanded) {
				context.expandedLabel = '- Less';
			} else {
				context.expandedLabel = '+ More';
			}
		},
		/**
		 * Facet template mount hook referenced by data-wp-init.
		 */
		onTemplateInit() {
			// Intentionally empty: satisfies data-wp-init on the facet template wrapper.
		},
	},
});

// Initialize debug logging
if (isDebugMode()) {
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

	// Log initial state
	debugLog('Initial facets state', {
		facets: Object.keys(state.facets || {}),
		selected: state.selected || {},
		isProcessing: state.isProcessing,
	});
}
