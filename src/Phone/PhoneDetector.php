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
     * Czech spelling of digits 0-9, including the most common variants and
     * diacritic forms an owner would actually type when obfuscating a number.
     * The keys are matched against the lowercased description, so all entries
     * are lowercase.
     */
    private const DIGIT_WORDS = [
        'nula' => 0,
        'jedna' => 1,
        'jeden' => 1,
        'jedno' => 1,
        'jednu' => 1,
        'dva' => 2,
        'dve' => 2,
        'dvě' => 2,
        'tri' => 3,
        'tři' => 3,
        'ctyri' => 4,
        'čtyři' => 4,
        'pet' => 5,
        'pět' => 5,
        'sest' => 6,
        'šest' => 6,
        'sedm' => 7,
        'osm' => 8,
        'devet' => 9,
        'devět' => 9,
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

        // Strip keycap-emoji modifiers (U+FE0F variation selector + U+20E3
        // combining enclosing keycap) so a digit run written as
        // "7️⃣7️⃣7️⃣ 1️⃣2️⃣3️⃣ 4️⃣5️⃣6️⃣" collapses back to plain "777 123 456"
        // before the scanners run. Bezrealitky owners use this trick to dodge
        // naive text scrapers; the regex scanner picks the normalised digits
        // up exactly as it would a regular number.
        $text = str_replace(["\u{FE0F}", "\u{20E3}"], '', $listing->rawText);

        foreach ($this->scanText($text) as $detected) {
            // Structured numbers win — keep the already-stored one if present.
            $byE164[$detected->e164] ??= $detected;
        }

        foreach ($this->scanWordDigits($text) as $detected) {
            // Digit-runs and structured numbers both win over word-digit runs.
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

    /**
     * Scans for nine consecutive Czech digit-words (e.g. `ctyri pet osm sest
     * sedm devet jedna dva tri`) and reconstructs the national number. A
     * non-digit word between two digit-words breaks the run — we never glue
     * across noise — and the first reconstructed digit must be 2-9, matching
     * the regex scanner's `[2-9]` lead requirement for Czech phones.
     *
     * @return list<DetectedPhone>
     */
    private function scanWordDigits(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        // Match letter-runs OR single digit characters as separate tokens. A
        // bare digit (e.g. "7" between word-digits) thus becomes its own token,
        // is not a key in DIGIT_WORDS, and breaks the nine-in-a-row window —
        // honouring the "no gluing across noise" promise in the docblock.
        if (preg_match_all('/[\p{L}]+|\d/u', $lower, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $tokens = $matches[0];
        $tokenCount = count($tokens);
        $found = [];

        $i = 0;
        while ($i + 9 <= $tokenCount) {
            $digits = '';
            $allDigitWords = true;
            for ($j = 0; $j < 9; $j++) {
                $word = $tokens[$i + $j][0];
                if (! isset(self::DIGIT_WORDS[$word])) {
                    $allDigitWords = false;
                    break;
                }
                $digits .= self::DIGIT_WORDS[$word];
            }

            if ($allDigitWords && strlen($digits) === 9 && $digits[0] >= '2') {
                $startOffset = $tokens[$i][1];
                $lastWord = $tokens[$i + 8][0];
                $endOffset = $tokens[$i + 8][1] + strlen($lastWord);
                $raw = substr($text, $startOffset, $endOffset - $startOffset);

                $found[] = new DetectedPhone(
                    e164: '+420' . $digits,
                    raw: $raw,
                    origin: PhoneOrigin::TEXT,
                    marker: $this->markerBefore($text, $startOffset),
                );

                $i += 9; // skip past the consumed run — no overlapping matches
                continue;
            }

            $i++;
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
