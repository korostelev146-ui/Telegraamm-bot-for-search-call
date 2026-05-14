<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Listing
{
    /**
     * @param list<string> $structuredPhones E.164 numbers from a structured API field
     */
    public function __construct(
        public string $id,
        public Source $source,
        public string $title,
        public ?int $price,
        public DealType $dealType,
        public string $location,
        public string $url,
        public string $rawText,
        public ?SellerMeta $sellerMeta,
        public array $structuredPhones,
    ) {
    }

    /**
     * @param list<string> $structuredPhones
     */
    public function withDetails(string $rawText, ?SellerMeta $sellerMeta, array $structuredPhones): self
    {
        return new self(
            $this->id,
            $this->source,
            $this->title,
            $this->price,
            $this->dealType,
            $this->location,
            $this->url,
            $rawText,
            $sellerMeta,
            $structuredPhones,
        );
    }
}
