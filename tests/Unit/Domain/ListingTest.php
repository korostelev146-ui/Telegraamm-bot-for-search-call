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
}
