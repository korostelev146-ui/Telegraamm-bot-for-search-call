<?php

declare(strict_types=1);

namespace App\Tests\Unit\Persistence;

use App\Domain\Classification;
use App\Domain\Confidence;
use App\Domain\Source;
use App\Persistence\ContactRegistry;
use App\Persistence\Database;
use PHPUnit\Framework\TestCase;

final class ContactRegistryTest extends TestCase
{
    private function registry(): ContactRegistry
    {
        $database = new Database(':memory:');
        $database->migrate();

        return new ContactRegistry($database);
    }

    public function testRecordEvidenceCountsDistinctListings(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, 'Jan');
        $registry->recordEvidence('+420777123456', 'sreality:2', Source::SREALITY, 'Jan');

        self::assertSame(2, $registry->listingCount('+420777123456'));
    }

    public function testRecordEvidenceIsIdempotentPerListing(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, 'Jan');
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, 'Jan');

        self::assertSame(1, $registry->listingCount('+420777123456'));
    }

    public function testSiteCountCountsDistinctSources(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, null);
        $registry->recordEvidence('+420777123456', 'sreality:2', Source::SREALITY, null);
        $registry->recordEvidence('+420777123456', 'bezrealitky:9', Source::BEZREALITKY, null);

        self::assertSame(2, $registry->siteCount('+420777123456'));
    }

    public function testVerdictIsNullUntilSet(): void
    {
        self::assertNull($this->registry()->getVerdict('+420777123456'));
    }

    public function testSetVerdictPersistsClassification(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, null);
        $registry->setVerdict('+420777123456', Classification::REALTOR, Confidence::HIGH);

        self::assertSame(Classification::REALTOR, $registry->getVerdict('+420777123456'));
    }

    public function testSetVerdictWorksEvenWithoutPriorEvidence(): void
    {
        $registry = $this->registry();
        $registry->setVerdict('+420777123456', Classification::OWNER, Confidence::MEDIUM);

        self::assertSame(Classification::OWNER, $registry->getVerdict('+420777123456'));
    }

    public function testRecordEvidenceDoesNotClobberExistingVerdict(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, null);
        $registry->setVerdict('+420777123456', Classification::REALTOR, Confidence::HIGH);
        $registry->recordEvidence('+420777123456', 'sreality:2', Source::SREALITY, null);

        self::assertSame(Classification::REALTOR, $registry->getVerdict('+420777123456'));
    }

    public function testUnknownPhoneHasZeroCounts(): void
    {
        $registry = $this->registry();

        self::assertSame(0, $registry->listingCount('+420000000000'));
        self::assertSame(0, $registry->siteCount('+420000000000'));
    }
}
