<?php

declare(strict_types=1);

namespace App\Notification;

use App\Domain\Classification;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\Source;
use App\Domain\Verdict;

/**
 * Renders a classified listing as a Telegram HTML message.
 */
final class MessageFormatter
{
    private const SOURCE_LABELS = [
        Source::SREALITY->value => 'Sreality',
        Source::BEZREALITKY->value => 'Bezrealitky',
    ];

    private const BADGES = [
        Classification::OWNER->value => '👤 majitel',
        Classification::UNKNOWN->value => '❓ nejasné',
        Classification::REALTOR->value => '🏢 realitka',
    ];

    /**
     * @param list<DetectedPhone> $phones
     */
    public function format(Listing $listing, Verdict $verdict, array $phones): string
    {
        $lines = [];
        $lines[] = '🏠 <b>' . $this->escape($listing->title) . '</b>';
        $lines[] = '📍 ' . $this->escape($listing->location);

        if ($listing->price !== null) {
            $lines[] = '💰 ' . number_format($listing->price, 0, '', ' ') . ' Kč';
        }

        foreach ($phones as $phone) {
            $lines[] = '📞 ' . $this->escape($phone->e164);
        }

        $email = $listing->sellerMeta?->email;
        if ($email !== null && $email !== '') {
            $lines[] = '📧 ' . $this->escape($email);
        }

        $badge = self::BADGES[$verdict->classification->value];
        $reason = $verdict->reasons[0] ?? '';
        $lines[] = $badge . ($reason !== '' ? ' — ' . $this->escape($reason) : '');

        $lines[] = '🔗 ' . $this->escape($listing->url);

        $snippet = $this->snippet($listing->rawText);
        if ($snippet !== '') {
            $lines[] = '📝 ' . $this->escape($snippet);
        }

        $lines[] = '🌐 ' . self::SOURCE_LABELS[$listing->source->value];

        return implode("\n", $lines);
    }

    private function snippet(string $text): string
    {
        $normalised = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($normalised === '') {
            return '';
        }

        return mb_strlen($normalised) > 200 ? mb_substr($normalised, 0, 200) . '…' : $normalised;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
