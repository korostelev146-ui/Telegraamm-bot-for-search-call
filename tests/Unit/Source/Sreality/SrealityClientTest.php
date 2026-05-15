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
        $http = new MockHttpClient([new MockResponse($this->fixture('sreality_list.json'))]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $listings = iterator_to_array($client->fetchRecentListings(), false);

        self::assertCount(2, $listings);
        self::assertSame('sreality:111', $listings[0]->id);
        self::assertSame(Source::SREALITY, $listings[0]->source);
        self::assertSame(DealType::SALE, $listings[0]->dealType);
        self::assertSame('Praha 7 - Holesovice', $listings[0]->location);
        self::assertSame(
            'https://www.sreality.cz/detail/prodej/byt/2+kk/praha-7-holesovice/111',
            $listings[0]->url,
        );
        self::assertSame(
            'https://www.sreality.cz/detail/prodej/byt/3+1/praha-5-smichov/222',
            $listings[1]->url,
        );
    }

    public function testFetchRecentListingsBuildsDetailUrlWhenSeoIsMissing(): void
    {
        $http = new MockHttpClient([new MockResponse(
            '{"_embedded":{"estates":[{"hash_id":333,"name":"Byt","locality":"Praha"}]}}',
        )]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'rent');

        $listings = iterator_to_array($client->fetchRecentListings(), false);

        self::assertSame(
            'https://www.sreality.cz/detail/pronajem/byt/1+kk/praha/333',
            $listings[0]->url,
        );
    }

    public function testFetchRecentListingsCombinesSaleAndRent(): void
    {
        // For each deal type the client paginates until a short/empty page;
        // a one-item page counts as "short" and ends pagination for that type.
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_list.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertCount(4, iterator_to_array($client->fetchRecentListings(), false));
    }

    public function testHydratePrivateListingReadsTextAndContactFallback(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_private.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];
        $hydrated = $client->hydrate($shallow);

        // text is a {name, value} object — value must reach rawText.
        self::assertStringContainsString('Bez provize', $hydrated->rawText);
        // Private sellers have no _embedded.seller — fall back to top-level contact.
        self::assertNotNull($hydrated->sellerMeta);
        self::assertFalse($hydrated->sellerMeta->hasPremise);
        self::assertNull($hydrated->sellerMeta->totalListingCount);
        self::assertSame('Čenětická 2kk od vlastnika', $hydrated->sellerMeta->name);
        self::assertSame('ruslan76731@gmail.com', $hydrated->sellerMeta->email);
        // contact.phones is empty when unauthenticated.
        self::assertSame([], $hydrated->structuredPhones);
    }

    public function testHydrateAgencyListingMarksPremise(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_agency.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];
        $hydrated = $client->hydrate($shallow);

        self::assertStringContainsString('Bakers Court', $hydrated->rawText);
        self::assertNotNull($hydrated->sellerMeta);
        self::assertTrue($hydrated->sellerMeta->hasPremise);
        self::assertSame(6, $hydrated->sellerMeta->totalListingCount);
        self::assertSame('jiri@bakerscourt.cz', $hydrated->sellerMeta->email);
        self::assertSame(['+420608444111'], $hydrated->structuredPhones);
    }

    public function testHydrateMinimalDetailDegradesGracefully(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_minimal.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];
        $hydrated = $client->hydrate($shallow);

        self::assertSame('', $hydrated->rawText);
        self::assertNull($hydrated->sellerMeta);
        self::assertSame([], $hydrated->structuredPhones);
    }

    public function testHydrateThrottlesBetweenDetailCalls(): void
    {
        // Two list + two details = one back-to-back detail-call pair. The
        // second hydrate must wait at least THROTTLE_USEC microseconds after
        // the first; 350 ms is the production value (see SrealityClient).
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_private.json')),
            new MockResponse($this->fixture('sreality_detail_agency.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = iterator_to_array($client->fetchRecentListings(), false);
        $start = hrtime(true);
        $client->hydrate($shallow[0]);
        $client->hydrate($shallow[1]);
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);

        // First call has no throttle; second call must wait the full window.
        // Allow the 300 ms floor — the production value is 350 ms.
        self::assertGreaterThanOrEqual(300_000, $elapsedUs);
    }

    public function testFetchRecentListingsPaginatesAcrossPages(): void
    {
        // Page 1 returns 100 listings (a full page → keep paginating), page 2
        // returns 1 listing (a short page → stop). The client must yield all 101.
        $page1 = json_encode([
            '_embedded' => [
                'estates' => array_map(
                    static fn (int $i) => [
                        'hash_id' => 1000 + $i,
                        'name' => 'Prodej bytu',
                        'locality' => 'Praha',
                        'price' => 1,
                        'seo' => [
                            'category_main_cb' => 1,
                            'category_sub_cb' => 4,
                            'category_type_cb' => 1,
                            'locality' => 'praha',
                        ],
                    ],
                    range(1, 100),
                ),
            ],
        ]);
        $page2 = json_encode([
            '_embedded' => [
                'estates' => [[
                    'hash_id' => 9999,
                    'name' => 'Prodej bytu',
                    'locality' => 'Praha',
                    'price' => 1,
                    'seo' => [
                        'category_main_cb' => 1,
                        'category_sub_cb' => 4,
                        'category_type_cb' => 1,
                        'locality' => 'praha',
                    ],
                ]],
            ],
        ]);
        self::assertIsString($page1);
        self::assertIsString($page2);

        $http = new MockHttpClient([new MockResponse($page1), new MockResponse($page2)]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $ids = [];
        foreach ($client->fetchRecentListings() as $listing) {
            $ids[] = $listing->id;
        }

        self::assertCount(101, $ids);
        self::assertSame('sreality:1001', $ids[0]);
        self::assertSame('sreality:9999', $ids[100]);
    }

    public function testFetchRecentListingsStopsEarlyWhenConsumerBreaks(): void
    {
        // Only the first page response is queued — the test will fail with
        // "no more responses" if the client tries to fetch page 2.
        $http = new MockHttpClient([new MockResponse($this->fixture('sreality_list.json'))]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $first = null;
        foreach ($client->fetchRecentListings() as $listing) {
            $first = $listing;
            break;
        }

        self::assertNotNull($first);
        self::assertSame('sreality:111', $first->id);
    }
}
