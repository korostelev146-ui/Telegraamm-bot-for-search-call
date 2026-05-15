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
 * Sreality source. The list endpoint returns shallow listings; hydrate() fetches
 * the detail endpoint, which is the only place description text, seller metadata
 * and structured phones are exposed.
 */
final class SrealityClient implements ListingSource
{
    private const LIST_URL = 'https://www.sreality.cz/api/cs/v2/estates';

    private const DETAIL_URL = 'https://www.sreality.cz/api/cs/v2/estates/';

    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /**
     * Sreality region id for Prague (verified in reconnaissance).
     */
    private const REGION_IDS = [
        'praha' => 10,
    ];

    /**
     * Sreality category_main_cb codes for the building kinds we monitor.
     */
    private const ESTATE_TYPE_CODES = [
        'apartment' => 1, // byt
        'house' => 2,     // dum
    ];

    /**
     * Sreality category_type_cb codes.
     */
    private const DEAL_TYPE_CODES = [
        'sale' => 1,
        'rent' => 2,
    ];

    /**
     * `per_page` is honoured by Sreality up to 100 (verified empirically); a
     * "short" page (strictly fewer items than this) marks the last page.
     */
    private const PER_PAGE = 100;

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
        // Houses (category_main_cb = 2). The canonical slugs are short forms;
        // Sreality 301-redirects a wrong-but-recognised token to the right one,
        // so the `1+kk` apartment fallback also resolves for unmapped houses.
        33 => 'chata',
        37 => 'rodinny',
        39 => 'vila',
        54 => 'vicegeneracni',
    ];

    /**
     * Minimum gap between detail-endpoint calls, in microseconds. A full scan
     * fires hundreds of detail requests; pacing them ~350 ms apart keeps the
     * request rate within what a real browser would produce.
     */
    private const THROTTLE_USEC = 350_000;

    private int $lastDetailCallAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $monitorRegion,
        private readonly string $monitorDealTypes,
        private readonly string $monitorEstateTypes = 'apartment',
    ) {
    }

    public function fetchRecentListings(): iterable
    {
        $regionId = self::REGION_IDS[$this->monitorRegion] ?? self::REGION_IDS['praha'];

        foreach ($this->estateCategories() as $estateCategory) {
            foreach ($this->dealTypes() as $dealType) {
                $page = 1;
                while (true) {
                    $query = http_build_query([
                        'category_main_cb' => $estateCategory,
                        'category_type_cb' => self::DEAL_TYPE_CODES[$dealType->value],
                        'locality_region_id' => $regionId,
                        'per_page' => self::PER_PAGE,
                        'page' => $page,
                        'sort' => 'date',
                    ]);

                    $this->logger->info('Sreality list request', [
                        'query' => $query,
                    ]);

                    $data = $this->httpClient
                        ->request('GET', self::LIST_URL . '?' . $query, $this->options())
                        ->toArray();

                    $embedded = is_array($data['_embedded'] ?? null) ? $data['_embedded'] : [];
                    $estates = is_array($embedded['estates'] ?? null) ? $embedded['estates'] : [];

                    if ($estates === []) {
                        break;
                    }

                    foreach ($estates as $estate) {
                        if (is_array($estate)) {
                            yield $this->mapShallow($estate, $dealType);
                        }
                    }

                    if (count($estates) < self::PER_PAGE) {
                        break; // short page → no more results
                    }

                    ++$page;
                }
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

        $hashId = substr($listing->id, strlen('sreality:'));

        $this->logger->info('Sreality detail request', [
            'hash_id' => $hashId,
        ]);

        $this->throttleDetailCall();

        $data = $this->httpClient
            ->request('GET', self::DETAIL_URL . $hashId, $this->options())
            ->toArray();

        $text = $this->extractText($data);
        $seller = $this->extractSeller($data);

        return $listing->withDetails(
            rawText: $text,
            sellerMeta: $seller['meta'],
            structuredPhones: $seller['phones'],
        );
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
     * @return list<int>
     */
    private function estateCategories(): array
    {
        $codes = [];
        foreach (explode(',', $this->monitorEstateTypes) as $raw) {
            $code = self::ESTATE_TYPE_CODES[trim($raw)] ?? null;
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $codes === [] ? [self::ESTATE_TYPE_CODES['apartment']] : $codes;
    }

    /**
     * @param array<mixed, mixed> $estate
     */
    private function mapShallow(array $estate, DealType $dealType): Listing
    {
        $hashId = is_int($estate['hash_id'] ?? null) ? $estate['hash_id'] : 0;
        $name = is_string($estate['name'] ?? null) ? $estate['name'] : '';
        $locality = is_string($estate['locality'] ?? null) ? $estate['locality'] : '';
        $price = is_int($estate['price'] ?? null) ? $estate['price'] : null;

        return new Listing(
            id: 'sreality:' . $hashId,
            source: Source::SREALITY,
            title: $name,
            price: $price,
            dealType: $dealType,
            location: $locality,
            url: $this->buildDetailUrl($hashId, $dealType, $estate['seo'] ?? null),
            rawText: '',
            sellerMeta: null,
            structuredPhones: [],
        );
    }

    /**
     * Builds the public detail-page URL from the list endpoint's `seo` block:
     * /detail/{type}/{main}/{sub}/{locality}/{hash_id}. Missing or unrecognised
     * codes fall back to valid tokens (the deal type for the transaction, `byt`,
     * `1+kk`, `praha`) — Sreality 301-redirects a non-canonical path to the real
     * listing, so the only true 404 is a bare /detail/{hash_id}.
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
     * Reads an integer SEO category code; a missing or non-integer value yields
     * 0, which no slug map contains, so the caller falls back to a valid token.
     *
     * @param array<mixed, mixed> $seo
     */
    private function seoCode(array $seo, string $key): int
    {
        $value = $seo[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * The detail endpoint returns `text` as a {name, value} object. Fall back to
     * a bare string for resilience against future shape changes.
     *
     * @param array<mixed, mixed> $data
     */
    private function extractText(array $data): string
    {
        $text = $data['text'] ?? null;
        if (is_array($text)) {
            return is_string($text['value'] ?? null) ? $text['value'] : '';
        }

        return is_string($text) ? $text : '';
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array{meta: ?SellerMeta, phones: list<string>}
     */
    private function extractSeller(array $data): array
    {
        $embedded = is_array($data['_embedded'] ?? null) ? $data['_embedded'] : [];
        $seller = is_array($embedded['seller'] ?? null) ? $embedded['seller'] : null;

        if ($seller !== null) {
            return $this->extractBrokerSeller($seller);
        }

        // Private sellers have no broker profile under _embedded.seller; their
        // name, e-mail and (only when logged in) phone live in the top-level
        // `contact` object instead.
        $contact = is_array($data['contact'] ?? null) ? $data['contact'] : null;
        if ($contact !== null) {
            return $this->extractContactSeller($contact);
        }

        return [
            'meta' => null,
            'phones' => [],
        ];
    }

    /**
     * @param array<mixed, mixed> $seller
     * @return array{meta: SellerMeta, phones: list<string>}
     */
    private function extractBrokerSeller(array $seller): array
    {
        $sellerEmbedded = is_array($seller['_embedded'] ?? null) ? $seller['_embedded'] : [];
        $hasPremise = isset($sellerEmbedded['premise']);

        $name = is_string($seller['user_name'] ?? null) ? $seller['user_name'] : null;
        $email = is_string($seller['email'] ?? null) ? $seller['email'] : null;

        $specialization = is_array($seller['specialization'] ?? null) ? $seller['specialization'] : [];
        $categories = is_array($specialization['category'] ?? null) ? $specialization['category'] : [];
        $totalListingCount = null;
        if ($categories !== []) {
            $totalListingCount = 0;
            foreach ($categories as $category) {
                if (is_array($category) && is_int($category['num'] ?? null)) {
                    $totalListingCount += $category['num'];
                }
            }
        }

        return [
            'meta' => new SellerMeta($hasPremise, $totalListingCount, $name, $email),
            'phones' => $this->extractPhones($seller['phones'] ?? null),
        ];
    }

    /**
     * @param array<mixed, mixed> $contact
     * @return array{meta: SellerMeta, phones: list<string>}
     */
    private function extractContactSeller(array $contact): array
    {
        $name = is_string($contact['name'] ?? null) ? $contact['name'] : null;
        $email = is_string($contact['email'] ?? null) ? $contact['email'] : null;

        // A bare contact carries no premise or listing-count signal — treat it
        // as a private seller (hasPremise: false, totalListingCount: null).
        return [
            'meta' => new SellerMeta(false, null, $name, $email),
            'phones' => $this->extractPhones($contact['phones'] ?? null),
        ];
    }

    /**
     * Canonicalises a Sreality phones array (list of {code, type, number}) to a
     * de-duplicated list of E.164 numbers.
     *
     * @return list<string>
     */
    private function extractPhones(mixed $rawPhones): array
    {
        $phones = [];
        foreach (is_array($rawPhones) ? $rawPhones : [] as $phone) {
            if (! is_array($phone)) {
                continue;
            }
            $number = is_string($phone['number'] ?? null) ? $phone['number'] : '';
            $e164 = $this->toE164($number);
            if ($e164 !== null) {
                $phones[$e164] = true;
            }
        }

        return array_keys($phones);
    }

    /**
     * Canonicalises a Czech phone number to "+420" + 9 digits, matching the format
     * PhoneDetector produces, so ContactRegistry keys stay consistent across sources.
     */
    private function toE164(string $number): ?string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        if (strlen($digits) < 9) {
            return null;
        }

        // The Czech national number is always the last 9 digits (any 420 / 00420
        // country-code prefix sits in front of it).
        return '+420' . substr($digits, -9);
    }

    /**
     * @return array{headers: array<string, string>}
     */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
        ];
    }
}
