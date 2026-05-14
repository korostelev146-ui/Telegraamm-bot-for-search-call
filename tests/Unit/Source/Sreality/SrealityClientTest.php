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
        // The public detail URL needs the full SEO path; a bare /detail/{id} 404s.
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

        $listings = $client->fetchRecentListings();

        // No SEO block — fall back to deal-type-derived path with valid slug
        // tokens; Sreality 301-redirects a non-canonical path to the real page.
        self::assertSame(
            'https://www.sreality.cz/detail/pronajem/byt/1+kk/praha/333',
            $listings[0]->url,
        );
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

    public function testHydratePrivateListingReadsTextAndContactFallback(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_private.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = $client->fetchRecentListings()[0];
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

        $shallow = $client->fetchRecentListings()[0];
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

        $shallow = $client->fetchRecentListings()[0];
        $hydrated = $client->hydrate($shallow);

        self::assertSame('', $hydrated->rawText);
        self::assertNull($hydrated->sellerMeta);
        self::assertSame([], $hydrated->structuredPhones);
    }
}
