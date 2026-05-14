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
     * Sreality category_main_cb for apartments.
     */
    private const APARTMENT_CATEGORY = 1;

    /**
     * Sreality category_type_cb codes.
     */
    private const DEAL_TYPE_CODES = [
        'sale' => 1,
        'rent' => 2,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $monitorRegion,
        private readonly string $monitorDealTypes,
    ) {
    }

    public function fetchRecentListings(): array
    {
        $regionId = self::REGION_IDS[$this->monitorRegion] ?? self::REGION_IDS['praha'];
        $listings = [];

        foreach ($this->dealTypes() as $dealType) {
            $query = http_build_query([
                'category_main_cb' => self::APARTMENT_CATEGORY,
                'category_type_cb' => self::DEAL_TYPE_CODES[$dealType->value],
                'locality_region_id' => $regionId,
                'per_page' => 60,
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

            foreach ($estates as $estate) {
                if (is_array($estate)) {
                    $listings[] = $this->mapShallow($estate, $dealType);
                }
            }
        }

        return $listings;
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
            url: 'https://www.sreality.cz/detail/' . $hashId,
            rawText: '',
            sellerMeta: null,
            structuredPhones: [],
        );
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
