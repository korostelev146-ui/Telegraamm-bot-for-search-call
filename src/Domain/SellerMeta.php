<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Seller metadata. Sreality exposes the richest data (premise flag, listing
 * count, name, e-mail); private Sreality sellers expose only name + e-mail.
 * Bezrealitky listings carry null.
 */
final readonly class SellerMeta
{
    public function __construct(
        public bool $hasPremise,
        public ?int $totalListingCount,
        public ?string $name,
        public ?string $email = null,
    ) {
    }
}
