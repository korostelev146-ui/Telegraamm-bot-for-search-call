<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Domain\Classification;
use App\Domain\Confidence;
use App\Domain\DealType;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;
use App\Domain\Source;
use App\Domain\Verdict;
use App\Notification\MessageFormatter;
use PHPUnit\Framework\TestCase;

final class MessageFormatterTest extends TestCase
{
    private function listing(): Listing
    {
        return new Listing(
            id: 'sreality:111',
            source: Source::SREALITY,
            title: 'Prodej bytu 2+kk 50 m2',
            price: 7_500_000,
            dealType: DealType::SALE,
            location: 'Praha 7 - Holesovice',
            url: 'https://www.sreality.cz/detail/111',
            rawText: 'Volejte na 777 123 456',
            sellerMeta: null,
            structuredPhones: [],
        );
    }

    public function testFormatsOwnerListing(): void
    {
        $message = (new MessageFormatter())->format(
            $this->listing(),
            new Verdict(Classification::OWNER, Confidence::HIGH, ['owner self-identification: "primo od majitele"']),
            [new DetectedPhone('+420777123456', '777 123 456', PhoneOrigin::TEXT, 'volejte')],
        );

        self::assertStringContainsString('Prodej bytu 2+kk 50 m2', $message);
        self::assertStringContainsString('Praha 7 - Holesovice', $message);
        self::assertStringContainsString('7 500 000', $message);
        self::assertStringContainsString('+420777123456', $message);
        self::assertStringContainsString('https://www.sreality.cz/detail/111', $message);
        self::assertStringContainsString('majitel', $message);
        self::assertStringContainsString('Sreality', $message);
    }

    public function testFormatsUnknownListingWithQuestionBadge(): void
    {
        $message = (new MessageFormatter())->format(
            $this->listing(),
            new Verdict(Classification::UNKNOWN, Confidence::LOW, ['no decisive signal']),
            [new DetectedPhone('+420777123456', '777 123 456', PhoneOrigin::TEXT, null)],
        );

        self::assertStringContainsString('nejasné', $message);
    }

    public function testFormatsMultiplePhones(): void
    {
        $message = (new MessageFormatter())->format(
            $this->listing(),
            new Verdict(Classification::OWNER, Confidence::HIGH, ['x']),
            [
                new DetectedPhone('+420777123456', '777 123 456', PhoneOrigin::TEXT, null),
                new DetectedPhone('+420608444111', '608 444 111', PhoneOrigin::STRUCTURED, null),
            ],
        );

        self::assertStringContainsString('+420777123456', $message);
        self::assertStringContainsString('+420608444111', $message);
    }
}
