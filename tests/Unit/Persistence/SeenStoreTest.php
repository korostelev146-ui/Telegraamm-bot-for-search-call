<?php

declare(strict_types=1);

namespace App\Tests\Unit\Persistence;

use App\Domain\Source;
use App\Persistence\Database;
use App\Persistence\SeenStore;
use PHPUnit\Framework\TestCase;

final class SeenStoreTest extends TestCase
{
    private function store(): SeenStore
    {
        $database = new Database(':memory:');
        $database->migrate();

        return new SeenStore($database);
    }

    public function testUnknownListingIsNotSeen(): void
    {
        self::assertFalse($this->store()->isSeen('sreality:1'));
    }

    public function testMarkedListingIsSeen(): void
    {
        $store = $this->store();
        $store->markSeen('sreality:1', Source::SREALITY);

        self::assertTrue($store->isSeen('sreality:1'));
    }

    public function testMarkSeenIsIdempotent(): void
    {
        $store = $this->store();
        $store->markSeen('sreality:1', Source::SREALITY);
        $store->markSeen('sreality:1', Source::SREALITY); // must not throw

        self::assertSame(1, $store->count());
    }

    public function testCountReflectsStoredListings(): void
    {
        $store = $this->store();
        self::assertSame(0, $store->count());

        $store->markSeen('sreality:1', Source::SREALITY);
        $store->markSeen('bezrealitky:2', Source::BEZREALITKY);

        self::assertSame(2, $store->count());
    }
}
