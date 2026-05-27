# Sreality HTML-Scraping Migration — Design

## Goal

Restore the bot's Sreality data source after Sreality replaced its public
`/api/cs/v2/estates` JSON API (which returned 404 starting around 26 May 2026)
with a Next.js SPA backed by an authenticated `/api/v1/estates`. The new
SrealityClient consumes the server-rendered HTML pages — both search-result
pages and per-listing detail pages — extracting the structured data from each
page's embedded `<script id="__NEXT_DATA__">` JSON. This restores parity with
the old behaviour without requiring user authentication, paid partner access,
or a headless browser.

## Context

**What broke.** As of 26 May 2026 16:12 CEST, every `Source fetch failed` and
`Hydrate failed` in `var/monitor.log` is a Sreality `/api/cs/v2/estates*` call
returning HTTP 404. The replacement endpoint `/api/v1/estates` returns HTTP 401
with `{"error_details":{"is_sbr":false,"user_rus_id":null},"status_code":401}`
regardless of User-Agent — the new API requires a valid Sreality user session.
The session token is minted server-side by the Next.js app and never travels
through the browser JS chunks, so it cannot be reverse-engineered without an
actual logged-in account. Bezrealitky is unaffected.

**Why HTML scraping (not auth, not third-party).** Brainstorming evaluated
four paths. Authenticated API access requires periodic manual session refresh
(rejected: user wants set-and-forget). Official partner API requires a paid
B2B contract. Alternative Czech sites (Reality.cz, RealityMix, Realingo,
iDnes Reality) either have no public API or block bot access. HTML scraping
is the simplest path that works today: every Sreality page server-renders the
same structured data Apollo would fetch, embedded as `__NEXT_DATA__` JSON.

**Why search-page HTML and not sitemap-discovery.** Both work; search-page
scraping is closer to the current pagination model, simpler, and faster to
ship (2-3 h vs 4-5 h). Sitemap-discovery is only meaningfully better if
Sreality starts blocking search-page traffic specifically — at that point we
escalate.

## Non-Goals

- No authentication, no token refresh, no Seznam OAuth integration.
- No headless browser (Puppeteer, Playwright, Chromium in Docker).
- No new data sources (iDnes, Reality.cz, RealityMix).
- No changes to Bezrealitky scraping, the Telegram pipeline, the classifier,
  the phone detector, or the database schema.
- No changes to public class contracts (`ListingSource`, `Listing`,
  `SellerMeta`, `DetectedPhone`). Only `SrealityClient` internals change.

## Architecture

`SrealityClient` keeps the same public interface (`ListingSource`). Inside,
two HTTP surfaces are replaced:

```
                                     OLD                            NEW
  fetchRecentListings() iterates ──> /api/cs/v2/estates?page=N ──>  /hledani/{type}/byty/{region}?strana=N&sort=-date
                                     (JSON, 60/page)                (HTML, 22/page)

  hydrate($listing)         ──────>  /api/cs/v2/estates/{hash}  ──>  /detail/{type}/{main}/{disp}/{loc}/{hash}
                                     (JSON)                          (HTML, URL unchanged from buildDetailUrl)
```

Both responses are HTML pages containing `<script id="__NEXT_DATA__" type="application/json">...</script>`. A new private helper `fetchNextData(string $url): array`
issues the GET, validates the HTTP status (Section: Defensive instrumentation),
extracts the script tag with a regex (`/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s`), `json_decode`s the payload, and returns the decoded `array<mixed, mixed>`.

The decoded payload contains `props.pageProps.dehydratedState.queries` — an
array of React-Query cache entries. The Generator iterates entries until it
finds one whose `queryKey[0]` matches `'estatesSearch'` (on the search page)
or `'estate'` (on the detail page), then reads `state.data` from that entry.

`buildDetailUrl()`, `THROTTLE_USEC`, `$lastDetailCallAt`, `throttleDetailCall()`,
the `TYPE_CB_SLUGS` / `MAIN_CB_SLUGS` / `SUB_CB_SLUGS` constants, and the
pagination Generator structure (`while (true) { … ++$page; }`) all stay.

## Data Flow

```
fetchRecentListings()
  for each dealType in dealTypes():
    page = 1
    loop:
      url = "https://www.sreality.cz/hledani/{type-slug}/byty/{region}?strana={page}&sort=-date"
      throttleSearchPage()                              -- 500 ms between search-page fetches
      data = fetchNextData(url)
      query = pickQuery(data, queryKey0='estatesSearch')
      results = query.state.data.results               -- 22 listings (Sreality default)
      pagination = query.state.data.pagination
      for each result in results:
        yield mapShallow(result, dealType)
      if results == [] or page * 22 >= pagination.total:
        break
      ++page

hydrate($listing)
  hashId = strip 'sreality:' prefix from $listing->id
  url    = $listing->url      -- already canonical /detail/.../{hashId}
  throttleDetailCall()        -- 350 ms between detail fetches (unchanged)
  data   = fetchNextData(url)
  query  = pickQuery(data, queryKey0='estate')
  estate = query.state.data
  text   = extractText(estate)              -- now reads estate.description
  seller = extractSeller(estate)            -- now reads estate.seller, estate.premise
  return $listing->withDetails(text, seller.meta, seller.phones)
```

## Field Mapping

### Search-page result → `Listing` (`mapShallow`)

| Old API (`_embedded.estates[]`) | New `__NEXT_DATA__.results[]` |
|---|---|
| `hash_id` (int) | `id` (int) |
| `name` (string) | `name` (string) |
| `locality` (string) | `locality.city` + `locality.cityPart` (joined as `"{city}, {cityPart}"`) |
| `price` (int) | `priceCzk` (int) |
| `seo.category_main_cb` (int) | `categoryMainCb.value` (int) |
| `seo.category_sub_cb` | `categorySubCb.value` |
| `seo.category_type_cb` | `categoryTypeCb.value` |
| `seo.locality` (single slug) | composed from `locality.citySeoName` and `locality.cityPartSeoName` as `"{citySeoName}-{cityPartSeoName}"`; when `cityPartSeoName` is missing, fall back to `citySeoName` alone |

`buildDetailUrl()` accepts the composed slug. If composition diverges from the
canonical Sreality slug, Sreality 301-redirects to the canonical URL — already
verified during the existing implementation.

### Detail-page data → `extractText()` and `extractSeller()`

| Old | New |
|---|---|
| `text.value` (string in `{name, value}` object) | `description` (plain string) |
| `_embedded.seller.user_name` | `seller.name` |
| `_embedded.seller.email` | `seller.email` |
| `_embedded.seller._embedded.premise` (existence → `hasPremise`) | `premise` object on `state.data` (existence → `hasPremise`) |
| `_embedded.seller.specialization.category[].num` (sum → `totalListingCount`) | **No equivalent.** The new `premise` object carries `reviewCount` (agency reviews, not listings) and no per-broker listing count. `totalListingCount` becomes permanently `null` going forward |
| `_embedded.seller.phones[].number` (Sreality national digits, paired with `code`) | `seller.phones[].phone` (string, **already E.164** — e.g. `"+420739544411"`); `extractPhones()` reads `phone` directly and runs it through `toE164()` for normalisation |
| `contact` (top-level, for private sellers) | `seller` non-null + `premise` absent → private seller; the unified extraction below handles it |

**Effect on the classifier** of losing `totalListingCount`:

- Tier-0 rule `hasPremise → REALTOR (HIGH)` keeps firing for every broker — the main realtor signal stays intact (verified: every probed agency listing has `premise` populated).
- Tier-0 rule `totalListingCount > 2 → REALTOR (HIGH)` becomes inactive. In practice this only removed brokers who lacked a premise but had many listings (a rare path).
- Tier-1 rule `totalListingCount == 1 && !hasPremise → OWNER (HIGH)` becomes inactive. Owner detection falls back entirely to the text-phrase tier (`"bez RK"`, `"primo od majitele"`, `"bez provize"` etc.), which is the strongest owner signal anyway.

This is an accepted graceful degradation — the same gate (drop REALTOR; drop without phone+email) still works.

**Unified `extractSeller()` logic:**

```
function extractSeller(estate):
  seller  = estate.seller   (may be null)
  premise = estate.premise  (may be null)

  if seller is null:
    return { meta: null, phones: [] }

  hasPremise        = premise !== null
  totalListingCount = null                          -- no equivalent in the new API
  name              = seller.name
  email             = seller.email
  phones            = extractPhones(seller.phones)

  return {
    meta:   new SellerMeta(hasPremise, totalListingCount, name, email),
    phones: phones,
  }
```

This collapses the old broker/contact dichotomy into a single branch: every
listing has a `seller`; only the presence of `premise` distinguishes broker
from private owner. The existing classifier's tier-0 logic (`hasPremise` →
REALTOR) and tier-1 logic (private owner with `totalListingCount == 1`)
continue to work unchanged.

`extractPhones()` becomes simpler in the new shape: each `seller.phones[]`
entry is `{phoneType: "TEL"|"MOB", phone: "+420…"}` with the phone already in
E.164 form. The helper iterates entries, reads `phone`, and still runs each
through `toE164()` for defensive normalisation (catches stray whitespace,
missing prefix, oddly-grouped numbers).

## Defensive Instrumentation

`fetchNextData($url)` is the single chokepoint. It runs the HTTP request and
maps the response to one of these outcomes:

| Outcome | Logging | Behaviour |
|---|---|---|
| 200, `__NEXT_DATA__` present, payload parses, target query exists | none (success) | return data |
| 200, `__NEXT_DATA__` regex matches nothing | `[error] Sreality: __NEXT_DATA__ missing at {url}` | throw `\RuntimeException` |
| 200, `__NEXT_DATA__` present but `json_decode` fails | `[error] Sreality: __NEXT_DATA__ malformed JSON at {url}` | throw |
| 200, payload parses, target `queryKey` missing | `[error] Sreality: query key '{key}' missing at {url}` | throw |
| 401 / 403 | `[error] Sreality: HTTP {code} — possibly blocked, anti-bot triggered at {url}` | throw |
| 429 | `[warn] Sreality: HTTP 429 — rate-limited at {url}` | throw |
| 5xx | `[warn] Sreality: HTTP {code} server error at {url}` | throw |
| 404 on **search page** | `[error] Sreality: HTTP 404 on search page at {url} — pagination URL may have changed` | throw |
| 404 on **detail page** | `[info] Sreality: listing {id} deactivated (HTTP 404), dropping via no-contact gate` | `hydrate()` returns the input listing unchanged (blank `rawText`, null `sellerMeta`, empty `structuredPhones`); downstream gate marks it seen |
| Network error / timeout | `[warn] Sreality: network error '{msg}' at {url}` | throw |

**404-on-detail handling rationale.** Currently the bot accumulates a growing
backlog of `Hydrate failed` entries — ~46 per run, ~2199 over the past week —
because listings deactivated by Sreality between the search-page fetch and the
detail-page fetch return 404, and the old code treats every detail-call failure
as transient (leaves un-seen, retries forever). With this design,
`SrealityClient::hydrate()` catches the 404 internally and **returns the input
`Listing` unchanged** — no description, no seller, no phones. The downstream
send-gate in `MonitorRunner` then sees an entry without phone or e-mail, drops
it via the existing "no-contact" path, and `markSeen` is called through the
normal flow. No new exception type, no `MonitorRunner` change required. The
404 log line is emitted once per deactivated listing, after which the listing
is seen and never reprocessed.

`SrealityClient::throttleSearchPage()` is a sibling of `throttleDetailCall()`,
using a separate timestamp and a `SEARCH_THROTTLE_USEC = 500_000`. The first
search-page call passes through immediately; subsequent search-page calls in
the same Generator instance wait at least 500 ms apart.

User-Agent stays the existing Chrome UA constant.

## Testing

Fixtures are replaced. Old `.json` fixtures are deleted; new `.html` fixtures
contain a minimal HTML envelope around a `<script id="__NEXT_DATA__">`:

```html
<!doctype html>
<html><head></head><body>
<script id="__NEXT_DATA__" type="application/json">{... real-shape JSON ...}</script>
</body></html>
```

| New fixture | Purpose |
|---|---|
| `tests/Fixtures/sreality_search_page.html` | One search page with 2 distinct results — broker + private seller |
| `tests/Fixtures/sreality_detail_private.html` | Detail of a private seller — `seller` populated, `premise` absent |
| `tests/Fixtures/sreality_detail_agency.html` | Detail of a broker — `seller` populated, `premise` present |
| `tests/Fixtures/sreality_detail_minimal.html` | Degenerate — `description` empty string, `seller: null`, `premise: null` |

`SrealityClientTest` tests are rewritten with the new fixtures. All existing
test method names and intents are preserved (`testFetchRecentListingsMapsShallowListings`,
`testHydratePrivateListingReadsTextAndContactFallback`,
`testHydrateAgencyListingMarksPremise`, `testHydrateMinimalDetailDegradesGracefully`,
`testFetchRecentListingsPaginatesAcrossPages`, `testHydrateThrottlesBetweenDetailCalls`,
…). They now read from the HTML fixtures and assert on the same `Listing`
shape consumers expect.

Four new defensive tests:

| Test | Verifies |
|---|---|
| `testThrowsWhenNextDataMissing` | Empty HTML body → `RuntimeException` with "missing" in message |
| `testThrowsWhenEstatesSearchQueryMissing` | Valid `__NEXT_DATA__` but no `estatesSearch` query → throws |
| `testHydrateReturnsBlankListingOnDetail404` | Detail responds 404 → returned `Listing` has empty rawText and null sellerMeta |
| `testThrowsOnHttp403` | Search page responds 403 → throws |

No changes to `MonitorRunnerTest`, `PhoneDetectorTest`, `MessageFormatterTest`,
classifier tests, Bezrealitky tests, or any DTO tests.

## Migration

1. Branch: `feat/sreality-html-scraping`.
2. Implement, tests green, PHPStan level 10 clean, ECS clean.
3. Merge to `main`.
4. Clear Symfony prod cache (services.yaml unchanged but cheap to be safe):
   `docker compose run --rm bot rm -rf var/cache/prod`.
5. Next cron run (within an hour, possibly sooner via `launchctl start
   com.bezr.monitor`) uses the new code.
6. Operator monitors `var/monitor.log` for the first 1-2 runs — sanity-check
   no `__NEXT_DATA__ missing` / `query key missing` errors that would indicate
   shape drift from the fixtures.

## Database State

- The 9959 `sreality` entries in `seen_listings` stay marked seen and continue
  to be skipped via `isSeen()`. Correct.
- The ~2199 listings that accumulated as `Hydrate failed` over the past week
  (never marked seen because the API was 404-ing) will be retried on the
  first run after deployment. With the new 404-on-detail → empty-listing →
  drop-via-gate path, they get properly marked seen on first retry. Expected
  cleanup time: 1-2 cron runs.
- `MONITOR_BATCH_LIMIT=50` per source per run is preserved.

## Risks & Rollback

**Risk 1 — Sreality changes the `dehydratedState.queries[].queryKey` shape
or adds anti-bot defences.** Mitigated by Defensive Instrumentation: when a
key disappears or HTTP 401/403/429 is returned, the log states exactly which
condition failed and at which URL.

**Risk 2 — Sreality moves search pages to client-side rendering** (data
fetched only after JS execution, not in `__NEXT_DATA__`). Currently they
server-render (verified — 22 results in HTML on first paint). If they
de-hydrate later, `fetchNextData` throws "query missing", and we escalate to
the headless-browser approach (out-of-scope here).

**Risk 3 — Sreality blocks the Mac's IP after sustained scraping.** Throttling
(500 ms between search pages, 350 ms between detail pages) keeps the rate
well below human browsing speed. If they still block, the same anti-bot
escalation applies.

**Rollback:** `git revert <merge-commit>`, clear cache. Bot returns to the
current state (Sreality blind, Bezrealitky live). No DB changes, no env
changes, no scheduler changes — clean revert.

## Out of Scope

- Sitemap-driven discovery (variant B in brainstorming).
- Headless Chromium / Puppeteer (variant C).
- Authenticated `/api/v1/estates` access via a real Sreality account.
- Loosening the Bezrealitky gate to also pass listings without contact.
- Adding new data sources.
- Russian-speaker prioritisation in delivery order (separate request,
  postponed earlier in the conversation).
- Caffeinate / VPS / GitHub Actions migration (separate scheduling concern).
