# PRC ElasticPress (`@prc/elasticpress`)

Canonical ElasticPress / VIP Search integration for PRC Platform: search hardening, faceted filtering, blocks, and server redirects.

## What it does

- Forces **ElasticPress** (`ep_integrate`) on search paths that would otherwise fall through to expensive MySQL `LIKE` scans, including admin DataViews list search
- Indexes unpublished statuses `draft`, `pending`, `private`, and `future` for editorial search (`trash` stays on MySQL)
- Sanitizes and truncates search terms early (100-character cap) on frontend search, REST search, DataViews list search, and search feeds
- Caps search RSS feed `posts_per_page` at 20 (crawler hardening)
- Owns faceted listing/search middleware (`ElasticPress_Middleware` + `ElasticPress_Facets_API`)
- Registers facet UI blocks under the `prc-ep/*` namespace
- Owns pre-WordPress redirects: `?s=` → `/search/{term}` and legacy FacetWP underscore params → `ep_filter_*`

## Architecture

**PHP (server):** Search hardening hooks live in `includes/class-plugin.php`. Facets middleware owns `ep_integrate` for pub-listing queries, taxonomy OR/AND filter rewriting, and custom `years` / `time_since` aggregations. `ElasticPress_Facets_API` normalizes aggregations into the Interactivity contract. `Context_Provider` always uses the EP API with `urlKey` `ep_filter_`.

**JS (client):** Blocks under `src/` drive selection, URL construction (`ep_filter_*`), and result refresh via the Interactivity API.

**Legacy URLs:** `plugins/prc-elasticpress/vip-config/server-redirects.php` (loaded from root `vip-config` before WP boots).

## Blocks

| Block | Name |
| --- | --- |
| Facets Context Provider | `prc-ep/facets-context-provider` |
| Facet Template | `prc-ep/facet-template` |
| Facets Results Info | `prc-ep/facets-results-info` |
| Facet Search Relevancy | `prc-ep/facet-search-relevancy` |

Temporary aliases keep old `prc-platform/facet*` names rendering until DB-stored templates are re-saved.

## Registered facets

| slug | Type | Notes |
| --- | --- | --- |
| `category` | checkbox | Topics; OR within taxonomy |
| `formats` | checkbox | OR within taxonomy |
| `regions-countries` | radio | |
| `bylines` | dropdown | Authors |
| `research-teams` | dropdown | |
| `years` | dropdown | `date_terms.year` aggregation |
| `time_since` | radio | `past-month`, `past-6-months`, `past-12-months`, `past-2-years` |

## Search routing

Frontend HTML `/search*` integrates via facets middleware. Additional hooks cover REST/feed gaps:

| Hook | Scope | Behavior |
| --- | --- | --- |
| `pre_get_posts` → `integrate_search_queries` | REST search + search feeds | Sets `ep_integrate=true` when `REST_REQUEST` or `is_feed()` |
| `rest_post_query` → `integrate_rest_post_search` | `/wp/v2/posts?search=…` | Sanitizes term + sets `ep_integrate=true` |
| `prc_wp_admin_dataview` query flag | Admin DataViews lists | Shell/`Search_Query` sets `ep_integrate` (not trash-only); `Admin_Dataview_Search` expands search fields |
| `ep_indexable_post_status` | Index | Adds `draft`, `pending`, `private`, `future`; never `trash` |
| `pre_get_posts` → `sanitize_search_term` | Frontend + REST + DataViews | Truncates `s` to 100 chars |
| `pre_get_posts` → `reject_nonsense_search_term` | Frontend main search | Flags spammy `s` (consecutive `++`, noise-only); short-circuits + 404 |
| `ElasticPress_Middleware::MAX_NAVIGABLE_PAGE` | Facet aggregations | Skip facets past page **100** (pager still uses ES window) |

## Debugging

Define `PRC_ELASTICPRESS_DEBUG` to log provider decisions under `[PRC Facets - ElasticPress]`. Client: `window.prcFacetsDebug = true`.

## Build

```bash
npx turbo build --filter=@prc/elasticpress
```

## Key files

| Path | Purpose |
| --- | --- |
| `includes/class-plugin.php` | Search integration, sanitization, robots, facet bootstrap |
| `includes/class-admin-dataview-search.php` | Admin DataViews search fields, unpublished statuses, chart `design_slug` |
| `includes/providers/` | Middleware + Facets API |
| `vip-config/server-redirects.php` | Pre-WordPress search + legacy facet redirects |
| `src/` | Facet blocks |
| [`docs/plugins/prc-elasticpress/`](../../docs/plugins/prc-elasticpress/) | Plugin docs (architecture, blocks, REST, troubleshooting) |

## Related

- Requires `prc-scripts` and `prc-publication-listing` (`isPubListingQuery` handshake)
- Theme templates in `@prc/design-system` embed `prc-ep/*` facet blocks
