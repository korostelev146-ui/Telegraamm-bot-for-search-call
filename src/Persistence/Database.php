<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * Owns the single SQLite connection and the schema.
 *
 * $databasePath is either an absolute file path or ':memory:' (tests).
 */
final class Database
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $databasePath,
    ) {
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO('sqlite:' . $this->databasePath);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $this->pdo;
    }

    public function migrate(): void
    {
        $pdo = $this->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS seen_listings (
                listing_id    TEXT PRIMARY KEY,
                source        TEXT NOT NULL,
                first_seen_at TEXT NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS contacts (
                phone_e164    TEXT PRIMARY KEY,
                verdict       TEXT,
                confidence    TEXT,
                first_seen_at TEXT NOT NULL,
                updated_at    TEXT NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS contact_evidence (
                id         INTEGER PRIMARY KEY,
                phone_e164 TEXT NOT NULL,
                listing_id TEXT NOT NULL,
                source     TEXT NOT NULL,
                name       TEXT,
                seen_at    TEXT NOT NULL,
                UNIQUE (phone_e164, listing_id)
            )
            SQL);
    }
}
