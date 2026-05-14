<?php

declare(strict_types=1);

namespace App\Tests\Unit\Phone;

use App\Domain\DealType;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;
use App\Domain\Source;
use App\Phone\PhoneDetector;
use PHPUnit\Framework\TestCase;

final class PhoneDetectorTest extends TestCase
{
    private function listing(string $rawText, array $structuredPhones = []): Listing
    {
        return new Listing(
            id: 'sreality:1',
            source: Source::SREALITY,
            title: 't',
            price: null,
            dealType: DealType::SALE,
            location: 'Praha',
            url: 'https://example.test/1',
            rawText: $rawText,
            sellerMeta: null,
            structuredPhones: $structuredPhones,
        );
    }

    public function testExtractsSpacedNumberFromText(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Volejte mi na 777 123 456 kdykoliv'));

        self::assertCount(1, $phones);
        self::assertSame('+420777123456', $phones[0]->e164);
        self::assertSame(PhoneOrigin::TEXT, $phones[0]->origin);
        self::assertSame('volejte', $phones[0]->marker);
    }

    public function testExtractsNumberWithExplicitPrefix(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('tel +420 608 444 111'));

        self::assertCount(1, $phones);
        self::assertSame('+420608444111', $phones[0]->e164);
        self::assertSame('tel', $phones[0]->marker);
    }

    public function testExtractsCompactNineDigitNumber(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('kontakt 731431957'));

        self::assertCount(1, $phones);
        self::assertSame('+420731431957', $phones[0]->e164);
    }

    public function testDoesNotMatchPrice(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Cena 8 500 000 Kc, k jednani'));

        self::assertSame([], $phones);
    }

    public function testDoesNotMatchCompactNumberFollowedByCurrency(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Cena 750000000 Kc'));

        self::assertSame([], $phones);
    }

    public function testStructuredPhonesAreIncludedAndDeduplicated(): void
    {
        $listing = $this->listing(
            rawText: 'Volejte na 774 956 705',
            structuredPhones: ['+420774956705'],
        );

        $phones = (new PhoneDetector())->detect($listing);

        self::assertCount(1, $phones);
        self::assertSame('+420774956705', $phones[0]->e164);
        self::assertSame(PhoneOrigin::STRUCTURED, $phones[0]->origin);
    }

    public function testMarkerIsNullWhenNoMarkerWordPrecedesNumber(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Hezky byt, 777 123 456, sluneny'));

        self::assertCount(1, $phones);
        self::assertNull($phones[0]->marker);
    }

    public function testReturnsEmptyWhenNothingFound(): void
    {
        self::assertSame([], (new PhoneDetector())->detect($this->listing('Zadny kontakt zde')));
    }

    public function testRejectsNumberFollowedByCzkLabel(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Sleva 731 431 957 CZK k dispozici'));

        self::assertSame([], $phones);
    }

    public function testRejectsPriceWithWideWhitespaceBeforeCurrency(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Cena 250 000 000      Kc'));

        self::assertSame([], $phones);
    }

    public function testExtractsMultiplePhonesFromOneText(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Volejte 777 123 456 nebo 608 444 111'));

        self::assertCount(2, $phones);
        $e164 = array_map(static fn ($p) => $p->e164, $phones);
        self::assertContains('+420777123456', $e164);
        self::assertContains('+420608444111', $e164);
    }

    public function testExtractsPhoneAtStartOfText(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('608444111 je moje cislo'));

        self::assertCount(1, $phones);
        self::assertSame('+420608444111', $phones[0]->e164);
        self::assertNull($phones[0]->marker);
    }
}
