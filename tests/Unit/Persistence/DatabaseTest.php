<?php

declare(strict_types=1);

namespace App\Tests\Unit\Persistence;

use App\Persistence\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testMigrateCreatesAllTables(): void
    {
        $database = new Database(':memory:');
        $database->migrate();

        $tables = $database->pdo()
            ->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['contact_evidence', 'contacts', 'seen_listings'], $tables);
    }

    public function testMigrateIsIdempotent(): void
    {
        $database = new Database(':memory:');
        $database->migrate();
        $database->migrate(); // must not throw

        $count = $database->pdo()
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")
            ->fetchColumn();

        self::assertSame(3, (int) $count);
    }

    public function testPdoReturnsSameConnection(): void
    {
        $database = new Database(':memory:');

        self::assertSame($database->pdo(), $database->pdo());
    }
}
