<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Seller metadata that only Sreality exposes. Bezrealitky listings carry null.
 */
final readonly class SellerMeta
{
    public function __construct(
        public bool $hasPremise,
        public ?int $totalListingCount,
        public ?string $name,
    ) {
    }
}
