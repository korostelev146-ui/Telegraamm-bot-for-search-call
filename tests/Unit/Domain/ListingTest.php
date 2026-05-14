<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\DealType;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;
use App\Domain\SellerMeta;
use App\Domain\Source;
use PHPUnit\Framework\TestCase;

final class ListingTest extends TestCase
{
    public function testListingHoldsNormalisedFields(): void
    {
        $listing = new Listing(
            id: 'sreality:602509388',
            source: Source::SREALITY,
            title: 'Prodej bytu 3+1 89 m2',
            price: 8_500_000,
            dealType: DealType::SALE,
            location: 'Praha - Zbraslav',
            url: 'https://www.sreality.cz/detail/602509388',
            rawText: 'Volejte na 777 123 456',
            sellerMeta: new SellerMeta(hasPremise: false, totalListingCount: 1, name: 'Jan Novak'),
            structuredPhones: ['+420774956705'],
        );

        self::assertSame('sreality:602509388', $listing->id);
        self::assertSame(Source::SREALITY, $listing->source);
        self::assertSame(DealType::SALE, $listing->dealType);
        self::assertFalse($listing->sellerMeta?->hasPremise);
        self::assertSame(['+420774956705'], $listing->structuredPhones);
    }

    public function testListingWithoutSellerMetaIsAllowed(): void
    {
        $listing = new Listing(
            id: 'bezrealitky:1002810',
            source: Source::BEZREALITKY,
            title: 'Prodej bytu 2+kk',
            price: null,
            dealType: DealType::SALE,
            location: 'Praha 7 - Holesovice',
            url: 'https://www.bezrealitky.cz/nemovitosti-byty-domy/1002810',
            rawText: 'Bez realitky, primo od majitele',
            sellerMeta: null,
            structuredPhones: [],
        );

        self::assertNull($listing->sellerMeta);
        self::assertSame([], $listing->structuredPhones);
    }

    public function testDetectedPhoneCarriesOriginAndMarker(): void
    {
        $phone = new DetectedPhone(
            e164: '+420777123456',
            raw: '777 123 456',
            origin: PhoneOrigin::TEXT,
            marker: 'volejte',
        );

        self::assertSame('+420777123456', $phone->e164);
        self::assertSame(PhoneOrigin::TEXT, $phone->origin);
        self::assertSame('volejte', $phone->marker);
    }

    public function testWithDetailsReplacesDetailFieldsAndPreservesIdentity(): void
    {
        $original = new Listing(
            id: 'sreality:123456789',
            source: Source::SREALITY,
            title: 'Prodej bytu 2+1',
            price: 5_000_000,
            dealType: DealType::SALE,
            location: 'Praha 2 - Vinohrady',
            url: 'https://www.sreality.cz/detail/123456789',
            rawText: 'Volejte na 777 111 111',
            sellerMeta: new SellerMeta(hasPremise: true, totalListingCount: 5, name: 'Old Name'),
            structuredPhones: ['+420111111111'],
        );

        $updated = $original->withDetails(
            'NEW raw text',
            new SellerMeta(hasPremise: true, totalListingCount: 9, name: 'New Name'),
            ['+420222222222'],
        );

        // Assert detail fields are replaced
        self::assertSame('NEW raw text', $updated->rawText);
        self::assertSame(true, $updated->sellerMeta?->hasPremise);
        self::assertSame(9, $updated->sellerMeta?->totalListingCount);
        self::assertSame('New Name', $updated->sellerMeta?->name);
        self::assertSame(['+420222222222'], $updated->structuredPhones);

        // Assert identity fields are preserved
        self::assertSame('sreality:123456789', $updated->id);
        self::assertSame(Source::SREALITY, $updated->source);
        self::assertSame('Prodej bytu 2+1', $updated->title);
        self::assertSame(5_000_000, $updated->price);
        self::assertSame(DealType::SALE, $updated->dealType);
        self::assertSame('Praha 2 - Vinohrady', $updated->location);
        self::assertSame('https://www.sreality.cz/detail/123456789', $updated->url);

        // Assert immutability
        self::assertNotSame($original, $updated);
    }
}
