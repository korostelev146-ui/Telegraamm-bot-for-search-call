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

        $listings = $client->fetchRecentListings();

        self::assertCount(2, $listings);
        self::assertSame('sreality:111', $listings[0]->id);
        self::assertSame(Source::SREALITY, $listings[0]->source);
        self::assertSame(DealType::SALE, $listings[0]->dealType);
        self::assertSame('Praha 7 - Holesovice', $listings[0]->location);
        self::assertStringContainsString('111', $listings[0]->url);
    }

    public function testFetchRecentListingsCombinesSaleAndRent(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_list.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertCount(4, $client->fetchRecentListings());
    }

    public function testHydratePrivateListingFillsSellerMetaAndPhones(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_private.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = $client->fetchRecentListings()[0];
        $hydrated = $client->hydrate($shallow);

        self::assertStringContainsString('777 123 456', $hydrated->rawText);
        self::assertNotNull($hydrated->sellerMeta);
        self::assertFalse($hydrated->sellerMeta->hasPremise);
        self::assertSame(1, $hydrated->sellerMeta->totalListingCount);
        self::assertSame('Jan Novak', $hydrated->sellerMeta->name);
        self::assertSame(['+420777123456'], $hydrated->structuredPhones);
    }

    public function testHydrateAgencyListingMarksPremise(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_agency.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = $client->fetchRecentListings()[0];
        $hydrated = $client->hydrate($shallow);

        self::assertNotNull($hydrated->sellerMeta);
        self::assertTrue($hydrated->sellerMeta->hasPremise);
        self::assertSame(9, $hydrated->sellerMeta->totalListingCount);
    }
}
