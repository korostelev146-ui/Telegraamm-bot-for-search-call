<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitor;

use App\Classification\TieredAdvertiserClassifier;
use App\Domain\DealType;
use App\Domain\Listing;
use App\Domain\SellerMeta;
use App\Domain\Source;
use App\Monitor\MonitorRunner;
use App\Notification\MessageFormatter;
use App\Notification\Notifier;
use App\Persistence\ContactRegistry;
use App\Persistence\Database;
use App\Persistence\SeenStore;
use App\Phone\PhoneDetector;
use App\Source\ListingSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MonitorRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $sentMessages = [];

    private function listing(string $id, string $rawText, ?SellerMeta $sellerMeta = null): Listing
    {
        return new Listing(
            id: $id,
            source: Source::SREALITY,
            title: 't',
            price: 1,
            dealType: DealType::SALE,
            location: 'Praha',
            url: 'https://example.test/' . $id,
            rawText: $rawText,
            sellerMeta: $sellerMeta,
            structuredPhones: [],
        );
    }

    /**
     * @param list<Listing> $listings
     */
    private function source(
        array $listings,
        bool $throwOnFetch = false,
        bool $throwOnHydrate = false,
    ): ListingSource {
        return new class ($listings, $throwOnFetch, $throwOnHydrate) implements ListingSource {
            /** @param list<Listing> $listings */
            public function __construct(
                private array $listings,
                private bool $throwOnFetch,
                private bool $throwOnHydrate,
            ) {
            }

            public function fetchRecentListings(): array
            {
                if ($this->throwOnFetch) {
                    throw new \RuntimeException('source down');
                }

                return $this->listings;
            }

            public function hydrate(Listing $listing): Listing
            {
                if ($this->throwOnHydrate) {
                    throw new \RuntimeException('hydrate failed');
                }

                return $listing;
            }
        };
    }

    private function makeDatabase(): Database
    {
        $database = new Database(':memory:');
        $database->migrate();

        return $database;
    }

    private function runner(ListingSource ...$sources): MonitorRunner
    {
        return $this->runnerOnDatabase($this->makeDatabase(), ...$sources);
    }

    private function runnerOnDatabase(Database $database, ListingSource ...$sources): MonitorRunner
    {
        $registry = new ContactRegistry($database);

        $notifier = new class ($this->sentMessages) implements Notifier {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function send(string $text): void
            {
                $this->sink[] = $text;
            }
        };

        return new MonitorRunner(
            sources: $sources,
            seenStore: new SeenStore($database),
            contactRegistry: $registry,
            phoneDetector: new PhoneDetector(),
            classifier: new TieredAdvertiserClassifier($registry),
            formatter: new MessageFormatter(),
            notifier: $notifier,
            logger: new NullLogger(),
            firstRunLimit: 2,
        );
    }

    private function runnerWithThrowingNotifier(Database $database, ListingSource ...$sources): MonitorRunner
    {
        $registry = new ContactRegistry($database);

        $notifier = new class implements Notifier {
            public function send(string $text): void
            {
                throw new \RuntimeException('Telegram send failed');
            }
        };

        return new MonitorRunner(
            sources: $sources,
            seenStore: new SeenStore($database),
            contactRegistry: $registry,
            phoneDetector: new PhoneDetector(),
            classifier: new TieredAdvertiserClassifier($registry),
            formatter: new MessageFormatter(),
            notifier: $notifier,
            logger: new NullLogger(),
            firstRunLimit: 2,
        );
    }

    public function testSendsOwnerListingAndMarksItSeen(): void
    {
        $source = $this->source([
            $this->listing('sreality:1', 'Volejte 777 123 456, primo od majitele'),
        ]);
        $runner = $this->runner($source);

        $runner->run();

        self::assertCount(1, $this->sentMessages);
        // Second run must not resend.
        $runner->run();
        self::assertCount(1, $this->sentMessages);
    }

    public function testSkipsListingWithoutPhone(): void
    {
        $runner = $this->runner($this->source([$this->listing('sreality:1', 'Zadny kontakt')]));

        $runner->run();

        self::assertCount(0, $this->sentMessages);
    }

    public function testSendsOwnerListingWithoutPhoneButWithEmail(): void
    {
        // Owner self-identification in the text and no phone, but a contact
        // e-mail (e.g. a Sreality private seller) — still an actionable lead.
        $runner = $this->runner($this->source([
            $this->listing(
                'sreality:1',
                'Prodam byt primo od majitele, bez provize',
                new SellerMeta(
                    hasPremise: false,
                    totalListingCount: null,
                    name: 'Jan',
                    email: 'jan@example.cz',
                ),
            ),
        ]));

        $runner->run();

        self::assertCount(1, $this->sentMessages);
    }

    public function testSkipsOwnerListingWithoutPhoneOrEmail(): void
    {
        // Owner self-identification in the text, but no phone and no e-mail
        // anywhere (e.g. a link-only Bezrealitky listing) — nothing to act on.
        $runner = $this->runner($this->source([
            $this->listing('sreality:1', 'Prodam byt primo od majitele, bez provize'),
        ]));

        $runner->run();

        self::assertCount(0, $this->sentMessages);
    }

    public function testSkipsRealtorListing(): void
    {
        $runner = $this->runner($this->source([
            $this->listing(
                'sreality:1',
                'Volejte 777 123 456',
                new SellerMeta(hasPremise: true, totalListingCount: 5, name: 'RK'),
            ),
        ]));

        $runner->run();

        self::assertCount(0, $this->sentMessages);
    }

    public function testFirstRunIsCappedByLimit(): void
    {
        $listings = [
            $this->listing('sreality:1', 'Volejte 777 123 401, primo od majitele'),
            $this->listing('sreality:2', 'Volejte 777 123 402, primo od majitele'),
            $this->listing('sreality:3', 'Volejte 777 123 403, primo od majitele'),
            $this->listing('sreality:4', 'Volejte 777 123 404, primo od majitele'),
        ];
        $runner = $this->runner($this->source($listings)); // firstRunLimit = 2

        $runner->run();

        self::assertCount(2, $this->sentMessages);
        // All four are marked seen, so a second run sends nothing.
        $runner->run();
        self::assertCount(2, $this->sentMessages);
    }

    public function testOneSourceFailingDoesNotStopOthers(): void
    {
        $broken = $this->source([], throwOnFetch: true);
        $working = $this->source([$this->listing('sreality:9', 'Volejte 777 123 456, primo od majitele')]);

        $runner = $this->runner($broken, $working);
        $runner->run();

        self::assertCount(1, $this->sentMessages);
    }

    public function testHydrateFailureLeavesListingUnseenAndRetriesNextRun(): void
    {
        $listing = $this->listing('sreality:hydrate-fail', 'Volejte 777 123 456, primo od majitele');
        $database = $this->makeDatabase();

        // First run: hydrate() throws — listing must NOT be marked seen.
        $throwingSource = $this->source([$listing], throwOnHydrate: true);
        $firstRunner = $this->runnerOnDatabase($database, $throwingSource);
        $firstRunner->run();

        self::assertCount(0, $this->sentMessages, 'Nothing sent when hydrate throws');

        // Second run: hydrate() succeeds — because the listing was not marked seen
        // it is picked up again and delivered now.
        $workingSource = $this->source([$listing]);
        $secondRunner = $this->runnerOnDatabase($database, $workingSource);
        $secondRunner->run();

        self::assertCount(1, $this->sentMessages, 'Listing retried and sent after hydrate succeeds');
    }

    public function testSendFailureLeavesListingUnseenAndRetriesNextRun(): void
    {
        $listing = $this->listing('sreality:send-fail', 'Volejte 777 123 456, primo od majitele');
        $database = $this->makeDatabase();

        // First run: Notifier::send() throws — listing must NOT be marked seen.
        $source = $this->source([$listing]);
        $firstRunner = $this->runnerWithThrowingNotifier($database, $source);
        $firstRunner->run();

        self::assertCount(0, $this->sentMessages, 'Nothing sent when Notifier::send() throws');

        // Second run: working notifier — because the listing was not marked seen
        // it is picked up again and delivered now.
        $secondRunner = $this->runnerOnDatabase($database, $source);
        $secondRunner->run();

        self::assertCount(1, $this->sentMessages, 'Listing retried and sent after notifier recovers');
    }

    public function testSendsUnknownListingWithEmailButNoPhone(): void
    {
        // Neutral text — classification falls through to UNKNOWN. No phone, but
        // a Sreality-style contact e-mail — must be sent under the unified gate.
        $runner = $this->runner($this->source([
            $this->listing(
                'sreality:1',
                'Pekny byt v centru.',
                new SellerMeta(
                    hasPremise: false,
                    totalListingCount: null,
                    name: 'Jan',
                    email: 'jan@example.cz',
                ),
            ),
        ]));

        $runner->run();

        self::assertCount(1, $this->sentMessages);
    }
}
