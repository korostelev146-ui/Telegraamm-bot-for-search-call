<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Domain\Classification;
use App\Domain\Confidence;
use App\Domain\Source;

/**
 * Accumulates evidence about phone numbers across listings and sites, and stores
 * the verdict for each number. The verdict is on the contact, not the listing.
 */
final class ContactRegistry
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function recordEvidence(string $phoneE164, string $listingId, Source $source, ?string $name): void
    {
        $pdo = $this->database->pdo();
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $contact = $pdo->prepare(
            'INSERT INTO contacts (phone_e164, verdict, confidence, first_seen_at, updated_at)
             VALUES (:phone, NULL, NULL, :now, :now)
             ON CONFLICT (phone_e164) DO UPDATE SET updated_at = :now',
        );
        $contact->execute([
            'phone' => $phoneE164,
            'now' => $now,
        ]);

        $evidence = $pdo->prepare(
            'INSERT OR IGNORE INTO contact_evidence (phone_e164, listing_id, source, name, seen_at)
             VALUES (:phone, :listing, :source, :name, :now)',
        );
        $evidence->execute([
            'phone' => $phoneE164,
            'listing' => $listingId,
            'source' => $source->value,
            'name' => $name,
            'now' => $now,
        ]);
    }

    public function listingCount(string $phoneE164): int
    {
        $statement = $this->database->pdo()
            ->prepare('SELECT COUNT(DISTINCT listing_id) FROM contact_evidence WHERE phone_e164 = :phone');
        $statement->execute([
            'phone' => $phoneE164,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function siteCount(string $phoneE164): int
    {
        $statement = $this->database->pdo()
            ->prepare('SELECT COUNT(DISTINCT source) FROM contact_evidence WHERE phone_e164 = :phone');
        $statement->execute([
            'phone' => $phoneE164,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function getVerdict(string $phoneE164): ?Classification
    {
        $statement = $this->database->pdo()
            ->prepare('SELECT verdict FROM contacts WHERE phone_e164 = :phone');
        $statement->execute([
            'phone' => $phoneE164,
        ]);

        $value = $statement->fetchColumn();
        if (! is_string($value)) {
            return null;
        }

        return Classification::from($value);
    }

    public function setVerdict(string $phoneE164, Classification $classification, Confidence $confidence): void
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $statement = $this->database->pdo()
            ->prepare(
                'INSERT INTO contacts (phone_e164, verdict, confidence, first_seen_at, updated_at)
             VALUES (:phone, :verdict, :confidence, :now, :now)
             ON CONFLICT (phone_e164)
             DO UPDATE SET verdict = :verdict, confidence = :confidence, updated_at = :now',
            );
        $statement->execute([
            'phone' => $phoneE164,
            'verdict' => $classification->value,
            'confidence' => $confidence->value,
            'now' => $now,
        ]);
    }
}
