<?php

declare(strict_types=1);

namespace App\Phone;

use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;

/**
 * Extracts Czech phone numbers from a listing's structured fields and description text.
 *
 * A Czech phone number is 9 digits whose first digit is 2-9, optionally prefixed
 * with +420 / 00420 and optionally grouped in 3-3-3 with spaces or dashes.
 *
 * Note: a bare 9-digit group in text with no marker word and no nearby currency
 * (e.g. a property registry number or order ID) can be mis-detected as a phone.
 * This is an accepted tradeoff — the advertiser classifier and the operator are
 * the safety net for PhoneOrigin::TEXT numbers.
 */
final class PhoneDetector
{
    private const PHONE_PATTERN =
        '/(?<!\d)(?:\+?420[\s\-]?|00420[\s\-]?)?([2-9]\d{2})[\s\-]?(\d{3})[\s\-]?(\d{3})(?!\d)/u';

    /**
     * Marker words that, when they appear shortly before a number, mark it as a contact.
     */
    private const MARKERS = [
        'tel', 'telefon', 'mobil', 'mob', 'volejte', 'volat', 'zavolejte',
        'kontakt', 'cislo', 'na me', 'na mne',
    ];

    /**
     * @return list<DetectedPhone>
     */
    public function detect(Listing $listing): array
    {
        $byE164 = [];

        foreach ($listing->structuredPhones as $structured) {
            $byE164[$structured] = new DetectedPhone(
                e164: $structured,
                raw: $structured,
                origin: PhoneOrigin::STRUCTURED,
                marker: null,
            );
        }

        foreach ($this->scanText($listing->rawText) as $detected) {
            // Structured numbers win — keep the already-stored one if present.
            $byE164[$detected->e164] ??= $detected;
        }

        return array_values($byE164);
    }

    /**
     * @return list<DetectedPhone>
     */
    private function scanText(string $text): array
    {
        if (preg_match_all(self::PHONE_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $found = [];
        foreach ($matches[0] as $index => [$rawMatch, $offset]) {
            $trailing = substr($text, $offset + strlen($rawMatch), 24);
            if (preg_match('/^[\s\-]*(?:\d{3}[\s\-]*)*(?:Kč|Kc|CZK|,-)/ui', $trailing) === 1) {
                continue; // a price, not a phone number
            }

            $e164 = '+420' . $matches[1][$index][0] . $matches[2][$index][0] . $matches[3][$index][0];

            $found[] = new DetectedPhone(
                e164: $e164,
                raw: trim($rawMatch),
                origin: PhoneOrigin::TEXT,
                marker: $this->markerBefore($text, $offset),
            );
        }

        return $found;
    }

    private function markerBefore(string $text, int $offset): ?string
    {
        $window = mb_strtolower(substr($text, max(0, $offset - 25), min($offset, 25)), 'UTF-8');

        foreach (self::MARKERS as $marker) {
            if (str_contains($window, $marker)) {
                return $marker;
            }
        }

        return null;
    }
}
