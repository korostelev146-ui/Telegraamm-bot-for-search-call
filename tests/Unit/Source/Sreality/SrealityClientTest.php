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
}
