<?php

declare(strict_types=1);

namespace App\Source\Bezrealitky;

use App\Domain\DealType;
use App\Domain\Listing;
use App\Domain\Source;
use App\Source\ListingSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bezrealitky source over GraphQL. The list query already returns the full
 * description, so hydrate() is the identity. Bezrealitky exposes no structured
 * phone or seller metadata unauthenticated, so structuredPhones is always empty
 * and sellerMeta is always null — phone numbers come purely from the text.
 */
final class BezrealitkyClient implements ListingSource
{
    private const GRAPHQL_URL = 'https://api.bezrealitky.cz/graphql/';

    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /**
     * Bezrealitky internal region ids (verified via the `czechRegions` GraphQL
     * query). Each value is the numeric id Bezrealitky uses as `regionId`.
     */
    private const REGION_IDS = [
        'praha' => '486',
        'stredocesky' => '490',
    ];

    private const DEAL_TYPE_CODES = [
        'sale' => 'PRODEJ',
        'rent' => 'PRONAJEM',
    ];

    /**
     * Bezrealitky `EstateType` enum values for the building kinds we monitor.
     */
    private const ESTATE_TYPE_CODES = [
        'apartment' => 'BYT',
        'house' => 'DUM',
    ];

    /**
     * Page size for the GraphQL list call. A "short" page (strictly fewer items
     * than this) marks the last page.
     */
    private const PER_PAGE = 100;

    private const QUERY = <<<'GQL'
        query AdvertList($offerType: [OfferType], $estateType: [EstateType], $regionId: ID, $limit: Int, $offset: Int, $order: ResultOrder) {
            listAdverts(offerType: $offerType, estateType: $estateType, regionId: $regionId, limit: $limit, offset: $offset, order: $order) {
                totalCount
                list {
                    id
                    uri
                    title
                    address(locale: CS)
                    price
                    offerType
                    description
                }
            }
        }
        GQL;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $monitorBezrealitkyRegions,
        private readonly string $monitorDealTypes,
        private readonly string $monitorEstateTypes = 'apartment',
    ) {
    }

    public function fetchRecentListings(): iterable
    {
        $offerTypes = $this->offerTypes();
        $estateTypes = $this->estateTypes();

        foreach ($this->regionIds() as $regionId) {
            $offset = 0;
            while (true) {
                $variables = [
                    'offerType' => $offerTypes,
                    'estateType' => $estateTypes,
                    'regionId' => $regionId,
                    'limit' => self::PER_PAGE,
                    'offset' => $offset,
                    'order' => 'TIMEORDER_DESC',
                ];

                $this->logger->info('Bezrealitky GraphQL request', [
                    'variables' => $variables,
                ]);

                $data = $this->httpClient->request('POST', self::GRAPHQL_URL, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'User-Agent' => self::USER_AGENT,
                    ],
                    'json' => [
                        'query' => self::QUERY,
                        'operationName' => 'AdvertList',
                        'variables' => $variables,
                    ],
                ])->toArray();

                $dataSection = is_array($data['data'] ?? null) ? $data['data'] : [];
                $listAdverts = is_array($dataSection['listAdverts'] ?? null) ? $dataSection['listAdverts'] : [];
                $rawList = is_array($listAdverts['list'] ?? null) ? $listAdverts['list'] : [];

                if ($rawList === []) {
                    break;
                }

                foreach ($rawList as $item) {
                    if (is_array($item)) {
                        yield $this->map($item);
                    }
                }

                if (count($rawList) < self::PER_PAGE) {
                    break; // short page → no more results for this region
                }

                $offset += self::PER_PAGE;
            }
        }
    }

    public function hydrate(Listing $listing): Listing
    {
        return $listing; // the list query already returned everything we can get
    }

    /**
     * @return list<string>
     */
    private function offerTypes(): array
    {
        $codes = [];
        foreach (explode(',', $this->monitorDealTypes) as $raw) {
            $code = self::DEAL_TYPE_CODES[trim($raw)] ?? null;
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $codes === [] ? [self::DEAL_TYPE_CODES['sale']] : $codes;
    }

    /**
     * @return list<string>
     */
    private function estateTypes(): array
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
     * @return list<string>
     */
    private function regionIds(): array
    {
        $ids = [];
        foreach (explode(',', $this->monitorBezrealitkyRegions) as $raw) {
            $id = self::REGION_IDS[trim($raw)] ?? null;
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids === [] ? [self::REGION_IDS['praha']] : $ids;
    }

    /**
     * @param array<mixed, mixed> $item
     */
    private function map(array $item): Listing
    {
        $rawId = $item['id'] ?? '';
        $id = is_string($rawId) ? $rawId : (is_int($rawId) ? (string) $rawId : '');
        $uri = is_string($item['uri'] ?? null) ? $item['uri'] : '';
        $title = is_string($item['title'] ?? null) ? $item['title'] : '';
        $address = is_string($item['address'] ?? null) ? $item['address'] : '';
        $price = is_int($item['price'] ?? null) ? $item['price'] : null;
        $description = is_string($item['description'] ?? null) ? $item['description'] : '';
        $offerType = is_string($item['offerType'] ?? null) ? $item['offerType'] : 'PRODEJ';

        return new Listing(
            id: 'bezrealitky:' . $id,
            source: Source::BEZREALITKY,
            title: $title,
            price: $price,
            dealType: $offerType === 'PRONAJEM' ? DealType::RENT : DealType::SALE,
            location: $address,
            url: 'https://www.bezrealitky.cz/nemovitosti-byty-domy/' . $uri,
            rawText: $description,
            sellerMeta: null,
            structuredPhones: [],
        );
    }
}
