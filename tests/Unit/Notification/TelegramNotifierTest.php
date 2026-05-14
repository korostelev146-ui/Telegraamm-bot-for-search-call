<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\TelegramNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class TelegramNotifierTest extends TestCase
{
    public function testSendPostsToBotApiWithChatIdAndText(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse('{"ok":true}');
        });

        $notifier = new TelegramNotifier($http, 'secret-token', '123456');
        $notifier->send('hello world');

        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertStringContainsString('/botsecret-token/sendMessage', $captured['url']);
        self::assertStringContainsString('123456', (string) $captured['body']);
        self::assertStringContainsString('hello+world', (string) $captured['body']);
        self::assertStringContainsString('parse_mode=HTML', (string) $captured['body']);
    }

    public function testSendThrowsOnApiError(): void
    {
        $http = new MockHttpClient(new MockResponse('{"ok":false}', ['http_code' => 400]));
        $notifier = new TelegramNotifier($http, 'secret-token', '123456');

        $this->expectException(\RuntimeException::class);
        $notifier->send('hello');
    }

    public function testSendThrowsOnTransportFailure(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $notifier = new TelegramNotifier($http, 'secret-token', '123456');

        $this->expectException(TransportExceptionInterface::class);
        $notifier->send('hello');
    }
}
