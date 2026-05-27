# Sreality HTML-Scraping Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `SrealityClient`'s dead `/api/cs/v2/estates` JSON consumption with HTML scraping of Sreality's Next.js search and detail pages, extracting structured data from each page's embedded `<script id="__NEXT_DATA__">` JSON. Restores Sreality coverage in the bot's pipeline without authentication.

**Architecture:** `SrealityClient` keeps the same `ListingSource` interface. Inside, a new private helper `fetchNextData(string $url, bool $allowNotFound = false)` issues the GET, validates the response, extracts and JSON-decodes the `__NEXT_DATA__` script tag. `fetchRecentListings()` walks `/hledani/{type}/byty/{region}?strana=N&sort=-date` pages and reads the `estatesSearch` query from the dehydrated React-Query state. `hydrate()` walks the per-listing detail URL (URL builder unchanged) and reads the `estate` query. Detail-page 404 returns the input listing unchanged so the downstream send-gate drops it via the normal no-contact path — clearing the long-standing `Hydrate failed` backlog.

**Tech Stack:** PHP 8.4, Symfony 8, PHPUnit 13, PHPStan level 10, ECS. All tooling runs through Docker: `docker compose run --rm bot <cmd>`.

---

## Context

Sreality replaced its public `/api/cs/v2/estates` JSON API with a Next.js SPA around 26 May 2026. The old endpoint returns HTTP 404 on every path; the replacement `/api/v1/estates` returns HTTP 401 regardless of User-Agent and requires a server-minted Sreality session token that never travels through the browser JS. Brainstorming concluded HTML scraping is the only feasible non-paid path: every Sreality page server-renders the data Apollo would fetch, embedded as JSON in the page itself. Design doc is at `docs/superpowers/specs/2026-05-27-sreality-html-scraping-design.md`.

The bot has been Sreality-blind since 26 May 16:12 CEST; Bezrealitky is unaffected. Existing `seen_listings` (9959 Sreality entries) stay seen — the migration is purely a code swap, no DB or env changes.

## Conventions (apply throughout)

- TDD where practical; this refactor mixes implementation-first (Task 2 — major code rewrite) with test-first elsewhere. Acknowledged in task notes.
- All tooling runs in Docker: `docker compose run --rm bot vendor/bin/phpunit`, `docker compose run --rm bot composer phpstan`, `docker compose run --rm bot composer ecs`.
- `declare(strict_types=1);` in every PHP file. Final classes. Readonly DTOs where they already are.
- PHPStan runs at level 10 — be strict about `mixed`, `nullsafe.neverNull`, array-key types.
- Commit at the end of each task with the message shown in the task's final step. End every commit message with the trailer `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## File Structure

**Modified (3):**

- `src/Source/Sreality/SrealityClient.php` — `fetchRecentListings()` and `hydrate()` reimplemented against HTML pages; new private helpers `fetchNextData()`, `findQuery()`, `composeLocalitySlug()`, `throttleSearchPage()`; `extractText()`, `extractSeller()`, `extractPhones()`, `mapShallow()` reimplemented against the new payload shape; `buildDetailUrl()`, `seoCode()`, `toE164()`, `throttleDetailCall()`, `options()`, the `TYPE_CB_SLUGS` / `MAIN_CB_SLUGS` / `SUB_CB_SLUGS` / `REGION_IDS` / `DEAL_TYPE_CODES` / `THROTTLE_USEC` / `APARTMENT_CATEGORY` constants kept as-is. New constants `SEARCH_THROTTLE_USEC`, `USER_AGENT` (relocation), new field `$lastSearchCallAt`.
- `tests/Unit/Source/Sreality/SrealityClientTest.php` — fully replaced; new comprehensive integration tests against HTML fixtures (Task 3) plus defensive error-path tests (Task 4).
- `tests/Fixtures/` — old `sreality_list.json`, `sreality_detail_private.json`, `sreality_detail_agency.json`, `sreality_detail_minimal.json` deleted; replaced by `sreality_search_page.html`, `sreality_detail_private.html`, `sreality_detail_agency.html`, `sreality_detail_minimal.html`.

**Created (4 fixtures):** see above.

**Untouched:** every other source file, every other test, every domain DTO, the `MonitorRunner`, `BezrealitkyClient`, `PhoneDetector`, classifier, message formatter, env, services.yaml, launchd plist, DB schema.

---

### Task 1: Replace fixtures and scaffold the test file

Tear down the JSON-API-shaped test scaffolding before any code changes. After this task: four new HTML fixtures exist, `SrealityClientTest` has zero test methods (just the `fixture()` helper), the full suite still passes (other tests are unaffected), and production `SrealityClient.php` is unchanged from `main`.

**Files:**
- Delete: `tests/Fixtures/sreality_list.json`
- Delete: `tests/Fixtures/sreality_detail_private.json`
- Delete: `tests/Fixtures/sreality_detail_agency.json`
- Delete: `tests/Fixtures/sreality_detail_minimal.json`
- Create: `tests/Fixtures/sreality_search_page.html`
- Create: `tests/Fixtures/sreality_detail_private.html`
- Create: `tests/Fixtures/sreality_detail_agency.html`
- Create: `tests/Fixtures/sreality_detail_minimal.html`
- Modify: `tests/Unit/Source/Sreality/SrealityClientTest.php`

- [ ] **Step 1: Delete the four obsolete JSON fixtures**

```bash
rm tests/Fixtures/sreality_list.json \
   tests/Fixtures/sreality_detail_private.json \
   tests/Fixtures/sreality_detail_agency.json \
   tests/Fixtures/sreality_detail_minimal.json
```

- [ ] **Step 2: Create `tests/Fixtures/sreality_search_page.html`**

Write this exact content:

```html
<!doctype html>
<html><head></head><body>
<script id="__NEXT_DATA__" type="application/json">
{
  "buildId": "1.0.482",
  "props": {
    "pageProps": {
      "dehydratedState": {
        "queries": [
          {
            "queryKey": ["estatesSearch", {"sort": "-date", "page": 1, "limit": 22, "categoryTypeCb": [1], "categoryMainCb": [1]}],
            "state": {
              "data": {
                "pagination": {"limit": 22, "offset": 0, "total": 2, "totalWithPromo": 2},
                "results": [
                  {
                    "id": 111,
                    "name": "Prodej bytu 2+kk 50 m²",
                    "categoryMainCb": {"name": "Byty", "value": 1},
                    "categorySubCb": {"name": "2+kk", "value": 4},
                    "categoryTypeCb": {"name": "Prodej", "value": 1},
                    "locality": {"city": "Praha", "citySeoName": "praha", "cityPart": "Holešovice", "cityPartSeoName": "holesovice"},
                    "priceCzk": 7500000,
                    "premise": {"id": 33946, "name": "Test Agency"}
                  },
                  {
                    "id": 222,
                    "name": "Prodej bytu 3+1 80 m²",
                    "categoryMainCb": {"name": "Byty", "value": 1},
                    "categorySubCb": {"name": "3+1", "value": 7},
                    "categoryTypeCb": {"name": "Prodej", "value": 1},
                    "locality": {"city": "Praha", "citySeoName": "praha", "cityPart": "Smíchov", "cityPartSeoName": "smichov"},
                    "priceCzk": 9900000
                  }
                ]
              }
            }
          }
        ]
      }
    }
  }
}
</script>
</body></html>
```

- [ ] **Step 3: Create `tests/Fixtures/sreality_detail_private.html`**

```html
<!doctype html>
<html><head></head><body>
<script id="__NEXT_DATA__" type="application/json">
{
  "props": {
    "pageProps": {
      "dehydratedState": {
        "queries": [
          {
            "queryKey": ["estate", {"id": 4058014540, "preview": false, "lang": "cs"}],
            "state": {
              "data": {
                "name": "Prodej bytu 2+kk",
                "description": "Nabízíme k prodeji byt 2+kk v prestižní lokalitě Prahy. Bez provize.",
                "categoryMainCb": {"name": "Byty", "value": 1},
                "categorySubCb": {"name": "2+kk", "value": 4},
                "categoryTypeCb": {"name": "Prodej", "value": 1},
                "locality": {"city": "Praha", "cityPart": "Chodov"},
                "priceCzk": 8990000,
                "seller": {
                  "id": 999,
                  "name": "Čenětická 2kk od vlastnika",
                  "email": "ruslan76731@gmail.com",
                  "phones": []
                }
              }
            }
          }
        ]
      }
    }
  }
}
</script>
</body></html>
```

- [ ] **Step 4: Create `tests/Fixtures/sreality_detail_agency.html`**

```html
<!doctype html>
<html><head></head><body>
<script id="__NEXT_DATA__" type="application/json">
{
  "props": {
    "pageProps": {
      "dehydratedState": {
        "queries": [
          {
            "queryKey": ["estate", {"id": 3173163084, "preview": false, "lang": "cs"}],
            "state": {
              "data": {
                "name": "Prodej bytu",
                "description": "Dovolujeme si Vám představit byt v Bakers Court.",
                "categoryMainCb": {"name": "Byty", "value": 1},
                "categorySubCb": {"name": "2+kk", "value": 4},
                "categoryTypeCb": {"name": "Prodej", "value": 1},
                "locality": {"city": "Praha", "cityPart": "Karlín"},
                "priceCzk": 12500000,
                "seller": {
                  "id": 36332,
                  "name": "Jiří Maštálka",
                  "email": "jiri@bakerscourt.cz",
                  "phones": [
                    {"phoneType": "MOB", "phone": "+420608444111"}
                  ]
                },
                "premise": {"id": 29465, "name": "Rezident Park 1 s.r.o.", "reviewCount": 6}
              }
            }
          }
        ]
      }
    }
  }
}
</script>
</body></html>
```

- [ ] **Step 5: Create `tests/Fixtures/sreality_detail_minimal.html`**

```html
<!doctype html>
<html><head></head><body>
<script id="__NEXT_DATA__" type="application/json">
{
  "props": {
    "pageProps": {
      "dehydratedState": {
        "queries": [
          {
            "queryKey": ["estate", {"id": 1, "preview": false, "lang": "cs"}],
            "state": {
              "data": {
                "name": "",
                "description": "",
                "locality": {},
                "seller": null,
                "premise": null
              }
            }
          }
        ]
      }
    }
  }
}
</script>
</body></html>
```

- [ ] **Step 6: Replace `tests/Unit/Source/Sreality/SrealityClientTest.php` with a minimal scaffold**

Write this exact content (deletes every existing test method):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source\Sreality;

use PHPUnit\Framework\TestCase;

final class SrealityClientTest extends TestCase
{
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    public function testFixturesLoadable(): void
    {
        // Smoke test: the four HTML fixtures exist and each contains __NEXT_DATA__.
        foreach (['sreality_search_page.html', 'sreality_detail_private.html', 'sreality_detail_agency.html', 'sreality_detail_minimal.html'] as $name) {
            $content = $this->fixture($name);
            self::assertStringContainsString('__NEXT_DATA__', $content, $name);
        }
    }
}
```

- [ ] **Step 7: Run the full test suite — confirm only one SrealityClient test exists and it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit`
Expected: PASS. Total count goes from 96 to ~85 (the old SrealityClient tests are gone), plus one new `testFixturesLoadable`. All non-Sreality tests still pass.

- [ ] **Step 8: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: PHPStan clean, ECS clean. The production `SrealityClient.php` is unchanged so it stays compliant.

- [ ] **Step 9: Commit**

```bash
git add tests/Fixtures/ tests/Unit/Source/Sreality/SrealityClientTest.php
git commit -m "test: scaffold HTML fixtures and clear SrealityClientTest for migration

Delete the four obsolete JSON-API fixtures and replace with HTML
fixtures embedding the new __NEXT_DATA__ shape. Empty
SrealityClientTest to a smoke-only scaffold; comprehensive new tests
land in Tasks 3 and 4.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Rewrite `SrealityClient.php` to consume HTML pages

Full code rewrite of `SrealityClient.php`. Replaces the JSON-API consumers with HTML-page consumers. No new tests in this task — the smoke test from Task 1 remains the only one. Comprehensive tests come in Tasks 3 and 4. PHPStan + ECS must pass.

**Files:**
- Modify: `src/Source/Sreality/SrealityClient.php` (full rewrite)

- [ ] **Step 1: Replace `src/Source/Sreality/SrealityClient.php` in full**

Write this exact content:

```php
<?php

declare(strict_types=1);

namespace App\Source\Sreality;

use App\Domain\DealType;
use App\Domain\Listing;
use App\Domain\SellerMeta;
use App\Domain\Source;
use App\Source\ListingSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sreality source over HTML scraping. The old /api/cs/v2/estates JSON API was
 * retired on 26 May 2026; the replacement /api/v1/estates requires authentication.
 * This client consumes the server-rendered Next.js pages instead, extracting the
 * structured data from each page's <script id="__NEXT_DATA__"> JSON blob — the
 * same data Apollo would otherwise fetch from the authenticated API.
 *
 * `fetchRecentListings()` paginates the /hledani/... search pages newest-first.
 * `hydrate()` walks the per-listing /detail/... page. Both share the same
 * fetchNextData() helper which centralises HTTP-status handling, __NEXT_DATA__
 * extraction, JSON parsing, and defensive logging on structural drift.
 */
final class SrealityClient implements ListingSource
{
    private const SEARCH_URL_BASE = 'https://www.sreality.cz/hledani';

    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /**
     * Sreality region slug used in the search URL path. We only support 'praha'
     * for the same reason the previous client only had one entry.
     */
    private const REGION_SLUGS = [
        'praha' => 'praha',
    ];

    /**
     * Maps our internal deal-type enum to the Czech slug in the search URL.
     */
    private const DEAL_TYPE_SLUGS = [
        'sale' => 'prodej',
        'rent' => 'pronajem',
    ];

    /**
     * Public-URL path slugs for the SEO category codes. The detail page lives at
     * /detail/{type}/{main}/{sub}/{locality}/{hash_id} — a bare /detail/{hash_id}
     * 404s. A non-canonical (but recognised) disposition slug still resolves:
     * Sreality 301-redirects it to the real page, so unknown codes fall back to
     * a valid token rather than risking a 404.
     */
    private const TYPE_CB_SLUGS = [
        1 => 'prodej',
        2 => 'pronajem',
        3 => 'drazba',
    ];

    private const MAIN_CB_SLUGS = [
        1 => 'byt',
        2 => 'dum',
        3 => 'pozemek',
        4 => 'komercni-prostory',
        5 => 'ostatni',
    ];

    private const SUB_CB_SLUGS = [
        // Apartments (category_main_cb = 1).
        2 => '1+kk',
        3 => '1+1',
        4 => '2+kk',
        5 => '2+1',
        6 => '3+kk',
        7 => '3+1',
        8 => '4+kk',
        9 => '4+1',
        10 => '5+kk',
        11 => '5+1',
        12 => '6-a-vice',
        16 => 'atypicky',
        47 => 'pokoj',
        // Houses (category_main_cb = 2).
        33 => 'chata',
        37 => 'rodinny',
        39 => 'vila',
        54 => 'vicegeneracni',
    ];

    /**
     * Minimum gap between detail-endpoint calls, in microseconds. The very first
     * call in a Generator passes through immediately; subsequent ones wait.
     */
    private const THROTTLE_USEC = 350_000;

    /**
     * Minimum gap between search-page fetches, in microseconds. Smaller dose
     * than detail throttling because there are far fewer search pages per run.
     */
    private const SEARCH_THROTTLE_USEC = 500_000;

    private int $lastDetailCallAt = 0;

    private int $lastSearchCallAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $monitorRegion,
        private readonly string $monitorDealTypes,
    ) {
    }

    public function fetchRecentListings(): iterable
    {
        $regionSlug = self::REGION_SLUGS[$this->monitorRegion] ?? self::REGION_SLUGS['praha'];

        foreach ($this->dealTypes() as $dealType) {
            $typeSlug = self::DEAL_TYPE_SLUGS[$dealType->value];
            $page = 1;

            while (true) {
                $url = sprintf(
                    '%s/%s/byty/%s?strana=%d&sort=-date',
                    self::SEARCH_URL_BASE,
                    $typeSlug,
                    $regionSlug,
                    $page,
                );

                $this->throttleSearchPage();
                $data = $this->fetchNextData($url);
                if ($data === null) {
                    break; // unreachable when allowNotFound is false; guards future use
                }

                $query = $this->findQuery($data, 'estatesSearch');
                if ($query === null) {
                    $this->logger->error('Sreality: query key "estatesSearch" missing', ['url' => $url]);
                    throw new \RuntimeException(sprintf('Sreality: query key "estatesSearch" missing at %s', $url));
                }

                $stateData = is_array($query['state']['data'] ?? null) ? $query['state']['data'] : [];
                $results = is_array($stateData['results'] ?? null) ? $stateData['results'] : [];
                $pagination = is_array($stateData['pagination'] ?? null) ? $stateData['pagination'] : [];

                if ($results === []) {
                    break;
                }

                foreach ($results as $result) {
                    if (is_array($result)) {
                        yield $this->mapShallow($result, $dealType);
                    }
                }

                $offset = is_int($pagination['offset'] ?? null) ? $pagination['offset'] : 0;
                $total = is_int($pagination['total'] ?? null) ? $pagination['total'] : 0;
                if ($total > 0 && $offset + count($results) >= $total) {
                    break;
                }

                ++$page;
            }
        }
    }

    public function hydrate(Listing $listing): Listing
    {
        if ($listing->source !== Source::SREALITY) {
            throw new \InvalidArgumentException(
                sprintf('SrealityClient cannot hydrate a %s listing', $listing->source->value),
            );
        }

        $this->throttleDetailCall();

        $data = $this->fetchNextData($listing->url, allowNotFound: true);
        if ($data === null) {
            $this->logger->info('Sreality: listing deactivated (404), dropping via no-contact gate', [
                'id' => $listing->id,
                'url' => $listing->url,
            ]);

            return $listing; // unchanged — downstream gate sees no phone+email and drops
        }

        $query = $this->findQuery($data, 'estate');
        if ($query === null) {
            $this->logger->error('Sreality: query key "estate" missing', ['url' => $listing->url]);
            throw new \RuntimeException(sprintf('Sreality: query key "estate" missing at %s', $listing->url));
        }

        $estate = is_array($query['state']['data'] ?? null) ? $query['state']['data'] : [];

        $text = $this->extractText($estate);
        $seller = $this->extractSeller($estate);

        return $listing->withDetails(
            rawText: $text,
            sellerMeta: $seller['meta'],
            structuredPhones: $seller['phones'],
        );
    }

    /**
     * Fetches a page, validates the HTTP status, extracts the embedded
     * __NEXT_DATA__ JSON, and returns the decoded payload. Returns null only
     * when $allowNotFound is true and the response is HTTP 404 — that signal
     * is used by hydrate() to gracefully drop deactivated listings without
     * polluting the retry queue.
     *
     * @return array<mixed, mixed>|null
     */
    private function fetchNextData(string $url, bool $allowNotFound = false): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, $this->options());
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Sreality: network error', ['url' => $url, 'error' => $e->getMessage()]);
            throw new \RuntimeException(sprintf("Sreality: network error '%s' at %s", $e->getMessage(), $url), 0, $e);
        }

        if ($status === 404 && $allowNotFound) {
            return null;
        }

        if ($status === 401 || $status === 403) {
            $this->logger->error('Sreality: blocked, possibly anti-bot', ['url' => $url, 'status' => $status]);
            throw new \RuntimeException(sprintf('Sreality: HTTP %d at %s — possibly blocked, anti-bot triggered', $status, $url));
        }

        if ($status === 429) {
            $this->logger->warning('Sreality: rate-limited', ['url' => $url]);
            throw new \RuntimeException(sprintf('Sreality: HTTP 429 at %s — rate-limited', $url));
        }

        if ($status >= 500) {
            $this->logger->warning('Sreality: server error', ['url' => $url, 'status' => $status]);
            throw new \RuntimeException(sprintf('Sreality: HTTP %d at %s — server error', $status, $url));
        }

        if ($status !== 200) {
            $this->logger->warning('Sreality: unexpected HTTP status', ['url' => $url, 'status' => $status]);
            throw new \RuntimeException(sprintf('Sreality: HTTP %d at %s', $status, $url));
        }

        try {
            $body = $response->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('Sreality: body read failed', ['url' => $url, 'error' => $e->getMessage()]);
            throw new \RuntimeException(sprintf('Sreality: body read failed at %s', $url), 0, $e);
        }

        if (preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $body, $matches) !== 1) {
            $this->logger->error('Sreality: __NEXT_DATA__ missing — page structure changed', ['url' => $url]);
            throw new \RuntimeException(sprintf('Sreality: __NEXT_DATA__ missing at %s', $url));
        }

        try {
            $decoded = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Sreality: __NEXT_DATA__ malformed JSON', ['url' => $url, 'error' => $e->getMessage()]);
            throw new \RuntimeException(sprintf('Sreality: __NEXT_DATA__ malformed JSON at %s', $url), 0, $e);
        }

        if (! is_array($decoded)) {
            $this->logger->error('Sreality: __NEXT_DATA__ not an object', ['url' => $url]);
            throw new \RuntimeException(sprintf('Sreality: __NEXT_DATA__ not an object at %s', $url));
        }

        return $decoded;
    }

    /**
     * Locates the first React-Query cache entry whose queryKey starts with
     * $keyName. Returns null if absent. Both search and detail pages use this:
     * search → 'estatesSearch', detail → 'estate'.
     *
     * @param array<mixed, mixed> $data
     * @return array<mixed, mixed>|null
     */
    private function findQuery(array $data, string $keyName): ?array
    {
        $queries = $data['props']['pageProps']['dehydratedState']['queries'] ?? null;
        if (! is_array($queries)) {
            return null;
        }

        foreach ($queries as $query) {
            if (! is_array($query)) {
                continue;
            }
            $queryKey = $query['queryKey'] ?? null;
            if (is_array($queryKey) && ($queryKey[0] ?? null) === $keyName) {
                return $query;
            }
        }

        return null;
    }

    /**
     * Sleeps so that consecutive detail-endpoint calls are spaced at least
     * THROTTLE_USEC apart. The very first call passes through with no wait.
     */
    private function throttleDetailCall(): void
    {
        if ($this->lastDetailCallAt !== 0) {
            $elapsed = (int) (hrtime(true) / 1000) - $this->lastDetailCallAt;
            if ($elapsed < self::THROTTLE_USEC) {
                usleep(self::THROTTLE_USEC - $elapsed);
            }
        }

        $this->lastDetailCallAt = (int) (hrtime(true) / 1000);
    }

    /**
     * Sleeps so that consecutive search-page calls are spaced at least
     * SEARCH_THROTTLE_USEC apart. The very first call passes through.
     */
    private function throttleSearchPage(): void
    {
        if ($this->lastSearchCallAt !== 0) {
            $elapsed = (int) (hrtime(true) / 1000) - $this->lastSearchCallAt;
            if ($elapsed < self::SEARCH_THROTTLE_USEC) {
                usleep(self::SEARCH_THROTTLE_USEC - $elapsed);
            }
        }

        $this->lastSearchCallAt = (int) (hrtime(true) / 1000);
    }

    /**
     * @return list<DealType>
     */
    private function dealTypes(): array
    {
        $types = [];
        foreach (explode(',', $this->monitorDealTypes) as $raw) {
            $type = DealType::tryFrom(trim($raw));
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types === [] ? [DealType::SALE] : $types;
    }

    /**
     * @param array<mixed, mixed> $result one entry from dehydratedState.queries[estatesSearch].state.data.results
     */
    private function mapShallow(array $result, DealType $dealType): Listing
    {
        $hashId = is_int($result['id'] ?? null) ? $result['id'] : 0;
        $name = is_string($result['name'] ?? null) ? $result['name'] : '';
        $price = is_int($result['priceCzk'] ?? null) ? $result['priceCzk'] : null;

        $loc = is_array($result['locality'] ?? null) ? $result['locality'] : [];
        $city = is_string($loc['city'] ?? null) ? $loc['city'] : '';
        $cityPart = is_string($loc['cityPart'] ?? null) ? $loc['cityPart'] : '';
        $location = $city . ($cityPart !== '' ? ', ' . $cityPart : '');

        // Build a pseudo-seo array shaped like the old API so buildDetailUrl()
        // (unchanged) can keep its slug logic.
        $seo = [
            'category_main_cb' => $this->unwrapCb($result['categoryMainCb'] ?? null),
            'category_sub_cb' => $this->unwrapCb($result['categorySubCb'] ?? null),
            'category_type_cb' => $this->unwrapCb($result['categoryTypeCb'] ?? null),
            'locality' => $this->composeLocalitySlug($loc),
        ];

        return new Listing(
            id: 'sreality:' . $hashId,
            source: Source::SREALITY,
            title: $name,
            price: $price,
            dealType: $dealType,
            location: $location,
            url: $this->buildDetailUrl($hashId, $dealType, $seo),
            rawText: '',
            sellerMeta: null,
            structuredPhones: [],
        );
    }

    /**
     * `categoryMainCb`, `categorySubCb`, `categoryTypeCb` in the new payload are
     * objects `{name, value}`. Returns the int `value` or null on any other shape.
     */
    private function unwrapCb(mixed $cb): ?int
    {
        if (! is_array($cb)) {
            return null;
        }

        return is_int($cb['value'] ?? null) ? $cb['value'] : null;
    }

    /**
     * Composes the locality slug for the public detail URL from the new
     * `locality.citySeoName` + `locality.cityPartSeoName`. Sreality 301-redirects
     * a non-canonical composition to the right page, so even a partial composition
     * resolves.
     *
     * @param array<mixed, mixed> $locality
     */
    private function composeLocalitySlug(array $locality): ?string
    {
        $city = is_string($locality['citySeoName'] ?? null) ? $locality['citySeoName'] : '';
        $cityPart = is_string($locality['cityPartSeoName'] ?? null) ? $locality['cityPartSeoName'] : '';

        if ($city !== '' && $cityPart !== '') {
            return $city . '-' . $cityPart;
        }

        if ($city !== '') {
            return $city;
        }

        return null;
    }

    /**
     * Reads the description from a detail-page estate payload.
     *
     * @param array<mixed, mixed> $estate
     */
    private function extractText(array $estate): string
    {
        $description = $estate['description'] ?? null;

        return is_string($description) ? $description : '';
    }

    /**
     * Reads seller + premise from a detail-page estate payload. A null `seller`
     * collapses to ('meta' => null, 'phones' => []). Otherwise hasPremise is
     * derived from the presence of `premise`; totalListingCount has no
     * equivalent in the new API (always null going forward — see design doc).
     *
     * @param array<mixed, mixed> $estate
     * @return array{meta: ?SellerMeta, phones: list<string>}
     */
    private function extractSeller(array $estate): array
    {
        $seller = $estate['seller'] ?? null;
        if (! is_array($seller)) {
            return ['meta' => null, 'phones' => []];
        }

        $premise = $estate['premise'] ?? null;
        $hasPremise = is_array($premise);
        $name = is_string($seller['name'] ?? null) ? $seller['name'] : null;
        $email = is_string($seller['email'] ?? null) ? $seller['email'] : null;

        return [
            'meta' => new SellerMeta($hasPremise, null, $name, $email),
            'phones' => $this->extractPhones($seller['phones'] ?? null),
        ];
    }

    /**
     * Canonicalises a `seller.phones[]` array to a de-duplicated list of E.164
     * numbers. Each entry in the new payload is `{phoneType, phone}` with
     * `phone` already in E.164 form (`+420…`); we still run it through
     * toE164() for defensive normalisation.
     *
     * @return list<string>
     */
    private function extractPhones(mixed $rawPhones): array
    {
        $phones = [];
        foreach (is_array($rawPhones) ? $rawPhones : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $phone = is_string($entry['phone'] ?? null) ? $entry['phone'] : '';
            $e164 = $this->toE164($phone);
            if ($e164 !== null) {
                $phones[$e164] = true;
            }
        }

        return array_keys($phones);
    }

    /**
     * Canonicalises a Czech phone number to "+420" + 9 digits, matching the
     * format PhoneDetector produces, so ContactRegistry keys stay consistent
     * across sources.
     */
    private function toE164(string $number): ?string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        if (strlen($digits) < 9) {
            return null;
        }

        return '+420' . substr($digits, -9);
    }

    /**
     * Builds the public detail-page URL from a seo-shaped dict (composed in
     * mapShallow() to mirror the old API's shape):
     * /detail/{type}/{main}/{sub}/{locality}/{hash_id}. Missing or unrecognised
     * codes fall back to valid tokens — Sreality 301-redirects a non-canonical
     * path to the real listing.
     */
    private function buildDetailUrl(int $hashId, DealType $dealType, mixed $seo): string
    {
        $seo = is_array($seo) ? $seo : [];

        $type = self::TYPE_CB_SLUGS[$this->seoCode($seo, 'category_type_cb')]
            ?? ($dealType === DealType::RENT ? 'pronajem' : 'prodej');
        $main = self::MAIN_CB_SLUGS[$this->seoCode($seo, 'category_main_cb')] ?? 'byt';
        $sub = self::SUB_CB_SLUGS[$this->seoCode($seo, 'category_sub_cb')] ?? '1+kk';
        $locality = is_string($seo['locality'] ?? null) && $seo['locality'] !== ''
            ? $seo['locality']
            : 'praha';

        return sprintf('https://www.sreality.cz/detail/%s/%s/%s/%s/%d', $type, $main, $sub, $locality, $hashId);
    }

    /**
     * @param array<mixed, mixed> $seo
     */
    private function seoCode(array $seo, string $key): int
    {
        $value = $seo[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * @return array{headers: array<string, string>}
     */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => self::USER_AGENT,
            ],
        ];
    }
}
```

- [ ] **Step 2: Run the test suite — the smoke test should still pass**

Run: `docker compose run --rm bot vendor/bin/phpunit`
Expected: PASS — only one SrealityClient test (`testFixturesLoadable`), and it has no production-code dependency so the swap doesn't break it.

- [ ] **Step 3: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: PHPStan clean, ECS clean. If PHPStan flags anything, fix inline (typical fixes for level 10: extra `is_int()` / `is_string()` narrowing, `??` chains).

- [ ] **Step 4: Commit**

```bash
git add src/Source/Sreality/SrealityClient.php
git commit -m "feat: rewrite SrealityClient against HTML __NEXT_DATA__

Sreality replaced /api/cs/v2/estates with a Next.js SPA backed by an
authenticated /api/v1/estates around 26 May 2026; the old endpoint
returns 404. This commit retargets SrealityClient at the same
structured data that Apollo would fetch, embedded in every server-
rendered page as a <script id=\"__NEXT_DATA__\"> JSON blob.

fetchRecentListings() now paginates /hledani/{type}/byty/{region}
search pages and reads dehydratedState.queries[estatesSearch].
hydrate() walks /detail/... and reads dehydratedState.queries[estate].
A new fetchNextData() helper centralises HTTP-status handling,
__NEXT_DATA__ extraction, defensive logging, and the 'allowNotFound'
flag that lets hydrate() drop deactivated listings via the no-contact
gate instead of looping in retry purgatory.

ListingSource contract unchanged. Comprehensive tests come in
Tasks 3 and 4 (per the implementation plan); this commit only ships
the smoke test from Task 1.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Add integration tests against the HTML fixtures

Adds the bulk of new tests. Each test wires `MockHttpClient` with one or more `MockResponse` instances containing fixture HTML, exercises a public `SrealityClient` method, and asserts on the resulting `Listing` / generator behaviour.

**Files:**
- Modify: `tests/Unit/Source/Sreality/SrealityClientTest.php`

- [ ] **Step 1: Replace `tests/Unit/Source/Sreality/SrealityClientTest.php` in full**

Write this exact content:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source\Sreality;

use App\Domain\DealType;
use App\Domain\Source;
use App\Source\Sreality\SrealityClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SrealityClientTest extends TestCase
{
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    public function testFetchRecentListingsMapsShallowListings(): void
    {
        $http = new MockHttpClient([new MockResponse($this->fixture('sreality_search_page.html'))]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $listings = iterator_to_array($client->fetchRecentListings(), false);

        self::assertCount(2, $listings);
        self::assertSame('sreality:111', $listings[0]->id);
        self::assertSame(Source::SREALITY, $listings[0]->source);
        self::assertSame(DealType::SALE, $listings[0]->dealType);
        self::assertSame('Prodej bytu 2+kk 50 m²', $listings[0]->title);
        self::assertSame('Praha, Holešovice', $listings[0]->location);
        self::assertSame(7500000, $listings[0]->price);
        self::assertSame(
            'https://www.sreality.cz/detail/prodej/byt/2+kk/praha-holesovice/111',
            $listings[0]->url,
        );

        self::assertSame('sreality:222', $listings[1]->id);
        self::assertSame(
            'https://www.sreality.cz/detail/prodej/byt/3+1/praha-smichov/222',
            $listings[1]->url,
        );
    }

    public function testFetchRecentListingsCombinesSaleAndRent(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_search_page.html')),
            new MockResponse($this->fixture('sreality_search_page.html')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertCount(4, iterator_to_array($client->fetchRecentListings(), false));
    }

    public function testFetchRecentListingsStopsAtPaginationTotal(): void
    {
        // The fixture's pagination is total=2 and offset=0 with 2 results; the
        // client must stop after the first page and never request page 2 —
        // verified by queueing only one MockResponse.
        $http = new MockHttpClient([new MockResponse($this->fixture('sreality_search_page.html'))]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        self::assertCount(2, iterator_to_array($client->fetchRecentListings(), false));
    }

    public function testFetchRecentListingsStopsEarlyWhenConsumerBreaks(): void
    {
        // Only one response queued; if the client tried to fetch beyond what
        // the consumer pulled, MockHttpClient would throw "no more responses".
        $http = new MockHttpClient([new MockResponse($this->fixture('sreality_search_page.html'))]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $first = null;
        foreach ($client->fetchRecentListings() as $listing) {
            $first = $listing;
            break;
        }

        self::assertNotNull($first);
        self::assertSame('sreality:111', $first->id);
    }

    public function testHydratePrivateListingReadsDescriptionAndContactSeller(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_search_page.html')),
            new MockResponse($this->fixture('sreality_detail_private.html')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];
        $hydrated = $client->hydrate($shallow);

        self::assertStringContainsString('Bez provize', $hydrated->rawText);
        self::assertNotNull($hydrated->sellerMeta);
        self::assertFalse($hydrated->sellerMeta->hasPremise);
        self::assertNull($hydrated->sellerMeta->totalListingCount);
        self::assertSame('Čenětická 2kk od vlastnika', $hydrated->sellerMeta->name);
        self::assertSame('ruslan76731@gmail.com', $hydrated->sellerMeta->email);
        self::assertSame([], $hydrated->structuredPhones);
    }

    public function testHydrateAgencyListingMarksPremise(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_search_page.html')),
            new MockResponse($this->fixture('sreality_detail_agency.html')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];
        $hydrated = $client->hydrate($shallow);

        self::assertStringContainsString('Bakers Court', $hydrated->rawText);
        self::assertNotNull($hydrated->sellerMeta);
        self::assertTrue($hydrated->sellerMeta->hasPremise);
        self::assertNull($hydrated->sellerMeta->totalListingCount);
        self::assertSame('jiri@bakerscourt.cz', $hydrated->sellerMeta->email);
        self::assertSame(['+420608444111'], $hydrated->structuredPhones);
    }

    public function testHydrateMinimalDetailDegradesGracefully(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_search_page.html')),
            new MockResponse($this->fixture('sreality_detail_minimal.html')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];
        $hydrated = $client->hydrate($shallow);

        self::assertSame('', $hydrated->rawText);
        self::assertNull($hydrated->sellerMeta);
        self::assertSame([], $hydrated->structuredPhones);
    }

    public function testHydrateReturnsListingUnchangedOnDetail404(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_search_page.html')),
            new MockResponse('', ['http_code' => 404]),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];
        $hydrated = $client->hydrate($shallow);

        // Returned identically: blank rawText, null sellerMeta, empty phones.
        // The downstream gate in MonitorRunner drops it via the no-contact path.
        self::assertSame($shallow->id, $hydrated->id);
        self::assertSame('', $hydrated->rawText);
        self::assertNull($hydrated->sellerMeta);
        self::assertSame([], $hydrated->structuredPhones);
    }

    public function testHydrateThrottlesBetweenDetailCalls(): void
    {
        // Two list + two details = one back-to-back detail-call pair. The
        // second hydrate must wait at least THROTTLE_USEC microseconds after
        // the first; 350 ms is the production value. Tolerance: assert ≥ 300 ms.
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_search_page.html')),
            new MockResponse($this->fixture('sreality_detail_private.html')),
            new MockResponse($this->fixture('sreality_detail_agency.html')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false);
        $start = hrtime(true);
        $client->hydrate($shallow[0]);
        $client->hydrate($shallow[1]);
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);

        self::assertGreaterThanOrEqual(300_000, $elapsedUs);
    }
}
```

- [ ] **Step 2: Run SrealityClient tests**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Sreality/SrealityClientTest.php`
Expected: 9 tests PASS — `testFixturesLoadable` removed (replaced by the smoke implicit in the others); 9 new tests cover shallow mapping, deal-type combination, pagination stop, lazy consumer, private hydrate, agency hydrate, minimal detail, detail 404, throttle.

- [ ] **Step 3: Run the full suite**

Run: `docker compose run --rm bot vendor/bin/phpunit`
Expected: full suite green; total count increases by ~8 (was ~85 after Task 1, now ~93).

- [ ] **Step 4: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: PHPStan clean, ECS clean.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Source/Sreality/SrealityClientTest.php
git commit -m "test: integration tests for SrealityClient HTML parsing

Cover shallow mapping (id, location, URL, price), sale+rent deal-type
combination, pagination stop at pagination.total, lazy generator stop
on consumer break, private-seller hydrate, agency hydrate with premise,
minimal degenerate detail, 404-on-detail returns the listing unchanged
(no-contact drop downstream), and the 350 ms throttle between
detail-page fetches.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Add defensive error-path tests

Tests for the structural-drift and anti-bot signals that `fetchNextData()` is responsible for surfacing. Each one feeds `MockHttpClient` a crafted response (bad status or malformed body) and asserts the right exception with the right message is thrown.

**Files:**
- Modify: `tests/Unit/Source/Sreality/SrealityClientTest.php`

- [ ] **Step 1: Append defensive tests to `SrealityClientTest.php`**

Insert these test methods just before the closing `}` of the class:

```php
    public function testFetchThrowsWhenNextDataMissing(): void
    {
        $http = new MockHttpClient([new MockResponse('<html><body>nothing here</body></html>')]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('__NEXT_DATA__ missing');

        iterator_to_array($client->fetchRecentListings(), false);
    }

    public function testFetchThrowsWhenEstatesSearchQueryMissing(): void
    {
        $html = '<!doctype html><html><body><script id="__NEXT_DATA__" type="application/json">'
            . '{"props":{"pageProps":{"dehydratedState":{"queries":[]}}}}'
            . '</script></body></html>';
        $http = new MockHttpClient([new MockResponse($html)]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('query key "estatesSearch" missing');

        iterator_to_array($client->fetchRecentListings(), false);
    }

    public function testFetchThrowsOnHttp403(): void
    {
        $http = new MockHttpClient([new MockResponse('blocked', ['http_code' => 403])]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');

        iterator_to_array($client->fetchRecentListings(), false);
    }

    public function testFetchThrowsOnHttp429(): void
    {
        $http = new MockHttpClient([new MockResponse('too many', ['http_code' => 429])]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 429');

        iterator_to_array($client->fetchRecentListings(), false);
    }

    public function testFetchThrowsOnHttp503(): void
    {
        $http = new MockHttpClient([new MockResponse('down', ['http_code' => 503])]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        iterator_to_array($client->fetchRecentListings(), false);
    }

    public function testFetchThrowsOnMalformedJsonInNextData(): void
    {
        $html = '<!doctype html><html><body><script id="__NEXT_DATA__" type="application/json">{not json}</script></body></html>';
        $http = new MockHttpClient([new MockResponse($html)]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed JSON');

        iterator_to_array($client->fetchRecentListings(), false);
    }

    public function testHydrateThrowsWhenEstateQueryMissing(): void
    {
        $detailHtml = '<!doctype html><html><body><script id="__NEXT_DATA__" type="application/json">'
            . '{"props":{"pageProps":{"dehydratedState":{"queries":[]}}}}'
            . '</script></body></html>';
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_search_page.html')),
            new MockResponse($detailHtml),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('query key "estate" missing');

        $client->hydrate($shallow);
    }
```

- [ ] **Step 2: Run SrealityClient tests**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Sreality/SrealityClientTest.php`
Expected: 16 tests PASS (9 from Task 3 + 7 new).

- [ ] **Step 3: Run the full suite**

Run: `docker compose run --rm bot vendor/bin/phpunit`
Expected: full suite green.

- [ ] **Step 4: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: PHPStan clean, ECS clean.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Source/Sreality/SrealityClientTest.php
git commit -m "test: defensive error-path coverage for SrealityClient

Cover the structural-drift and anti-bot signals that fetchNextData()
surfaces: missing __NEXT_DATA__, missing estatesSearch / estate query
keys, HTTP 403 (anti-bot), HTTP 429 (rate-limit), HTTP 503 (server),
malformed __NEXT_DATA__ JSON. Each exception carries a message
identifying the failure mode so var/monitor.log makes the cause
obvious when the bot starts failing.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: End-to-end verification

Confirm the full test suite is green, PHPStan and ECS are clean, and a real monitoring pass against live Sreality succeeds. This is the merge-gate.

**Files:** none (verification only).

- [ ] **Step 1: Full test suite + quality tools**

Run:

```bash
docker compose run --rm bot vendor/bin/phpunit
docker compose run --rm bot composer phpstan
docker compose run --rm bot composer ecs
```

Expected: phpunit green (≈101 total), PHPStan clean, ECS clean.

- [ ] **Step 2: Real monitoring pass — verify the live Sreality HTML works**

Make sure Docker Desktop is running, then:

```bash
docker compose run --rm bot rm -rf var/cache/prod
docker compose run --rm bot php bin/console app:monitor:run
```

Expected: `[OK] Monitoring pass finished.`, exit code 0. The run takes ~5-10 minutes (Sreality detail throttle dominates).

What to look for in `var/monitor.log` afterwards:
- No `[error] Sreality:` lines.
- Some `[info] Sreality: listing … deactivated (404)` lines for listings that were 404 (this is expected and OK — they are now marked seen on the same run, not retried forever).
- `Hydrate failed` count for Sreality should be ≪ 46/run going forward (the previous accumulation of 2199 retry-forever entries should clear within 1-2 cron runs).

- [ ] **Step 3: Confirm with the operator**

Ask the operator to verify in Telegram group "Номера" that Sreality messages started arriving again. The first run after merging should produce a fresh batch (up to `MONITOR_BATCH_LIMIT=50` Sreality sends plus whatever Bezrealitky finds).

- [ ] **Step 4: Commit any final fixes**

```bash
git add -A
git commit -m "chore: end-to-end verification fixes" || echo "nothing to commit"
```

---

## Self-Review

**1. Spec coverage** — every section of `2026-05-27-sreality-html-scraping-design.md` maps to a task:

- Goal / Context → covered by the migration overall (Tasks 1-5).
- Non-Goals → respected: no auth, no headless browser, no new sources, no public-contract changes.
- Architecture (fetchNextData, findQuery, search-page + detail-page traversal) → Task 2 rewrites `SrealityClient.php` with exactly these helpers.
- Data Flow (search-page Generator + hydrate detail) → Task 2's `fetchRecentListings` and `hydrate` match the spec's pseudocode line-by-line.
- Field Mapping (search → `mapShallow`; detail → `extractText`/`extractSeller`) → Task 2 implements both per the spec tables.
- `extractSeller` unified logic — `totalListingCount` permanently null going forward, `hasPremise` derived from `premise` presence → Task 2 implements exactly that; Task 3's agency test asserts `hasPremise=true, totalListingCount=null`; Task 3's private test asserts `hasPremise=false, totalListingCount=null`.
- `extractPhones` reading `seller.phones[].phone` (already E.164) → Task 2 implements; Task 3's agency test asserts the `+420608444111` phone.
- Defensive Instrumentation (table of HTTP/structure outcomes) → Task 2 maps every row; Task 4 covers them with explicit tests.
- 404 on detail → return listing unchanged → Task 2 implements via `allowNotFound: true` in `fetchNextData`; Task 3's `testHydrateReturnsListingUnchangedOnDetail404` pins the behaviour.
- Throttling (350 ms detail, 500 ms search) → Task 2's `throttleDetailCall` (preserved) + new `throttleSearchPage`; Task 3's `testHydrateThrottlesBetweenDetailCalls` covers detail timing.
- Testing (HTML fixtures replacing JSON, comprehensive integration + defensive tests) → Tasks 1, 3, 4 cover it exactly per the spec's fixture table.
- Migration (cache clear, monitor) → Task 5 Step 2 includes the cache clear and live pass.
- Database state (9959 seen entries stay, 2199 hydrate-failed clean up via 404 path) → addressed by Task 2's 404-returns-blank-listing behaviour; Task 5 Step 2 monitors the clean-up.
- Risks & Rollback → covered by defensive tests (Task 4) and Task 5's verification gate; spec already documents the `git revert` rollback path.
- Out of Scope items → none of them touched by any task.

**2. Placeholder scan** — no "TBD", "TODO", "implement later", or "similar to Task N". Every step shows complete code or an exact command with expected output.

**3. Type consistency** — verified across tasks:
- `SrealityClient` constructor signature `(HttpClientInterface, LoggerInterface, string $monitorRegion, string $monitorDealTypes)` is preserved from current `main`; no breaking change. Test calls `new SrealityClient($http, new NullLogger(), 'praha', 'sale')` consistently.
- `fetchNextData(string $url, bool $allowNotFound = false): ?array` — signature in Task 2 matches its only callers in `fetchRecentListings()` (no second arg) and `hydrate()` (`allowNotFound: true`).
- `findQuery(array $data, string $keyName): ?array` — Task 2 implementation matches its two callers (`'estatesSearch'` and `'estate'`).
- `extractSeller(array $estate): array{meta: ?SellerMeta, phones: list<string>}` — return shape matches `hydrate()`'s `$listing->withDetails(rawText: $text, sellerMeta: $seller['meta'], structuredPhones: $seller['phones'])` access pattern.
- `SellerMeta(bool $hasPremise, ?int $totalListingCount, ?string $name, ?string $email = null)` — Task 2's `new SellerMeta($hasPremise, null, $name, $email)` passes `null` for `totalListingCount` consistently; Task 3's tests assert `totalListingCount === null`.
- Field paths `props.pageProps.dehydratedState.queries[].queryKey[0]` — used identically in `findQuery` (Task 2), in the search-page fixture (Task 1 step 2), and in the detail fixtures (Task 1 steps 3-5).
- `$result['categoryMainCb']['value']`, `categorySubCb`, `categoryTypeCb` — Task 2's `unwrapCb()` matches the fixture shape `{name, value}`.
- `$result['locality']['citySeoName']`, `cityPartSeoName`, `city`, `cityPart` — Task 2's `composeLocalitySlug()` and `mapShallow()` read these; Task 1's search-page fixture provides them.
- URL output `https://www.sreality.cz/detail/prodej/byt/2+kk/praha-holesovice/111` in Task 3's `testFetchRecentListingsMapsShallowListings` matches what `buildDetailUrl(111, SALE, {category_main_cb:1, category_sub_cb:4, category_type_cb:1, locality:'praha-holesovice'})` produces from the corresponding fixture entry.
