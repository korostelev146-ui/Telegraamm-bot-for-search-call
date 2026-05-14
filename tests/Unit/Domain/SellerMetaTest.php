<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\SellerMeta;
use PHPUnit\Framework\TestCase;

final class SellerMetaTest extends TestCase
{
    public function testCarriesEmailWhenProvided(): void
    {
        $meta = new SellerMeta(
            hasPremise: false,
            totalListingCount: 1,
            name: 'Jan Novak',
            email: 'jan@example.cz',
        );

        self::assertSame('jan@example.cz', $meta->email);
    }

    public function testEmailDefaultsToNull(): void
    {
        $meta = new SellerMeta(hasPremise: true, totalListingCount: 9, name: 'RK');

        self::assertNull($meta->email);
    }
}
