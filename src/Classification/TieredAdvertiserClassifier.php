<?php

declare(strict_types=1);

namespace App\Classification;

use App\Domain\Classification;
use App\Domain\Confidence;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\Verdict;
use App\Persistence\ContactRegistry;

/**
 * Owner-vs-realtor classification as a deterministic funnel:
 *  - Tier 0 excludes obvious realtors
 *  - Tier 1 includes obvious owners
 *  - Tier 2 cross-references the ContactRegistry
 * The first tier to produce a verdict wins. Anything left over is UNKNOWN.
 */
final class TieredAdvertiserClassifier implements AdvertiserClassifier
{
    /**
     * Phrases owners use to identify themselves (matched case-insensitively, accent-stripped).
     */
    private const OWNER_PHRASES = [
        'rk nevolat', 'bez rk', 'bez realitky', 'bez provize',
        'primo od majitele', 'soukromy prodej', 'makleri nevolejte',
    ];

    /**
     * Phrases that indicate a realtor wrote the listing.
     */
    private const REALTOR_PHRASES = ['makler', 'realitni kancelar', 'provize', 'zprostredkovani', 'nase kancelar'];

    private const FREQUENT_LISTING_THRESHOLD = 3;

    private const CROSS_SITE_THRESHOLD = 2;

    private const HIGH_SELLER_COUNT_THRESHOLD = 2;

    /**
     * Czech diacritics → ASCII base letters (locale-independent transliteration).
     */
    private const ACCENT_MAP = [
        'á' => 'a',
        'č' => 'c',
        'ď' => 'd',
        'é' => 'e',
        'ě' => 'e',
        'í' => 'i',
        'ň' => 'n',
        'ó' => 'o',
        'ř' => 'r',
        'š' => 's',
        'ť' => 't',
        'ú' => 'u',
        'ů' => 'u',
        'ý' => 'y',
        'ž' => 'z',
    ];

    public function __construct(
        private readonly ContactRegistry $registry,
    ) {
    }

    public function classify(Listing $listing, array $phones): Verdict
    {
        $haystack = $this->normalise($listing->rawText);

        return $this->tier0($listing, $phones)
            ?? $this->tier1($listing, $haystack)
            ?? $this->tier2($listing, $phones, $haystack)
            ?? new Verdict(Classification::UNKNOWN, Confidence::LOW, ['no decisive signal']);
    }

    /**
     * @param list<DetectedPhone> $phones
     */
    private function tier0(Listing $listing, array $phones): ?Verdict
    {
        $meta = $listing->sellerMeta;
        if ($meta !== null && $meta->hasPremise) {
            return new Verdict(Classification::REALTOR, Confidence::HIGH, ['seller has a premise (agency)']);
        }

        if ($meta !== null && $meta->totalListingCount !== null
            && $meta->totalListingCount > self::HIGH_SELLER_COUNT_THRESHOLD) {
            return new Verdict(
                Classification::REALTOR,
                Confidence::HIGH,
                [sprintf('seller has %d listings', $meta->totalListingCount)],
            );
        }

        foreach ($phones as $phone) {
            if ($this->registry->getVerdict($phone->e164) === Classification::REALTOR) {
                return new Verdict(
                    Classification::REALTOR,
                    Confidence::HIGH,
                    [sprintf('%s is a known realtor number', $phone->e164)],
                );
            }
        }

        return null;
    }

    private function tier1(Listing $listing, string $haystack): ?Verdict
    {
        foreach (self::OWNER_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return new Verdict(
                    Classification::OWNER,
                    Confidence::HIGH,
                    [sprintf('owner self-identification: "%s"', $phrase)],
                );
            }
        }

        $meta = $listing->sellerMeta;
        if ($meta !== null && ! $meta->hasPremise && $meta->totalListingCount === 1) {
            return new Verdict(
                Classification::OWNER,
                Confidence::HIGH,
                ['seller has exactly one listing and no premise'],
            );
        }

        return null;
    }

    /**
     * @param list<DetectedPhone> $phones
     */
    private function tier2(Listing $listing, array $phones, string $haystack): ?Verdict
    {
        foreach ($phones as $phone) {
            $listingCount = $this->registry->listingCount($phone->e164);
            $siteCount = $this->registry->siteCount($phone->e164);

            if ($listingCount >= self::FREQUENT_LISTING_THRESHOLD || $siteCount >= self::CROSS_SITE_THRESHOLD) {
                $this->registry->setVerdict($phone->e164, Classification::REALTOR, Confidence::MEDIUM);

                return new Verdict(
                    Classification::REALTOR,
                    Confidence::MEDIUM,
                    [sprintf('%s seen on %d listings across %d sites', $phone->e164, $listingCount, $siteCount)],
                );
            }
        }

        foreach (self::REALTOR_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return new Verdict(
                    Classification::REALTOR,
                    Confidence::MEDIUM,
                    [sprintf('realtor language: "%s"', $phrase)],
                );
            }
        }

        return null;
    }

    private function normalise(string $text): string
    {
        // Deterministic, locale-independent: lowercase, then map Czech diacritics
        // to ASCII so the ASCII phrase constants match real accented listing text.
        return strtr(mb_strtolower($text), self::ACCENT_MAP);
    }
}
