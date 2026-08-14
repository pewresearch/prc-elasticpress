/**
 * WordPress Dependencies
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Fetch registered facet settings for the editor.
 *
 * @param {string}  templateSlug Template slug passed to the REST endpoint.
 * @param {boolean} enabled      When false, skip the request (e.g. provider context already present).
 * @return {{ settings: Object|null, isLoading: boolean }} Facet settings map and loading state.
 */
export default function useFacetSettings(templateSlug, enabled = true) {
	const [settings, setSettings] = useState(null);
	const [isLoading, setIsLoading] = useState(enabled);

	const reduceSettings = (newSettings) => {
		const newFacets = {};
		Object.keys(newSettings).forEach((taxonomy) => {
			const { name, label, facet_type } = newSettings[taxonomy];
			const type = facet_type;
			newFacets[name] = {
				name,
				label,
				type,
			};
		});
		return newFacets;
	};

	useEffect(() => {
		if (!enabled) {
			setSettings(null);
			setIsLoading(false);
			return;
		}

		let cancelled = false;
		setIsLoading(true);

		apiFetch({
			path: addQueryArgs('/prc-api/v3/facets/get-settings', {
				templateSlug,
			}),
		})
			.then((newSettings) => {
				if (cancelled) {
					return;
				}
				const newFacets = reduceSettings(newSettings);
				setSettings(newFacets);
				setIsLoading(false);
			})
			.catch(() => {
				if (cancelled) {
					return;
				}
				setSettings(null);
				setIsLoading(false);
			});

		return () => {
			cancelled = true;
		};
	}, [enabled, templateSlug]);

	return useMemo(() => {
		return {
			settings,
			isLoading,
		};
	}, [settings, isLoading]);
}
