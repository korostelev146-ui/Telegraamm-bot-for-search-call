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

        $listings = $client->fetchRecentListings();

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

        $listing = $client->fetchRecentListings()[0];

        self::assertSame($listing, $client->hydrate($listing));
    }

    public function testMalformedGraphQlResponseYieldsEmptyList(): void
    {
        $http = new MockHttpClient([new MockResponse('{"data":null}')]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertSame([], $client->fetchRecentListings());
    }
}
