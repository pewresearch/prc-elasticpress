/* eslint-disable camelcase */
/**
 * WordPress Dependencies
 */
import { store, getServerState } from '@wordpress/interactivity';

const targetNamespace = 'prc-ep/facets-context-provider';

store(targetNamespace, {
	state: {
		get resultsText() {
			const { pagination } = getServerState();
			const { page, per_page, total_rows } = pagination || {};

			const currentPage = Math.max(1, Number(page) || 1);
			const perPage = Math.max(1, Number(per_page) || 10);
			const totalRows = Math.max(0, Number(total_rows) || 0);

			if (totalRows === 0) {
				return 'Displaying 0 of 0 results';
			}

			const start = (currentPage - 1) * perPage + 1;
			const end = Math.min(currentPage * perPage, totalRows);
			return `Displaying ${start} - ${end} of ${totalRows} results`;
		},
	},
});
