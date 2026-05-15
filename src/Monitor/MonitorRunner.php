<?php

declare(strict_types=1);

namespace App\Monitor;

use App\Classification\AdvertiserClassifier;
use App\Domain\Classification;
use App\Domain\Listing;
use App\Notification\MessageFormatter;
use App\Notification\Notifier;
use App\Persistence\ContactRegistry;
use App\Persistence\SeenStore;
use App\Phone\PhoneDetector;
use App\Source\ListingSource;
use Psr\Log\LoggerInterface;

/**
 * Executes one monitoring pass over every configured source.
 *
 * Failure isolation: a failing source is logged and skipped; a failing hydrate
 * or send is logged and the listing is left un-seen so it retries next run.
 */
final class MonitorRunner
{
    /**
     * @param iterable<ListingSource> $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly SeenStore $seenStore,
        private readonly ContactRegistry $contactRegistry,
        private readonly PhoneDetector $phoneDetector,
        private readonly AdvertiserClassifier $classifier,
        private readonly MessageFormatter $formatter,
        private readonly Notifier $notifier,
        private readonly LoggerInterface $logger,
        private readonly int $firstRunLimit,
    ) {
    }

    public function run(): void
    {
        $isFirstRun = $this->seenStore->count() === 0;

        foreach ($this->sources as $source) {
            $sentThisSource = 0;

            try {
                $listings = $source->fetchRecentListings();
            } catch (\Throwable $exception) {
                $this->logger->error('Source fetch failed', [
                    'source' => $source::class,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            foreach ($listings as $listing) {
                if ($this->seenStore->isSeen($listing->id)) {
                    continue;
                }

                $sent = $this->processListing($source, $listing, $isFirstRun, $sentThisSource);
                if ($sent) {
                    ++$sentThisSource;
                }
            }
        }
    }

    private function processListing(
        ListingSource $source,
        Listing $listing,
        bool $isFirstRun,
        int $sentThisSource,
    ): bool {
        try {
            $listing = $source->hydrate($listing);
        } catch (\Throwable $exception) {
            $this->logger->error('Hydrate failed', [
                'listing' => $listing->id,
                'error' => $exception->getMessage(),
            ]);

            return false; // leave un-seen so it retries next run
        }

        $phones = $this->phoneDetector->detect($listing);

        foreach ($phones as $phone) {
            $this->contactRegistry->recordEvidence(
                $phone->e164,
                $listing->id,
                $listing->source,
                $listing->sellerMeta?->name,
            );
        }

        $verdict = $this->classifier->classify($listing, $phones);

        if ($verdict->classification === Classification::REALTOR) {
            $this->seenStore->markSeen($listing->id, $listing->source);

            return false;
        }

        // Uniform rule: drop only when there is nothing actionable to send.
        // A phone or an e-mail counts as a contact; without either there is no
        // lead, regardless of whether the classifier called this OWNER or
        // UNKNOWN. (REALTOR was already filtered above.)
        $sellerMeta = $listing->sellerMeta;
        $hasEmail = $sellerMeta !== null
            && $sellerMeta->email !== null
            && $sellerMeta->email !== '';

        if ($phones === [] && ! $hasEmail) {
            $this->seenStore->markSeen($listing->id, $listing->source);

            return false;
        }

        if ($isFirstRun && $sentThisSource >= $this->firstRunLimit) {
            $this->seenStore->markSeen($listing->id, $listing->source);

            return false;
        }

        try {
            $this->notifier->send($this->formatter->format($listing, $verdict, $phones));
        } catch (\Throwable $exception) {
            $this->logger->error('Telegram send failed', [
                'listing' => $listing->id,
                'error' => $exception->getMessage(),
            ]);

            return false; // leave un-seen so it retries next run
        }

        $this->seenStore->markSeen($listing->id, $listing->source);

        return true;
    }
}
