<?php

declare(strict_types=1);

namespace App\Source;

use App\Domain\Listing;

interface ListingSource
{
    /**
     * Cheap call: yields shallow listings (rawText/sellerMeta/structuredPhones may be empty).
     * Implementations MUST yield listings ordered newest-first; pagination is lazy so a
     * consumer that breaks out of the foreach early skips the unfetched pages.
     *
     * @return iterable<Listing>
     */
    public function fetchRecentListings(): iterable;

    /**
     * Fills rawText, sellerMeta and structuredPhones. May perform an HTTP call.
     * For sources whose list call already returns everything, this returns the listing unchanged.
     */
    public function hydrate(Listing $listing): Listing;
}
