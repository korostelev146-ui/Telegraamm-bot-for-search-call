<?php

declare(strict_types=1);

namespace App\Source;

use App\Domain\Listing;

interface ListingSource
{
    /**
     * Cheap call: returns shallow listings (rawText/sellerMeta/structuredPhones may be empty).
     * Implementations MUST return listings ordered newest-first; the first-run cap relies on this ordering.
     *
     * @return list<Listing>
     */
    public function fetchRecentListings(): array;

    /**
     * Fills rawText, sellerMeta and structuredPhones. May perform an HTTP call.
     * For sources whose list call already returns everything, this returns the listing unchanged.
     */
    public function hydrate(Listing $listing): Listing;
}
