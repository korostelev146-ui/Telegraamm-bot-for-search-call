<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source\Bezrealitky;

use App\Domain\DealType;
use App\Domain\Source;
use App\Source\Bezrealitky\BezrealitkyClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BezrealitkyClientTest extends TestCase
{
    private function fixture(): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/bezrealitky_list.json');
        self::assertIsString($contents);

        return $contents;
    }

    public function testFetchRecentListingsMapsFullListings(): void
    {
        $http = new MockHttpClient([new MockResponse($this->fixture())]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent');

        $listings = iterator_to_array($client->fetchRecentListings(), false);

        self::assertCount(2, $listings);
        self::assertSame('bezrealitky:1002810', $listings[0]->id);
        self::assertSame(Source::BEZREALITKY, $listings[0]->source);
        self::assertSame(DealType::SALE, $listings[0]->dealType);
        self::assertSame(DealType::RENT, $listings[1]->dealType);
        self::assertStringContainsString('608 444 111', $listings[0]->rawText);
        self::assertStringContainsString('bezrealitky.cz', $listings[0]->url);
        self::assertNull($listings[0]->sellerMeta);
        self::assertSame([], $listings[0]->structuredPhones);
    }

    public function testHydrateIsIdentity(): void
    {
        $http = new MockHttpClient([new MockResponse($this->fixture())]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent');

        $listing = iterator_to_array($client->fetchRecentListings(), false)[0];

        self::assertSame($listing, $client->hydrate($listing));
    }

    public function testMalformedGraphQlResponseYieldsEmptyList(): void
    {
        $http = new MockHttpClient([new MockResponse('{"data":null}')]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertSame([], iterator_to_array($client->fetchRecentListings(), false));
    }

    public function testFetchRecentListingsPaginatesAcrossPages(): void
    {
        $page1List = array_map(
            static fn (int $i) => [
                'id' => (string) (2_000_000 + $i),
                'uri' => 'byt-' . $i,
                'title' => 'Prodej bytu',
                'address' => 'Praha',
                'price' => 1,
                'offerType' => 'PRODEJ',
                'description' => 'desc',
            ],
            range(1, 100),
        );
        $page1 = json_encode(['data' => ['listAdverts' => ['totalCount' => 101, 'list' => $page1List]]]);
        $page2 = json_encode(['data' => ['listAdverts' => ['totalCount' => 101, 'list' => [[
            'id' => '9999999',
            'uri' => 'last',
            'title' => 'Last',
            'address' => 'Praha',
            'price' => 1,
            'offerType' => 'PRODEJ',
            'description' => 'last',
        ]]]]]);
        self::assertIsString($page1);
        self::assertIsString($page2);

        $http = new MockHttpClient([new MockResponse($page1), new MockResponse($page2)]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale');

        $ids = array_map(
            static fn ($l) => $l->id,
            iterator_to_array($client->fetchRecentListings(), false),
        );

        self::assertCount(101, $ids);
        self::assertSame('bezrealitky:9999999', $ids[100]);
    }

    public function testFetchRecentListingsStopsAfterShortPage(): void
    {
        // A single short page (fewer items than PER_PAGE = 100) ends pagination
        // — the client must not request page 2.
        $http = new MockHttpClient([new MockResponse($this->fixture())]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertCount(2, iterator_to_array($client->fetchRecentListings(), false));
    }

    public function testFetchRecentListingsLoopsOverEveryConfiguredRegion(): void
    {
        // Two regions configured ⇒ two GraphQL calls (each a short page).
        $regionPage = json_encode(['data' => ['listAdverts' => ['totalCount' => 1, 'list' => [[
            'id' => '1',
            'uri' => 'one',
            'title' => 'One',
            'address' => 'X',
            'price' => 1,
            'offerType' => 'PRODEJ',
            'description' => 'x',
        ]]]]]);
        self::assertIsString($regionPage);

        $http = new MockHttpClient([new MockResponse($regionPage), new MockResponse($regionPage)]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha,stredocesky', 'sale');

        // One listing yielded per region — two in total.
        self::assertCount(2, iterator_to_array($client->fetchRecentListings(), false));
    }

    public function testFetchRecentListingsPassesEstateTypeArrayToGraphQl(): void
    {
        // Capture the JSON body sent to the API and confirm both BYT and DUM
        // travel together in one estateType array, not as two separate calls.
        $captured = [];
        $regionPage = json_encode(['data' => ['listAdverts' => ['totalCount' => 0, 'list' => []]]]);
        self::assertIsString($regionPage);

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $regionPage) {
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            $captured[] = $body;

            return new MockResponse($regionPage);
        });
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent', 'apartment,house');

        iterator_to_array($client->fetchRecentListings(), false);

        self::assertCount(1, $captured, 'one region ⇒ exactly one GraphQL call');
        $decoded = json_decode($captured[0], true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['variables'] ?? null);
        self::assertSame(['BYT', 'DUM'], $decoded['variables']['estateType']);
        self::assertSame(['PRODEJ', 'PRONAJEM'], $decoded['variables']['offerType']);
    }
}
