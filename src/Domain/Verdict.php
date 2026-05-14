<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Verdict
{
    /**
     * @param list<string> $reasons human-readable signals that produced this verdict
     */
    public function __construct(
        public Classification $classification,
        public Confidence $confidence,
        public array $reasons,
    ) {
    }
}
