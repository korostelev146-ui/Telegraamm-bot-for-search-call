<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Domain\Source;

/**
 * The set of listing IDs the bot has already processed. Deduplication lives here.
 */
final class SeenStore
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function isSeen(string $listingId): bool
    {
        $statement = $this->database->pdo()
            ->prepare('SELECT 1 FROM seen_listings WHERE listing_id = :id');
        $statement->execute([
            'id' => $listingId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function markSeen(string $listingId, Source $source): void
    {
        $statement = $this->database->pdo()
            ->prepare(
                'INSERT OR IGNORE INTO seen_listings (listing_id, source, first_seen_at)
             VALUES (:id, :source, :now)',
            );
        $statement->execute([
            'id' => $listingId,
            'source' => $source->value,
            'now' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function count(): int
    {
        $statement = $this->database->pdo()
            ->query('SELECT COUNT(*) FROM seen_listings');

        if ($statement === false) {
            return 0;
        }

        return (int) $statement->fetchColumn();
    }
}
