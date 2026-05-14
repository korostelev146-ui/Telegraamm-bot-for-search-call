<?php

declare(strict_types=1);

namespace App\Classification;

use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\Verdict;

interface AdvertiserClassifier
{
    /**
     * @param list<DetectedPhone> $phones phones already extracted from the listing
     */
    public function classify(Listing $listing, array $phones): Verdict;
}
