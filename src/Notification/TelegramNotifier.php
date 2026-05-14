<?php

declare(strict_types=1);

namespace App\Notification;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends a single message to the operator's Telegram chat. Transport only.
 */
final class TelegramNotifier implements Notifier
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $telegramToken,
        private readonly string $telegramChatId,
    ) {
    }

    public function send(string $text): void
    {
        $response = $this->httpClient->request('POST', self::API_BASE . $this->telegramToken . '/sendMessage', [
            'body' => [
                'chat_id' => $this->telegramChatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 300) {
            throw new \RuntimeException(sprintf('Telegram API returned HTTP %d', $statusCode));
        }
    }
}
