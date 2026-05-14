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
    private function source(array $listings, bool $throwOnFetch = false): ListingSource
    {
        return new class ($listings, $throwOnFetch) implements ListingSource {
            /** @param list<Listing> $listings */
            public function __construct(private array $listings, private bool $throwOnFetch)
            {
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
                return $listing;
            }
        };
    }

    private function runner(ListingSource ...$sources): MonitorRunner
    {
        $database = new Database(':memory:');
        $database->migrate();
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
}
