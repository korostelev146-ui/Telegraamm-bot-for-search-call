<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Classification\TieredAdvertiserClassifier;
use App\Command\MonitorCommand;
use App\Monitor\MonitorRunner;
use App\Notification\MessageFormatter;
use App\Notification\Notifier;
use App\Persistence\ContactRegistry;
use App\Persistence\Database;
use App\Persistence\SeenStore;
use App\Phone\PhoneDetector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class MonitorCommandTest extends TestCase
{
    public function testCommandMigratesSchemaAndRunsSuccessfully(): void
    {
        $database = new Database(':memory:');
        $registry = new ContactRegistry($database);

        $notifier = new class implements Notifier {
            public function send(string $text): void
            {
            }
        };

        $runner = new MonitorRunner(
            sources: [], // no sources -> run() is a no-op
            seenStore: new SeenStore($database),
            contactRegistry: $registry,
            phoneDetector: new PhoneDetector(),
            classifier: new TieredAdvertiserClassifier($registry),
            formatter: new MessageFormatter(),
            notifier: $notifier,
            logger: new NullLogger(),
            batchLimit: 15,
        );

        $command = new MonitorCommand($database, $runner);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        // migrate() ran, so the schema exists:
        $tables = $database->pdo()
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")
            ->fetchColumn();
        self::assertSame(3, (int) $tables);
    }
}
