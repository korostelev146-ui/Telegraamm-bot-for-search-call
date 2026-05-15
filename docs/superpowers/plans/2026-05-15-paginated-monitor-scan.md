# Paginated Batched Monitor Scan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the monitor cover the **full** Sreality and Bezrealitky Prague apartment inventory in safe, throttled hourly batches — processing up to 400 new listings per source per run, paginating list endpoints lazily, recognising phone numbers spelled out in Czech words, and unifying the send gate to a single rule: not a realtor AND has phone or e-mail.

**Architecture:** Five coordinated changes, each independently testable. (1) `PhoneDetector` learns Czech digit-words (`ctyri pet osm sest` → 4586…) so obfuscated owner numbers in Bezrealitky descriptions get caught. (2) `MonitorRunner`'s send gate becomes uniform: drop REALTOR, drop when no phone and no e-mail; classification OWNER/UNKNOWN no longer participates. (3) `SrealityClient::hydrate()` adds a self-throttle (~350 ms between detail calls) so a full scan doesn't look like a scraper. (4) The first-run cap (`MONITOR_FIRST_RUN_LIMIT`) becomes a uniform per-run, per-source `MONITOR_BATCH_LIMIT` (default 400) — unprocessed listings stay un-seen so the next run resumes the backlog. (5) `ListingSource::fetchRecentListings()` returns an `iterable<Listing>` Generator, and both clients paginate the list endpoints lazily (Sreality `?page=N&per_page=100`, Bezrealitky `offset:N`) — extra pages are never fetched once `MonitorRunner` hits the batch budget.

**Tech Stack:** PHP 8.4, Symfony 8, PHPUnit 13, PHPStan level 10, ECS. All tooling through Docker: `docker compose run --rm bot <cmd>`.

---

## Context

Operator end-to-end verification after the previous plan (`2026-05-15-sreality-owner-listings-fix.md`) showed only ~3 Telegram messages per pass on a fresh database. Live-API diagnostics found:

- **Coverage is one page deep:** `SrealityClient` requests `per_page=60` for one type at a time, `BezrealitkyClient` requests `limit:60`. The real inventory is ~4634 sale + ~4314 rent (Sreality) and 413 sale + 1405 rent (Bezrealitky) — the bot was checking ~3 % of Sreality and ~3 % of Bezrealitky. The first-run cap (15/source) compounded this.
- **Bezrealitky exposes no contact unauthenticated** (confirmed via GraphQL `listAdverts`, singular `advert(id:)`, and the rendered HTML — `messageData.requireLogin: true`). The previous "drop OWNER without phone or e-mail" gate therefore dropped 58 of 60 Bezrealitky listings every run. Bezrealitky is the FSBO site by design — the listing link is the contact channel — but the bot still needs an actionable phone in the description to actually call someone, and owners frequently spell digits as Czech words (`tel cislo na mne ctyri pet osm sest sedm devet jedna dva tri`).
- **Sreality is ~95 % brokers** (38/40 sampled detail responses have `_embedded.seller._embedded.premise`) — the classifier correctly flags them REALTOR. Genuine private owners on Sreality always carry `contact.email`; the gate should accept e-mail alone.

The operator's chosen direction:

- **Sreality:** send if not REALTOR AND (phone OR e-mail).
- **Bezrealitky:** send if not REALTOR AND phone in text (no e-mail ever).
- Detect Czech spelled-out digits in description text.
- Walk the full inventory, but process at most ~400 listings per source per hourly run — "без палева и не перегружая".

The two rules collapse to one: **drop REALTOR; drop when no phone and no e-mail**. For Bezrealitky `email` is always `null`, so the rule reduces to "needs a phone".

## Conventions (apply throughout)

- TDD: write the failing test, run it red, implement minimally, run it green.
- All tooling runs in Docker: `docker compose run --rm bot vendor/bin/phpunit`, `docker compose run --rm bot composer phpstan`, `docker compose run --rm bot composer ecs`.
- `declare(strict_types=1);` in every PHP file. Final classes. Readonly DTOs.
- Commit after each task with the message shown in its final step.
- PHPStan runs at level 10 — be strict about `mixed`, `nullsafe.neverNull`, etc.

## File Structure

**Modified:**
- `src/Phone/PhoneDetector.php` — Czech digit-word scanner alongside the existing regex scanner (Task 1).
- `src/Monitor/MonitorRunner.php` — unified contact gate; `$firstRunLimit` → `$batchLimit`, drop `isFirstRun`; per-source iteration with batch budget; move source `try/catch` around the foreach so Generator-thrown failures are handled (Tasks 2, 4, 5).
- `src/Source/Sreality/SrealityClient.php` — paginated Generator over `?page=N&per_page=100`; ~350 ms throttle between detail calls (Tasks 3, 5).
- `src/Source/Bezrealitky/BezrealitkyClient.php` — paginated Generator over `offset:N` (Task 5).
- `src/Source/ListingSource.php` — return type `array` → `iterable<Listing>` (Task 5).
- `config/services.yaml` — bind `$batchLimit` instead of `$firstRunLimit` (Task 4).
- `.env` — replace `MONITOR_FIRST_RUN_LIMIT=15` with `MONITOR_BATCH_LIMIT=400`; bump `MONITOR_INTERVAL=3600` (Task 4 + Task 7).
- `tests/Unit/Phone/PhoneDetectorTest.php` — word-digit cases (Task 1).
- `tests/Unit/Monitor/MonitorRunnerTest.php` — unified gate, batch-limit-retries-next-run (Tasks 2, 4).
- `tests/Unit/Source/Sreality/SrealityClientTest.php` — multi-page pagination, throttle no-op in tests (Tasks 3, 5).
- `tests/Unit/Source/Bezrealitky/BezrealitkyClientTest.php` — multi-page pagination (Task 5).
- `tests/Fixtures/sreality_list.json` — already covers a single page; pagination tests use additional inline `MockResponse`s.
- `tests/Fixtures/bezrealitky_list.json` — already covers a single page; pagination tests use additional inline `MockResponse`s.

**Created:** none.

---

### Task 1: `PhoneDetector` learns Czech spelled-out digits

Czech owners on Bezrealitky obfuscate the phone by spelling each digit as a word (`tel cislo na mne ctyri pet osm sest sedm devet jedna dva tri`). The existing regex scanner only matches digit characters. Add a second scanner that finds runs of **nine consecutive digit-words**, reconstructs the 9-digit national number, and emits a `DetectedPhone` with `PhoneOrigin::TEXT`.

**Files:**
- Modify: `src/Phone/PhoneDetector.php`
- Modify: `tests/Unit/Phone/PhoneDetectorTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Phone/PhoneDetectorTest.php`, just before the closing `}`:

```php
    public function testExtractsPhoneSpelledOutInCzechWords(): void
    {
        $text = 'tel cislo na mne sedm sedm sedm jedna dva tri ctyri pet sest';
        $phones = (new PhoneDetector())->detect($this->listing($text));

        self::assertCount(1, $phones);
        self::assertSame('+420777123456', $phones[0]->e164);
        self::assertSame(PhoneOrigin::TEXT, $phones[0]->origin);
        self::assertSame('cislo', $phones[0]->marker);
    }

    public function testExtractsCzechDigitWordsWithDiacritics(): void
    {
        // Same number, accented spelling: sedm/jedna/dva/tři/čtyři/pět/šest.
        $text = 'sedm sedm sedm jedna dva tři čtyři pět šest';
        $phones = (new PhoneDetector())->detect($this->listing($text));

        self::assertCount(1, $phones);
        self::assertSame('+420777123456', $phones[0]->e164);
    }

    public function testIgnoresWordDigitRunStartingWithDigitBelowTwo(): void
    {
        // First Czech mobile digit is 6-9, landlines start 2-5 — never 0/1.
        // A run starting "nula nula …" must not produce a phone.
        $text = 'nula nula jedna jedna dva tri ctyri pet sest';
        $phones = (new PhoneDetector())->detect($this->listing($text));

        self::assertSame([], $phones);
    }

    public function testRequiresExactlyNineConsecutiveDigitWords(): void
    {
        // Only eight digit-words — must not match.
        $text = 'sedm sedm sedm jedna dva tri ctyri pet';
        self::assertSame([], (new PhoneDetector())->detect($this->listing($text)));
    }

    public function testDoesNotMatchWhenNonDigitWordBreaksTheRun(): void
    {
        // A non-digit word inside the run breaks it — we never glue across.
        $text = 'sedm sedm sedm jedna NEMOVITOST tri ctyri pet sest sedm';
        self::assertSame([], (new PhoneDetector())->detect($this->listing($text)));
    }

    public function testWordDigitRunIsDeduplicatedWithStructuredPhone(): void
    {
        // Structured wins — the word-digit duplicate must not appear twice.
        $listing = $this->listing(
            rawText: 'sedm sedm sedm jedna dva tri ctyri pet sest',
            structuredPhones: ['+420777123456'],
        );
        $phones = (new PhoneDetector())->detect($listing);

        self::assertCount(1, $phones);
        self::assertSame(PhoneOrigin::STRUCTURED, $phones[0]->origin);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Phone/PhoneDetectorTest.php`
Expected: FAIL — the six new tests fail (word scanner doesn't exist).

- [ ] **Step 3: Add the digit-word vocabulary and scanner**

In `src/Phone/PhoneDetector.php`, **after** the `MARKERS` constant and before the `detect()` method, insert:

```php
    /**
     * Czech spelling of digits 0-9, including the most common variants and
     * diacritic forms an owner would actually type when obfuscating a number.
     * The keys are matched against the lowercased description, so all entries
     * are lowercase.
     */
    private const DIGIT_WORDS = [
        'nula' => 0,
        'jedna' => 1, 'jeden' => 1, 'jedno' => 1, 'jednu' => 1,
        'dva' => 2, 'dve' => 2, 'dvě' => 2,
        'tri' => 3, 'tři' => 3,
        'ctyri' => 4, 'čtyři' => 4,
        'pet' => 5, 'pět' => 5,
        'sest' => 6, 'šest' => 6,
        'sedm' => 7,
        'osm' => 8,
        'devet' => 9, 'devět' => 9,
    ];
```

Replace the `detect()` method (keep the existing scanText/markerBefore helpers untouched) with:

```php
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

        foreach ($this->scanWordDigits($listing->rawText) as $detected) {
            // Digit-runs and structured numbers both win over word-digit runs.
            $byE164[$detected->e164] ??= $detected;
        }

        return array_values($byE164);
    }
```

Then, **after** the `scanText()` method and before `markerBefore()`, add:

```php
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
        if (preg_match_all('/[\p{L}]+/u', $lower, $matches, PREG_OFFSET_CAPTURE) === false) {
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

            if ($allDigitWords && $digits[0] >= '2') {
                $startOffset = $tokens[$i][0];
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Phone/PhoneDetectorTest.php`
Expected: PASS (all PhoneDetector tests, ≥ 17 tests).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Phone/PhoneDetector.php tests/Unit/Phone/PhoneDetectorTest.php
git commit -m "feat: detect Czech spelled-out phone digits in description"
```

---

### Task 2: Unify `MonitorRunner` contact gate (drop REALTOR; require phone or e-mail)

The current gate special-cases OWNER vs UNKNOWN. Per the new direction the rule is the same for every classification: a listing is sent unless it's a confirmed REALTOR or has no phone and no e-mail. UNKNOWN + e-mail (e.g. a Sreality private seller whose text didn't trip an owner phrase) is now sent too.

**Files:**
- Modify: `src/Monitor/MonitorRunner.php`
- Modify: `tests/Unit/Monitor/MonitorRunnerTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Monitor/MonitorRunnerTest.php`, just before the closing `}`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Monitor/MonitorRunnerTest.php --filter testSendsUnknownListingWithEmailButNoPhone`
Expected: FAIL — `Failed asserting that actual size 0 matches expected size 1` (current gate drops UNKNOWN with no phone regardless of e-mail).

- [ ] **Step 3: Replace the gate**

In `src/Monitor/MonitorRunner.php`, replace the entire `if ($phones === []) { … }` block (the comment block "A phone-less listing is only a lead when …" through the closing `}` of the outer `if`) with:

```php
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
```

- [ ] **Step 4: Run all MonitorRunner tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Monitor/MonitorRunnerTest.php`
Expected: PASS — the new test plus every existing test (`testSendsOwnerListingWithoutPhoneButWithEmail`, `testSkipsOwnerListingWithoutPhoneOrEmail`, `testSkipsListingWithoutPhone`, `testSkipsRealtorListing`, etc.) all green.

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Monitor/MonitorRunner.php tests/Unit/Monitor/MonitorRunnerTest.php
git commit -m "feat: unify send gate — drop only realtors and contactless listings"
```

---

### Task 3: Throttle Sreality detail calls (~350 ms between requests)

A full-inventory scan fires hundreds of detail requests. To avoid rate-limiting and to look like a real browser the client paces itself: the first detail call goes immediately; each subsequent call sleeps until at least `THROTTLE_USEC` has elapsed since the previous one.

**Files:**
- Modify: `src/Source/Sreality/SrealityClient.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Source/Sreality/SrealityClientTest.php`, just before the closing `}`:

```php
    public function testHydrateThrottlesBetweenDetailCalls(): void
    {
        // Two list + two details = one back-to-back detail-call pair. The
        // second hydrate must wait at least THROTTLE_USEC microseconds after
        // the first; 350 ms is the production value (see SrealityClient).
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_private.json')),
            new MockResponse($this->fixture('sreality_detail_agency.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = $client->fetchRecentListings();
        $start = hrtime(true);
        $client->hydrate($shallow[0]);
        $client->hydrate($shallow[1]);
        $elapsedUs = (int) ((hrtime(true) - $start) / 1000);

        // First call has no throttle; second call must wait the full window.
        // Allow the 300 ms floor — the production value is 350 ms.
        self::assertGreaterThanOrEqual(300_000, $elapsedUs);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Sreality/SrealityClientTest.php --filter testHydrateThrottlesBetweenDetailCalls`
Expected: FAIL — total elapsed is sub-millisecond because there is no throttle yet.

- [ ] **Step 3: Add the throttle**

In `src/Source/Sreality/SrealityClient.php`, **add** this constant after the existing `SUB_CB_SLUGS` constant:

```php
    /**
     * Minimum gap between detail-endpoint calls, in microseconds. A full scan
     * fires hundreds of detail requests; pacing them ~350 ms apart keeps the
     * request rate within what a real browser would produce.
     */
    private const THROTTLE_USEC = 350_000;
```

Add a private mutable field next to the readonly constructor properties — change the class declaration's opening to:

```php
final class SrealityClient implements ListingSource
{
    private int $lastDetailCallAt = 0;

    private const LIST_URL = 'https://www.sreality.cz/api/cs/v2/estates';
```

(i.e. insert `private int $lastDetailCallAt = 0;` as the first member, above the existing `private const LIST_URL`. The class is `final` but not `readonly`, so a mutable field is allowed.)

In `hydrate()`, immediately before the `$data = $this->httpClient…` line, insert:

```php
        $this->throttleDetailCall();
```

Then add this method **immediately after** `hydrate()` (and before `dealTypes()`):

```php
    /**
     * Sleeps so that consecutive detail-endpoint calls are spaced at least
     * THROTTLE_USEC apart. The very first call passes through with no wait.
     */
    private function throttleDetailCall(): void
    {
        if ($this->lastDetailCallAt !== 0) {
            $elapsed = (int) (hrtime(true) / 1000) - $this->lastDetailCallAt;
            if ($elapsed < self::THROTTLE_USEC) {
                usleep(self::THROTTLE_USEC - $elapsed);
            }
        }

        $this->lastDetailCallAt = (int) (hrtime(true) / 1000);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Sreality/SrealityClientTest.php`
Expected: PASS — all SrealityClient tests. Note: the throttle test adds ~350 ms to total suite time; that is intentional and the only sleeping test.

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Source/Sreality/SrealityClient.php tests/Unit/Source/Sreality/SrealityClientTest.php
git commit -m "feat: throttle Sreality detail requests to ~350ms apart"
```

---

### Task 4: Replace `firstRunLimit` with per-source `batchLimit`

The first-run cap dropped (and marked seen) every listing beyond N on the very first pass — those listings were lost forever. Replace it with a uniform per-run, per-source cap: when the budget is hit the loop **stops** without marking the rest seen, so the next run picks up the backlog. This is the change that makes "hourly batches" work.

**Files:**
- Modify: `.env`
- Modify: `config/services.yaml`
- Modify: `src/Monitor/MonitorRunner.php`
- Modify: `tests/Unit/Monitor/MonitorRunnerTest.php`

- [ ] **Step 1: Update the env defaults**

In `.env`, replace the line:

```
MONITOR_FIRST_RUN_LIMIT=15
```

with:

```
MONITOR_BATCH_LIMIT=400
```

- [ ] **Step 2: Rebind the service argument**

In `config/services.yaml`, line 13, replace:

```yaml
            $firstRunLimit: '%env(int:MONITOR_FIRST_RUN_LIMIT)%'
```

with:

```yaml
            $batchLimit: '%env(int:MONITOR_BATCH_LIMIT)%'
```

- [ ] **Step 3: Update the failing test**

In `tests/Unit/Monitor/MonitorRunnerTest.php`, replace the existing `testFirstRunIsCappedByLimit` method with:

```php
    public function testBatchLimitStopsRunAndLeavesRemainderForNextRun(): void
    {
        $database = $this->makeDatabase();
        $listings = [
            $this->listing('sreality:1', 'Volejte 777 123 401, primo od majitele'),
            $this->listing('sreality:2', 'Volejte 777 123 402, primo od majitele'),
            $this->listing('sreality:3', 'Volejte 777 123 403, primo od majitele'),
            $this->listing('sreality:4', 'Volejte 777 123 404, primo od majitele'),
        ];

        // batchLimit = 2 → first run sends 2 and leaves the remaining 2 un-seen.
        $first = $this->runnerOnDatabase($database, $this->source($listings));
        $first->run();
        self::assertCount(2, $this->sentMessages);

        // Same four listings the next run — the two un-seen ones are processed.
        $second = $this->runnerOnDatabase($database, $this->source($listings));
        $second->run();
        self::assertCount(4, $this->sentMessages);
    }
```

Also rename the constructor argument in **both** `runner()` helpers in the same test file. Find the two occurrences of:

```php
            firstRunLimit: 2,
```

and replace each with:

```php
            batchLimit: 2,
```

- [ ] **Step 4: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Monitor/MonitorRunnerTest.php`
Expected: FAIL — `MonitorRunner` still has `$firstRunLimit` as a parameter; either the named-arg `batchLimit:` blows up, or the cap-then-mark-seen behaviour fails the new assertions.

- [ ] **Step 5: Rename and rewire `MonitorRunner`**

In `src/Monitor/MonitorRunner.php`:

(a) Rename the constructor parameter. Replace:

```php
        private readonly int $firstRunLimit,
```

with:

```php
        private readonly int $batchLimit,
```

(b) Drop the first-run logic from `run()`. Replace the entire `run()` method body with:

```php
    public function run(): void
    {
        foreach ($this->sources as $source) {
            $sentThisSource = 0;

            try {
                foreach ($source->fetchRecentListings() as $listing) {
                    if ($sentThisSource >= $this->batchLimit) {
                        break; // next run resumes the backlog
                    }

                    if ($this->seenStore->isSeen($listing->id)) {
                        continue;
                    }

                    if ($this->processListing($source, $listing)) {
                        ++$sentThisSource;
                    }
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Source fetch failed', [
                    'source' => $source::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
```

(c) Update `processListing()`'s signature and drop the cap block inside it. Replace the method's signature and body up to (but not including) the existing `try { $listing = $source->hydrate(…) }` block. Concretely, replace:

```php
    private function processListing(
        ListingSource $source,
        Listing $listing,
        bool $isFirstRun,
        int $sentThisSource,
    ): bool {
        try {
```

with:

```php
    private function processListing(ListingSource $source, Listing $listing): bool
    {
        try {
```

And **remove** this block entirely from `processListing()` (it sits between the gate guard and the `try { $this->notifier->send(...) }`):

```php
        if ($isFirstRun && $sentThisSource >= $this->firstRunLimit) {
            $this->seenStore->markSeen($listing->id, $listing->source);

            return false;
        }
```

The remainder of `processListing()` (hydrate, phone detection, evidence recording, classify, the unified gate from Task 2, the `notifier->send` try/catch, the final `markSeen` + `return true`) stays unchanged.

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Monitor/MonitorRunnerTest.php`
Expected: PASS — including the new batch-limit-retries test and every existing one (`testOneSourceFailingDoesNotStopOthers` now relies on `try/catch` wrapping the foreach instead of the fetch line, but still passes because a `throw` from `fetchRecentListings()` still surfaces during iteration).

- [ ] **Step 7: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add .env config/services.yaml src/Monitor/MonitorRunner.php tests/Unit/Monitor/MonitorRunnerTest.php
git commit -m "feat: per-source batch limit; un-seen remainder retries next run"
```

---

### Task 5: Lazy pagination for both sources

`ListingSource::fetchRecentListings()` becomes `iterable<Listing>` — a Generator. Both clients paginate the list endpoint lazily, yielding one listing at a time; once `MonitorRunner` hits `batchLimit` it breaks out of the foreach and the unused pages are never fetched. This is what makes the full inventory actually reachable without firing thousands of requests up front.

**Files:**
- Modify: `src/Source/ListingSource.php`
- Modify: `src/Source/Sreality/SrealityClient.php`
- Modify: `src/Source/Bezrealitky/BezrealitkyClient.php`
- Modify: `tests/Unit/Source/Sreality/SrealityClientTest.php`
- Modify: `tests/Unit/Source/Bezrealitky/BezrealitkyClientTest.php`

- [ ] **Step 1: Loosen the interface return type**

In `src/Source/ListingSource.php`, replace the `fetchRecentListings()` declaration with:

```php
    /**
     * Cheap call: yields shallow listings (rawText/sellerMeta/structuredPhones may be empty).
     * Implementations MUST yield listings ordered newest-first; pagination is lazy so a
     * consumer that breaks out of the foreach early skips the unfetched pages.
     *
     * @return iterable<Listing>
     */
    public function fetchRecentListings(): iterable;
```

- [ ] **Step 2: Write failing tests for Sreality multi-page pagination**

Append to `tests/Unit/Source/Sreality/SrealityClientTest.php`, just before the closing `}`:

```php
    public function testFetchRecentListingsPaginatesAcrossPages(): void
    {
        // Page 1 returns 100 listings (a full page → keep paginating), page 2
        // returns 1 listing (a short page → stop). The client must yield all 101.
        $page1 = json_encode([
            '_embedded' => [
                'estates' => array_map(
                    static fn (int $i) => [
                        'hash_id' => 1000 + $i,
                        'name' => 'Prodej bytu',
                        'locality' => 'Praha',
                        'price' => 1,
                        'seo' => [
                            'category_main_cb' => 1,
                            'category_sub_cb' => 4,
                            'category_type_cb' => 1,
                            'locality' => 'praha',
                        ],
                    ],
                    range(1, 100),
                ),
            ],
        ]);
        $page2 = json_encode([
            '_embedded' => [
                'estates' => [[
                    'hash_id' => 9999,
                    'name' => 'Prodej bytu',
                    'locality' => 'Praha',
                    'price' => 1,
                    'seo' => [
                        'category_main_cb' => 1,
                        'category_sub_cb' => 4,
                        'category_type_cb' => 1,
                        'locality' => 'praha',
                    ],
                ]],
            ],
        ]);
        self::assertIsString($page1);
        self::assertIsString($page2);

        $http = new MockHttpClient([new MockResponse($page1), new MockResponse($page2)]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $ids = [];
        foreach ($client->fetchRecentListings() as $listing) {
            $ids[] = $listing->id;
        }

        self::assertCount(101, $ids);
        self::assertSame('sreality:1001', $ids[0]);
        self::assertSame('sreality:9999', $ids[100]);
    }

    public function testFetchRecentListingsStopsEarlyWhenConsumerBreaks(): void
    {
        // Only the first page response is queued — the test will fail with
        // "no more responses" if the client tries to fetch page 2.
        $http = new MockHttpClient([new MockResponse($this->fixture('sreality_list.json'))]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $first = null;
        foreach ($client->fetchRecentListings() as $listing) {
            $first = $listing;
            break;
        }

        self::assertNotNull($first);
        self::assertSame('sreality:111', $first->id);
    }
```

Also rewrite the existing `testFetchRecentListingsCombinesSaleAndRent` to iterate the Generator:

```php
    public function testFetchRecentListingsCombinesSaleAndRent(): void
    {
        // For each deal type the client paginates until a short/empty page;
        // a one-item page counts as "short" and ends pagination for that type.
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_list.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertCount(4, iterator_to_array($client->fetchRecentListings(), false));
    }
```

The existing `testFetchRecentListingsMapsShallowListings` and `testFetchRecentListingsBuildsDetailUrlWhenSeoIsMissing` already iterate the result via `[0]`/`[1]`; rewrite those to materialise the Generator first:

```php
    public function testFetchRecentListingsMapsShallowListings(): void
    {
        $http = new MockHttpClient([new MockResponse($this->fixture('sreality_list.json'))]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $listings = iterator_to_array($client->fetchRecentListings(), false);

        self::assertCount(2, $listings);
        self::assertSame('sreality:111', $listings[0]->id);
        self::assertSame(Source::SREALITY, $listings[0]->source);
        self::assertSame(DealType::SALE, $listings[0]->dealType);
        self::assertSame('Praha 7 - Holesovice', $listings[0]->location);
        self::assertSame(
            'https://www.sreality.cz/detail/prodej/byt/2+kk/praha-7-holesovice/111',
            $listings[0]->url,
        );
        self::assertSame(
            'https://www.sreality.cz/detail/prodej/byt/3+1/praha-5-smichov/222',
            $listings[1]->url,
        );
    }

    public function testFetchRecentListingsBuildsDetailUrlWhenSeoIsMissing(): void
    {
        $http = new MockHttpClient([new MockResponse(
            '{"_embedded":{"estates":[{"hash_id":333,"name":"Byt","locality":"Praha"}]}}',
        )]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'rent');

        $listings = iterator_to_array($client->fetchRecentListings(), false);

        self::assertSame(
            'https://www.sreality.cz/detail/pronajem/byt/1+kk/praha/333',
            $listings[0]->url,
        );
    }
```

The `testHydrate*` tests use `$client->fetchRecentListings()[0]`. Replace those calls too — change each occurrence of:

```php
        $shallow = $client->fetchRecentListings()[0];
```

to:

```php
        $shallow = iterator_to_array($client->fetchRecentListings(), false)[0];
```

(there are three: `testHydratePrivateListingReadsTextAndContactFallback`, `testHydrateAgencyListingMarksPremise`, `testHydrateMinimalDetailDegradesGracefully`). Also update `testHydrateThrottlesBetweenDetailCalls` (added in Task 3) the same way: replace its `$shallow = $client->fetchRecentListings();` with `$shallow = iterator_to_array($client->fetchRecentListings(), false);`.

- [ ] **Step 3: Run Sreality tests to verify they fail**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Sreality/SrealityClientTest.php`
Expected: FAIL — `testFetchRecentListingsPaginatesAcrossPages` fails (only 2 listings or a "no responses queued" error from MockHttpClient).

- [ ] **Step 4: Convert SrealityClient to a paginated Generator**

In `src/Source/Sreality/SrealityClient.php`, **add** a constant after the existing `DEAL_TYPE_CODES` const:

```php
    /**
     * `per_page` is honoured by Sreality up to 100 (verified empirically); a
     * "short" page (strictly fewer items than this) marks the last page.
     */
    private const PER_PAGE = 100;
```

Replace the entire `fetchRecentListings()` method with:

```php
    public function fetchRecentListings(): iterable
    {
        $regionId = self::REGION_IDS[$this->monitorRegion] ?? self::REGION_IDS['praha'];

        foreach ($this->dealTypes() as $dealType) {
            $page = 1;
            while (true) {
                $query = http_build_query([
                    'category_main_cb' => self::APARTMENT_CATEGORY,
                    'category_type_cb' => self::DEAL_TYPE_CODES[$dealType->value],
                    'locality_region_id' => $regionId,
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                    'sort' => 'date',
                ]);

                $this->logger->info('Sreality list request', [
                    'query' => $query,
                ]);

                $data = $this->httpClient
                    ->request('GET', self::LIST_URL . '?' . $query, $this->options())
                    ->toArray();

                $embedded = is_array($data['_embedded'] ?? null) ? $data['_embedded'] : [];
                $estates = is_array($embedded['estates'] ?? null) ? $embedded['estates'] : [];

                if ($estates === []) {
                    break;
                }

                foreach ($estates as $estate) {
                    if (is_array($estate)) {
                        yield $this->mapShallow($estate, $dealType);
                    }
                }

                if (count($estates) < self::PER_PAGE) {
                    break; // short page → no more results
                }

                ++$page;
            }
        }
    }
```

- [ ] **Step 5: Run Sreality tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Sreality/SrealityClientTest.php`
Expected: PASS (all SrealityClient tests).

- [ ] **Step 6: Write failing tests for Bezrealitky multi-page pagination**

Append to `tests/Unit/Source/Bezrealitky/BezrealitkyClientTest.php`, just before the closing `}`:

```php
    public function testFetchRecentListingsPaginatesAcrossPages(): void
    {
        $page1List = array_map(
            static fn (int $i) => [
                'id' => (string) (2_000_000 + $i),
                'uri' => 'byt-' . $i,
                'title' => 'Prodej bytu',
                'address' => 'Praha',
                'price' => 1,
                'offerType' => 'PRODEJ',
                'description' => 'desc',
            ],
            range(1, 100),
        );
        $page1 = json_encode(['data' => ['listAdverts' => ['totalCount' => 101, 'list' => $page1List]]]);
        $page2 = json_encode(['data' => ['listAdverts' => ['totalCount' => 101, 'list' => [[
            'id' => '9999999',
            'uri' => 'last',
            'title' => 'Last',
            'address' => 'Praha',
            'price' => 1,
            'offerType' => 'PRODEJ',
            'description' => 'last',
        ]]]]]);
        self::assertIsString($page1);
        self::assertIsString($page2);

        $http = new MockHttpClient([new MockResponse($page1), new MockResponse($page2)]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale');

        $ids = array_map(
            static fn ($l) => $l->id,
            iterator_to_array($client->fetchRecentListings(), false),
        );

        self::assertCount(101, $ids);
        self::assertSame('bezrealitky:9999999', $ids[100]);
    }

    public function testFetchRecentListingsStopsAfterShortPage(): void
    {
        // A single short page (fewer items than PER_PAGE = 100) ends pagination
        // — the client must not request page 2.
        $http = new MockHttpClient([new MockResponse($this->fixture())]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertCount(2, iterator_to_array($client->fetchRecentListings(), false));
    }
```

Also update the existing `testFetchRecentListingsMapsFullListings`: change

```php
        $listings = $client->fetchRecentListings();
```

to

```php
        $listings = iterator_to_array($client->fetchRecentListings(), false);
```

And update `testHydrateIsIdentity` the same way — replace `$listing = $client->fetchRecentListings()[0];` with `$listing = iterator_to_array($client->fetchRecentListings(), false)[0];`.

For `testMalformedGraphQlResponseYieldsEmptyList`, replace the assertion with:

```php
        self::assertSame([], iterator_to_array($client->fetchRecentListings(), false));
```

- [ ] **Step 7: Run Bezrealitky tests to verify they fail**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Bezrealitky/BezrealitkyClientTest.php`
Expected: FAIL — pagination test only sees the first page.

- [ ] **Step 8: Convert BezrealitkyClient to a paginated Generator**

In `src/Source/Bezrealitky/BezrealitkyClient.php`, **add** a constant after `DEAL_TYPE_CODES`:

```php
    /**
     * Page size for the GraphQL list call. A "short" page (strictly fewer items
     * than this) marks the last page.
     */
    private const PER_PAGE = 100;
```

Update the GraphQL document to accept an `$offset` variable. Replace the `QUERY` constant with:

```php
    private const QUERY = <<<'GQL'
        query AdvertList($offerType: [OfferType], $regionId: ID, $limit: Int, $offset: Int, $order: ResultOrder) {
            listAdverts(offerType: $offerType, estateType: [BYT], regionId: $regionId, limit: $limit, offset: $offset, order: $order) {
                totalCount
                list {
                    id
                    uri
                    title
                    address(locale: CS)
                    price
                    offerType
                    description
                }
            }
        }
        GQL;
```

Replace the entire `fetchRecentListings()` method with:

```php
    public function fetchRecentListings(): iterable
    {
        $regionId = self::REGION_IDS[$this->monitorRegion] ?? self::REGION_IDS['praha'];
        $offerTypes = $this->offerTypes();

        $offset = 0;
        while (true) {
            $variables = [
                'offerType' => $offerTypes,
                'regionId' => $regionId,
                'limit' => self::PER_PAGE,
                'offset' => $offset,
                'order' => 'TIMEORDER_DESC',
            ];

            $this->logger->info('Bezrealitky GraphQL request', [
                'variables' => $variables,
            ]);

            $data = $this->httpClient->request('POST', self::GRAPHQL_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'json' => [
                    'query' => self::QUERY,
                    'operationName' => 'AdvertList',
                    'variables' => $variables,
                ],
            ])->toArray();

            $dataSection = is_array($data['data'] ?? null) ? $data['data'] : [];
            $listAdverts = is_array($dataSection['listAdverts'] ?? null) ? $dataSection['listAdverts'] : [];
            $rawList = is_array($listAdverts['list'] ?? null) ? $listAdverts['list'] : [];

            if ($rawList === []) {
                break;
            }

            foreach ($rawList as $item) {
                if (is_array($item)) {
                    yield $this->map($item);
                }
            }

            if (count($rawList) < self::PER_PAGE) {
                break; // short page → no more results
            }

            $offset += self::PER_PAGE;
        }
    }
```

- [ ] **Step 9: Run Bezrealitky tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Bezrealitky/BezrealitkyClientTest.php`
Expected: PASS.

- [ ] **Step 10: Run the full suite + quality tools**

Run:

```bash
docker compose run --rm bot vendor/bin/phpunit
docker compose run --rm bot composer phpstan
docker compose run --rm bot composer ecs
```

Expected: all tests pass; PHPStan no errors; ECS clean. `MonitorRunner` already iterates the result as `foreach`, so it consumes the Generator without code changes.

- [ ] **Step 11: Commit**

```bash
git add src/Source/ListingSource.php src/Source/Sreality/SrealityClient.php src/Source/Bezrealitky/BezrealitkyClient.php tests/Unit/Source/Sreality/SrealityClientTest.php tests/Unit/Source/Bezrealitky/BezrealitkyClientTest.php
git commit -m "feat: paginate both sources lazily via iterable<Listing> generators"
```

---

### Task 6: Schedule the monitor hourly

The previous setup ran every 20 minutes. With the new batch model an hourly cadence is enough — and the value is purely scheduling configuration consumed by whatever external process invokes `app:monitor:run` (cron, systemd timer, the operator's shell). No code reads `MONITOR_INTERVAL`.

**Files:**
- Modify: `.env`
- Modify: `.env.local`

- [ ] **Step 1: Update `.env`**

In `.env`, replace:

```
MONITOR_INTERVAL=1200
```

with:

```
MONITOR_INTERVAL=3600
```

- [ ] **Step 2: Update `.env.local`**

The operator's local file also pins the interval — update it too. In `.env.local`, replace the line:

```
MONITOR_INTERVAL=1200
```

with:

```
MONITOR_INTERVAL=3600
```

(`.env.local` is gitignored — this change stays on the operator's machine.)

- [ ] **Step 3: Verify nothing in code reads it**

Run: `docker compose run --rm bot grep -r 'MONITOR_INTERVAL' src/ config/ || echo "no references — good"`
Expected: `no references — good` (the value is consumed by external scheduling).

- [ ] **Step 4: Commit**

```bash
git add .env
git commit -m "chore: switch monitor cadence to hourly (MONITOR_INTERVAL=3600)"
```

(`.env.local` is gitignored and not committed.)

---

### Task 7: End-to-end verification

Confirm the full suite is green and a real monitoring pass on a fresh database delivers a meaningful batch to Telegram with phone-or-email contacts on every message.

**Files:** none (verification only).

- [ ] **Step 1: Full test suite + quality tools**

Run:

```bash
docker compose run --rm bot vendor/bin/phpunit
docker compose run --rm bot composer phpstan
docker compose run --rm bot composer ecs
```

Expected: all green.

- [ ] **Step 2: Real monitoring pass on a fresh database**

Run:

```bash
rm -f var/monitor.db var/monitor.db-shm var/monitor.db-wal
docker compose run --rm bot php bin/console app:monitor:run
```

Expected: `Monitoring pass finished.`, no `Telegram send failed` errors. The pass should take ~2–3 minutes (mostly Sreality detail throttle) and deliver up to 400 listings per source — capped only by listings that have neither a phone nor an e-mail (Bezrealitky's contactless majority, ~95 % of the inventory) and by realtor filtering.

- [ ] **Step 3: Confirm with the operator**

Ask the operator to verify in Telegram that:
- More than 3 messages arrived (the count before this plan).
- Sreality messages still resolve when clicked (the SEO URL fix from the previous plan stays good).
- Some Bezrealitky messages now arrive with a phone number that was spelled in Czech words (e.g. `ctyri pet osm sest …`).
- No message is contactless (every message shows `📞` or `📧`).

If zero owner listings appear after a re-run, that is a data-window artefact — the unit tests are the authoritative proof of the fix.

- [ ] **Step 4: Commit any final fixes**

```bash
git add -A
git commit -m "chore: end-to-end verification fixes" || echo "nothing to commit"
```

---

## Self-Review

**1. Spec coverage** — every directive from the conversation maps to a task:

- "Bezrealitky: send only listings with a phone in the description" → Task 2 unified gate (Bezrealitky has no e-mail → reduces to "must have a phone"); Task 1 makes word-digit phones detectable so this gate actually surfaces real Bezrealitky listings.
- "Sreality: send where there is phone or e-mail" → Task 2 unified gate.
- "Detect digits spelled in Czech words" → Task 1.
- "Check ALL listings on both sites" → Task 5 (lazy pagination across every page).
- "~400 per source per run, hourly, без палева" → Task 4 (per-source batchLimit) + Task 3 (~350 ms detail throttle) + Task 6 (hourly cadence).
- "New listings prioritised" → Tasks 3 and 5 keep the `sort=date` / `TIMEORDER_DESC` newest-first ordering; `seenStore` filters out already-processed items cheaply.
- End-to-end verification → Task 7.

**2. Placeholder scan** — no "TBD", "implement later", "appropriate error handling". Every code step shows complete code; every command step shows the exact command and expected output.

**3. Type consistency** — checked across tasks:

- `ListingSource::fetchRecentListings(): iterable<Listing>` (Task 5) — both clients yield via `yield`; `MonitorRunner::run()` (Task 4) iterates `foreach ($source->fetchRecentListings() as $listing)`. `iterable` accepts both Generators and arrays, so the anonymous `ListingSource` in `MonitorRunnerTest` (which still returns `array`) continues to satisfy the interface.
- `MonitorRunner::__construct(... int $batchLimit)` (Task 4) — `services.yaml` binds `$batchLimit: '%env(int:MONITOR_BATCH_LIMIT)%'`; the two test helpers in `MonitorRunnerTest` use `batchLimit: 2`.
- `PhoneDetector::DIGIT_WORDS` is `array<string, int>`, used inside `scanWordDigits()` for token-by-token lookup; reconstructed `$digits` is built by concatenation and compared by character (`$digits[0] >= '2'`).
- `SrealityClient::$lastDetailCallAt` is `int` (microseconds-since-boot from `hrtime(true) / 1000`), compared against `int THROTTLE_USEC`.

**4. No interface drift** — the only behaviour change visible to `MonitorRunner` outside its own file is the return type widening of `ListingSource::fetchRecentListings()` from `array` to `iterable`. `array` is itself `iterable`, so any future source that still returns an array (the test fake does) keeps compiling.
