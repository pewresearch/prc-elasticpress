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
 * Internal Dependencies
 */
import {
	announceDebugMode,
	collapseAfterDelay,
	debugLog,
	debugPerformance,
	filterChoicesBySearch,
	formatCount,
	getPropertyFromObjects,
	hasSameFacetQueryArgs,
	highlightListboxChoice,
	leaveTeamDatasetsPath,
	nextActiveIndex,
	slugToLabel,
	sortChoicesBySelection,
	sortDropdownChoices,
	toggleSelection,
} from './facet-helpers';

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
				return sortDropdownChoices(state.facets[facetSlug].choices);
			}
			const startTime = performance.now();
			// Selected choices first, then by count.
			const result = sortChoicesBySelection(
				state.facets[facetSlug].choices,
				state.facets[facetSlug].selected
			).slice(0, limit);

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
			const { slug, term_id: termId } = choice;
			return `facet_${slug}_${termId}`;
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
				_placeholder = slugToLabel(state.facets[facetSlug].selected[0]);
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
			const { facetSlug, searchTerm } = context;
			const { choices } = getServerState().facets[facetSlug];

			// Return the choices as an array of objects with the following properties:
			// - value: The value of the choice.
			// - label: The label of the choice.
			return filterChoicesBySearch(choices, searchTerm).map((choice) => {
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
		 * @param {boolean|Object} selected Selected facets, or false for none.
		 * @return {string|undefined} The new URL, or undefined when nothing is selected.
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
					return;
				}
				// Each facet names its own URL param; taxonomies share the default prefix.
				const param =
					state.facets?.[key]?.urlParam || `${state.urlKey}${key}`;
				tmp[param] = Array.isArray(selected[key])
					? selected[key].join(',')
					: selected[key];
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
			const currentUrl = window.location.href;
			const newUrl = state.getUpdatedUrl;

			debugLog('Updating results', { currentUrl, newUrl });

			// The watch callbacks run once on page load; navigating then would
			// strip non-facet params, drop /page/N/, and scroll to the results.
			if (!newUrl || hasSameFacetQueryArgs(newUrl, currentUrl)) {
				debugLog('No facet change, skipping update');
				return;
			}

			// console.log(
			// 	'Facets_Context_Provider -> updateResults::',
			// 	state,
			// 	currentUrl,
			// 	newUrl
			// );

			const startTime = performance.now();
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
		 * @param {string} newUrl The URL to prefetch.
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
		 * @param {string}        facetSlug  The facet to clear.
		 * @param {string|number} facetValue A single value to clear, or null for the whole facet.
		 * @return {Object|undefined} The remaining selection when a whole facet was cleared.
		 */
		onClear: (facetSlug, facetValue = null) => {
			debugLog('Clearing facet', { facetSlug, facetValue });

			if (leaveTeamDatasetsPath(facetSlug, facetValue)) {
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
			const { value, selected } = context;
			const currentSelected = selected || [];
			const nextSelected = { ...currentSelected, [value]: value };
			const nextUrl = actions.constructNewUrl(nextSelected);
			// Prefetch the possible next url.
			yield actions.prefetch(nextUrl);
		},
		onInputMouseLeave: withSyncEvent(() => {
			// Intentionally empty: no hover-out behavior yet.
		}),
		onInputFocus: withSyncEvent(() => {
			const context = getContext();
			const { facetType } = context;
			if ('dropdown' === facetType) {
				context.expanded = !context.expanded;
			}
		}),
		onInputBlur: withSyncEvent(() => {
			// Runs on the on-blur directive, and doubles as a shortcut to close
			// the listbox on click.
			const context = getContext();
			if ('dropdown' === context.facetType) {
				collapseAfterDelay(context);
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

				collapseAfterDelay(context);
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
		onDropdownArrowClick: withSyncEvent(() => {
			const context = getContext();
			const { facetType } = context;
			if ('dropdown' === facetType) {
				context.expanded = !context.expanded;
			}
		}),
		moveThroughChoices: (direction, ref) => {
			const { inputOptions } = state;
			const { activeIndex } = getContext();

			const nextActive = nextActiveIndex(
				activeIndex,
				direction,
				inputOptions.length
			);

			// Highlight the next active value and scroll the listbox to it.
			highlightListboxChoice(ref, inputOptions[nextActive].value);

			getContext().activeIndex = nextActive;
		},
		onInputOptionClick: withSyncEvent(() => {
			const context = getContext();
			const { facetSlug, choice } = context;
			const { selected } = state;
			const { value } = choice;
			selected[facetSlug] = [value];
		}),
		onInputCheckboxClick: withSyncEvent(() => {
			const context = getContext();
			const { choice, facetSlug } = context;
			const { selected } = state;
			const { value, type } = choice;

			debugLog('Facet input clicked', { facetSlug, value, type });

			if (state.isInputDisabled) {
				debugLog('Input disabled, ignoring click');
				return;
			}
			selected[facetSlug] = toggleSelection(
				selected[facetSlug],
				value,
				type
			);
			debugLog('Facet selection updated', {
				facetSlug,
				value,
				total: selected[facetSlug].length,
			});
		}),
		/**
		 * When clicking on the facet expanded button, toggle the expanded state.
		 */
		onExpand: () => {
			const context = getContext();
			context.expanded = !context.expanded;
		},
		onCollapse: () => {
			// Runs on the on-blur directive, and doubles as a shortcut to close
			// the listbox on click.
			collapseAfterDelay(getContext());
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
announceDebugMode(state);
