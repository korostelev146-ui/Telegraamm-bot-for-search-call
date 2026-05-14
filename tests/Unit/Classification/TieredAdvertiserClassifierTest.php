<?php

declare(strict_types=1);

namespace App\Tests\Unit\Classification;

use App\Classification\TieredAdvertiserClassifier;
use App\Domain\Classification;
use App\Domain\DealType;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;
use App\Domain\SellerMeta;
use App\Domain\Source;
use App\Persistence\ContactRegistry;
use App\Persistence\Database;
use PHPUnit\Framework\TestCase;

final class TieredAdvertiserClassifierTest extends TestCase
{
    private ContactRegistry $registry;
    private TieredAdvertiserClassifier $classifier;

    protected function setUp(): void
    {
        $database = new Database(':memory:');
        $database->migrate();
        $this->registry = new ContactRegistry($database);
        $this->classifier = new TieredAdvertiserClassifier($this->registry);
    }

    private function listing(string $rawText, ?SellerMeta $sellerMeta): Listing
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
            sellerMeta: $sellerMeta,
            structuredPhones: [],
        );
    }

    private function phone(string $e164): DetectedPhone
    {
        return new DetectedPhone($e164, $e164, PhoneOrigin::TEXT, null);
    }

    public function testTier0PremiseIsRealtor(): void
    {
        $listing = $this->listing('hezky byt', new SellerMeta(hasPremise: true, totalListingCount: 1, name: 'RK'));

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testTier0HighSellerCountIsRealtor(): void
    {
        $listing = $this->listing('hezky byt', new SellerMeta(hasPremise: false, totalListingCount: 9, name: 'Jan'));

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testTier0KnownRealtorNumberIsRealtor(): void
    {
        $this->registry->setVerdict('+420777123456', Classification::REALTOR, \App\Domain\Confidence::HIGH);
        $listing = $this->listing('hezky byt', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testTier1OwnerSelfIdPhraseIsOwner(): void
    {
        // Real accented Czech — normalise() must strip diacritics for the match to land.
        $listing = $this->listing('Prodej přímo od majitele, RK nevolat', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::OWNER, $verdict->classification);
        self::assertSame(\App\Domain\Confidence::HIGH, $verdict->confidence);
    }

    public function testTier1SingleListingSellerIsOwner(): void
    {
        $listing = $this->listing('hezky byt', new SellerMeta(hasPremise: false, totalListingCount: 1, name: 'Jan'));

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::OWNER, $verdict->classification);
    }

    public function testTier2FrequentNumberIsRealtor(): void
    {
        foreach (['sreality:10', 'sreality:11', 'sreality:12'] as $listingId) {
            $this->registry->recordEvidence('+420777123456', $listingId, Source::SREALITY, null);
        }
        $listing = $this->listing('hezky byt', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
        self::assertSame(Classification::REALTOR, $this->registry->getVerdict('+420777123456'));
    }

    public function testTier2CrossSiteNumberIsRealtor(): void
    {
        $this->registry->recordEvidence('+420777123456', 'sreality:10', Source::SREALITY, null);
        $this->registry->recordEvidence('+420777123456', 'bezrealitky:20', Source::BEZREALITKY, null);
        $listing = $this->listing('hezky byt', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testTier2RealtorLanguageIsRealtor(): void
    {
        $listing = $this->listing('Naše realitní kancelář nabízí, provize v ceně', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testUnclassifiedListingIsUnknown(): void
    {
        $listing = $this->listing('Hezky slunny byt v klidne lokalite', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::UNKNOWN, $verdict->classification);
    }

    public function testOwnerSelfIdBeatsRealtorLanguage(): void
    {
        // Tier 1 runs before Tier 2: owner self-ID wins even if realtor words also appear.
        $listing = $this->listing('Provize žádná, prodej přímo od majitele', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::OWNER, $verdict->classification);
    }

    public function testTier0BeatsTier1WhenSellerIsAgencyButTextLooksLikeOwner(): void
    {
        // Tier 0 runs before Tier 1: an agency seller stays REALTOR even with owner self-ID text.
        $listing = $this->listing(
            'Přímo od majitele, RK nevolat',
            new SellerMeta(hasPremise: true, totalListingCount: 1, name: 'RK'),
        );

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testSellerWithTwoListingsFallsThroughToUnknown(): void
    {
        // Tier 0 needs > 2, Tier 1 needs === 1 — exactly 2 falls through (intentional).
        $listing = $this->listing(
            'Hezky slunny byt',
            new SellerMeta(hasPremise: false, totalListingCount: 2, name: 'Jan'),
        );

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::UNKNOWN, $verdict->classification);
    }

    public function testVerdictCarriesReasons(): void
    {
        $listing = $this->listing('hezky byt', new SellerMeta(hasPremise: true, totalListingCount: 1, name: 'RK'));

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertNotEmpty($verdict->reasons);
    }
}
