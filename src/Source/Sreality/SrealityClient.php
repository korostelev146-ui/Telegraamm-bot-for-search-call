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
                    $this->logger->error('Sreality: query key "estatesSearch" missing', [
                        'url' => $url,
                    ]);
                    throw new \RuntimeException(sprintf('Sreality: query key "estatesSearch" missing at %s', $url));
                }

                $state = is_array($query['state'] ?? null) ? $query['state'] : [];
                $stateData = is_array($state['data'] ?? null) ? $state['data'] : [];
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
            $this->logger->error('Sreality: query key "estate" missing', [
                'url' => $listing->url,
            ]);
            throw new \RuntimeException(sprintf('Sreality: query key "estate" missing at %s', $listing->url));
        }

        $state = is_array($query['state'] ?? null) ? $query['state'] : [];
        $estate = is_array($state['data'] ?? null) ? $state['data'] : [];

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
            $this->logger->warning('Sreality: network error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(sprintf("Sreality: network error '%s' at %s", $e->getMessage(), $url), 0, $e);
        }

        if ($status === 404 && $allowNotFound) {
            return null;
        }

        if ($status === 401 || $status === 403) {
            $this->logger->error('Sreality: blocked, possibly anti-bot', [
                'url' => $url,
                'status' => $status,
            ]);
            throw new \RuntimeException(sprintf(
                'Sreality: HTTP %d at %s — possibly blocked, anti-bot triggered',
                $status,
                $url
            ));
        }

        if ($status === 429) {
            $this->logger->warning('Sreality: rate-limited', [
                'url' => $url,
            ]);
            throw new \RuntimeException(sprintf('Sreality: HTTP 429 at %s — rate-limited', $url));
        }

        if ($status >= 500) {
            $this->logger->warning('Sreality: server error', [
                'url' => $url,
                'status' => $status,
            ]);
            throw new \RuntimeException(sprintf('Sreality: HTTP %d at %s — server error', $status, $url));
        }

        if ($status !== 200) {
            $this->logger->warning('Sreality: unexpected HTTP status', [
                'url' => $url,
                'status' => $status,
            ]);
            throw new \RuntimeException(sprintf('Sreality: HTTP %d at %s', $status, $url));
        }

        try {
            $body = $response->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('Sreality: body read failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(sprintf('Sreality: body read failed at %s', $url), 0, $e);
        }

        if (preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $body, $matches) !== 1) {
            $this->logger->error('Sreality: __NEXT_DATA__ missing — page structure changed', [
                'url' => $url,
            ]);
            throw new \RuntimeException(sprintf('Sreality: __NEXT_DATA__ missing at %s', $url));
        }

        try {
            $decoded = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Sreality: __NEXT_DATA__ malformed JSON', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(sprintf('Sreality: __NEXT_DATA__ malformed JSON at %s', $url), 0, $e);
        }

        if (! is_array($decoded)) {
            $this->logger->error('Sreality: __NEXT_DATA__ not an object', [
                'url' => $url,
            ]);
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
        $props = is_array($data['props'] ?? null) ? $data['props'] : [];
        $pageProps = is_array($props['pageProps'] ?? null) ? $props['pageProps'] : [];
        $dehydrated = is_array($pageProps['dehydratedState'] ?? null) ? $pageProps['dehydratedState'] : [];
        $queries = $dehydrated['queries'] ?? null;
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
            return [
                'meta' => null,
                'phones' => [],
            ];
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
