<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class DetectedPhone
{
    public function __construct(
        public string $e164,
        public string $raw,
        public PhoneOrigin $origin,
        public ?string $marker,
    ) {
    }
}
