# Sreality Owner-Listings Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the monitor actually surface private-owner listings from Sreality — fix the two `SrealityClient` bugs that drop every description and every private seller, and let owner-classified listings through even when no phone number is available.

**Architecture:** Three coordinated changes. (1) `SrealityClient` reads the detail endpoint's real shapes: `text` is a `{name, value}` object (not a string), and private sellers expose their contact under the top-level `contact` key (not `_embedded.seller`). (2) `SellerMeta` gains an `email` field so the e-mail Sreality exposes for private sellers reaches the message. (3) `MonitorRunner` stops dropping every phone-less listing — a listing classified `OWNER` is sent with whatever contact it has (e-mail, link), while phone-less `UNKNOWN` listings are still skipped as noise.

**Tech Stack:** PHP 8.4, Symfony 8, PHPUnit 13, PHPStan, ECS. Run all tooling through Docker: `docker compose run --rm bot <cmd>`.

---

## Context

End-to-end verification (the original plan's Task 13) revealed the bot sent only one Telegram message from a real run. Root cause investigation found:

- **Sreality `text` bug:** the detail endpoint returns `text` as `{"name": "Popis", "value": "..."}`. `SrealityClient::hydrate()` does `is_string($data['text']) ? ... : ''`, so **every** Sreality listing got an empty `rawText`. The classifier's text tiers (owner phrases like "bez provize", realtor phrases) never saw anything.
- **Sreality private-seller bug:** private sellers have **no** `_embedded.seller` object. Their name + e-mail (and, when logged in, phone) live in the top-level `contact` key: `{"phones": [], "name": "...", "email": "..."}`. `extractSeller()` only looked at `_embedded.seller`, so private listings got `sellerMeta: null` and no phones.
- **Phone-required gate:** `MonitorRunner::processListing()` drops any listing with zero detected phones *before* classifying. Sreality hides private-seller phone numbers behind login (`contact.phones` is `[]` when unauthenticated), so genuine owner listings were silently dropped.
- **Why tests missed it:** the fixtures in `tests/Fixtures/` were hand-written and do not match the real API — `text` is a bare string, no `contact` key, and the "private" fixture has an `_embedded.seller`. Tests passed against fictional data.

Out of scope: Bezrealitky still cannot yield phones (not in description, `contactUser` null unauthenticated). With this plan Bezrealitky listings only flow if their text trips an owner phrase. A dedicated Bezrealitky decision is deferred.

## Conventions (apply throughout)

- TDD: write the failing test, run it red, implement minimally, run it green.
- All tooling runs in Docker: `docker compose run --rm bot vendor/bin/phpunit`, `docker compose run --rm bot composer phpstan`, `docker compose run --rm bot composer ecs`.
- `declare(strict_types=1);` in every PHP file. Final classes. Readonly DTOs.
- Commit after each task with the message shown in its final step.

## File Structure

**Modified:**
- `src/Domain/SellerMeta.php` — add `?string $email` (Task 1)
- `src/Source/Sreality/SrealityClient.php` — real `text` + `contact` extraction, email (Task 2)
- `src/Monitor/MonitorRunner.php` — classify before the phone gate; send phone-less owners (Task 3)
- `src/Notification/MessageFormatter.php` — render the seller e-mail line (Task 4)
- `tests/Fixtures/sreality_detail_private.json` — replace with real-shaped private listing (Task 2)
- `tests/Fixtures/sreality_detail_agency.json` — replace with real-shaped agency listing (Task 2)
- `tests/Fixtures/sreality_detail_minimal.json` — replace with a true degenerate detail (Task 2)
- `tests/Unit/Source/Sreality/SrealityClientTest.php` — rewrite hydrate assertions (Task 2)
- `tests/Unit/Monitor/MonitorRunnerTest.php` — add phone-less owner test (Task 3)
- `tests/Unit/Notification/MessageFormatterTest.php` — add e-mail tests (Task 4)

**Created:**
- `tests/Unit/Domain/SellerMetaTest.php` — covers the new `email` field (Task 1)

---

### Task 1: Add `email` to `SellerMeta`

`SellerMeta` is a readonly DTO. Adding `email` as a 4th constructor parameter **with a `null` default** keeps every existing call site (all use named arguments) compiling unchanged.

**Files:**
- Create: `tests/Unit/Domain/SellerMetaTest.php`
- Modify: `src/Domain/SellerMeta.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Domain/SellerMetaTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\SellerMeta;
use PHPUnit\Framework\TestCase;

final class SellerMetaTest extends TestCase
{
    public function testCarriesEmailWhenProvided(): void
    {
        $meta = new SellerMeta(
            hasPremise: false,
            totalListingCount: 1,
            name: 'Jan Novak',
            email: 'jan@example.cz',
        );

        self::assertSame('jan@example.cz', $meta->email);
    }

    public function testEmailDefaultsToNull(): void
    {
        $meta = new SellerMeta(hasPremise: true, totalListingCount: 9, name: 'RK');

        self::assertNull($meta->email);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Domain/SellerMetaTest.php`
Expected: FAIL — `Unknown named parameter $email`.

- [ ] **Step 3: Add the field**

Replace the constructor in `src/Domain/SellerMeta.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Seller metadata. Sreality exposes the richest data (premise flag, listing
 * count, name, e-mail); private Sreality sellers expose only name + e-mail.
 * Bezrealitky listings carry null.
 */
final readonly class SellerMeta
{
    public function __construct(
        public bool $hasPremise,
        public ?int $totalListingCount,
        public ?string $name,
        public ?string $email = null,
    ) {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Domain/SellerMetaTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/SellerMeta.php tests/Unit/Domain/SellerMetaTest.php
git commit -m "feat: add optional email to SellerMeta"
```

---

### Task 2: Fix `SrealityClient` — real `text` + private-seller `contact` extraction

The detail endpoint's `text` is a `{name, value}` object; private sellers live under the top-level `contact` key, not `_embedded.seller`. Replace the fictional fixtures with real-shaped captures, update the tests, then fix the client.

**Files:**
- Modify: `tests/Fixtures/sreality_detail_private.json`
- Modify: `tests/Fixtures/sreality_detail_agency.json`
- Modify: `tests/Fixtures/sreality_detail_minimal.json`
- Modify: `tests/Unit/Source/Sreality/SrealityClientTest.php`
- Modify: `src/Source/Sreality/SrealityClient.php`

- [ ] **Step 1: Replace the fixtures with real-shaped data**

Overwrite `tests/Fixtures/sreality_detail_private.json` (real capture of estate `4058014540` — a private owner: `text` is an object, **no** `_embedded.seller`, contact under top-level `contact`, no public phone):

```json
{
    "text": {
        "name": "Popis",
        "value": "Nabízíme k prodeji byt 2+kk v prestižní lokalitě Prahy. Byt je v osobním vlastnictví a nachází se v oblíbené lokalitě. Pokud Vás byt zaujal, neváhejte nám napsat nebo zavolat. Bez provize."
    },
    "contact": {
        "phones": [],
        "name": "Čenětická 2kk od vlastnika",
        "email": "ruslan76731@gmail.com"
    },
    "_embedded": {
        "images": []
    }
}
```

Overwrite `tests/Fixtures/sreality_detail_agency.json` (real capture of estate `3173163084` — a broker: `text` is an object, `_embedded.seller` with a `premise`, `specialization.category`, structured phones; `contact` is null):

```json
{
    "text": {
        "name": "Popis",
        "value": "Dovolujeme si Vám představit byt, který je součástí nově vznikajícího rezidenčního areálu Bakers Court."
    },
    "contact": null,
    "_embedded": {
        "seller": {
            "user_name": "Jiří Maštálka",
            "email": "jiri@bakerscourt.cz",
            "phones": [
                {
                    "code": "420",
                    "type": "MOB",
                    "number": "608444111"
                }
            ],
            "specialization": {
                "category": [
                    {
                        "category_main_cb": 1,
                        "num": 6
                    }
                ]
            },
            "_embedded": {
                "premise": {
                    "name": "Rezident Park 1 s.r.o."
                }
            }
        }
    }
}
```

Overwrite `tests/Fixtures/sreality_detail_minimal.json` (a true degenerate detail — no `text`, no seller, no contact):

```json
{
    "_embedded": {}
}
```

- [ ] **Step 2: Rewrite the hydrate tests**

In `tests/Unit/Source/Sreality/SrealityClientTest.php`, replace the three hydrate test methods (`testHydratePrivateListingFillsSellerMetaAndPhones`, `testHydrateAgencyListingMarksPremise`, `testHydrateMinimalDetailDegradesGracefully`) with:

```php
    public function testHydratePrivateListingReadsTextAndContactFallback(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_private.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = $client->fetchRecentListings()[0];
        $hydrated = $client->hydrate($shallow);

        // text is a {name, value} object — value must reach rawText.
        self::assertStringContainsString('Bez provize', $hydrated->rawText);
        // Private sellers have no _embedded.seller — fall back to top-level contact.
        self::assertNotNull($hydrated->sellerMeta);
        self::assertFalse($hydrated->sellerMeta->hasPremise);
        self::assertNull($hydrated->sellerMeta->totalListingCount);
        self::assertSame('Čenětická 2kk od vlastnika', $hydrated->sellerMeta->name);
        self::assertSame('ruslan76731@gmail.com', $hydrated->sellerMeta->email);
        // contact.phones is empty when unauthenticated.
        self::assertSame([], $hydrated->structuredPhones);
    }

    public function testHydrateAgencyListingMarksPremise(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_agency.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = $client->fetchRecentListings()[0];
        $hydrated = $client->hydrate($shallow);

        self::assertStringContainsString('Bakers Court', $hydrated->rawText);
        self::assertNotNull($hydrated->sellerMeta);
        self::assertTrue($hydrated->sellerMeta->hasPremise);
        self::assertSame(6, $hydrated->sellerMeta->totalListingCount);
        self::assertSame('jiri@bakerscourt.cz', $hydrated->sellerMeta->email);
        self::assertSame(['+420608444111'], $hydrated->structuredPhones);
    }

    public function testHydrateMinimalDetailDegradesGracefully(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_minimal.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = $client->fetchRecentListings()[0];
        $hydrated = $client->hydrate($shallow);

        self::assertSame('', $hydrated->rawText);
        self::assertNull($hydrated->sellerMeta);
        self::assertSame([], $hydrated->structuredPhones);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Sreality/SrealityClientTest.php`
Expected: FAIL — `testHydratePrivateListingReadsTextAndContactFallback` and `testHydrateAgencyListingMarksPremise` fail (empty `rawText`, null `sellerMeta`, wrong counts) because the client still reads the old shapes.

- [ ] **Step 4: Fix the client**

In `src/Source/Sreality/SrealityClient.php`, in `hydrate()`, replace this line:

```php
        $text = is_string($data['text'] ?? null) ? $data['text'] : '';
```

with:

```php
        $text = $this->extractText($data);
```

Then replace the entire `extractSeller()` method with the following four methods (`extractText`, `extractSeller`, `extractBrokerSeller`, `extractContactSeller`, `extractPhones`):

```php
    /**
     * The detail endpoint returns `text` as a {name, value} object. Fall back to
     * a bare string for resilience against future shape changes.
     *
     * @param array<mixed, mixed> $data
     */
    private function extractText(array $data): string
    {
        $text = $data['text'] ?? null;
        if (is_array($text)) {
            return is_string($text['value'] ?? null) ? $text['value'] : '';
        }

        return is_string($text) ? $text : '';
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array{meta: ?SellerMeta, phones: list<string>}
     */
    private function extractSeller(array $data): array
    {
        $embedded = is_array($data['_embedded'] ?? null) ? $data['_embedded'] : [];
        $seller = is_array($embedded['seller'] ?? null) ? $embedded['seller'] : null;

        if ($seller !== null) {
            return $this->extractBrokerSeller($seller);
        }

        // Private sellers have no broker profile under _embedded.seller; their
        // name, e-mail and (only when logged in) phone live in the top-level
        // `contact` object instead.
        $contact = is_array($data['contact'] ?? null) ? $data['contact'] : null;
        if ($contact !== null) {
            return $this->extractContactSeller($contact);
        }

        return [
            'meta' => null,
            'phones' => [],
        ];
    }

    /**
     * @param array<mixed, mixed> $seller
     * @return array{meta: SellerMeta, phones: list<string>}
     */
    private function extractBrokerSeller(array $seller): array
    {
        $sellerEmbedded = is_array($seller['_embedded'] ?? null) ? $seller['_embedded'] : [];
        $hasPremise = isset($sellerEmbedded['premise']);

        $name = is_string($seller['user_name'] ?? null) ? $seller['user_name'] : null;
        $email = is_string($seller['email'] ?? null) ? $seller['email'] : null;

        $specialization = is_array($seller['specialization'] ?? null) ? $seller['specialization'] : [];
        $categories = is_array($specialization['category'] ?? null) ? $specialization['category'] : [];
        $totalListingCount = null;
        if ($categories !== []) {
            $totalListingCount = 0;
            foreach ($categories as $category) {
                if (is_array($category) && is_int($category['num'] ?? null)) {
                    $totalListingCount += $category['num'];
                }
            }
        }

        return [
            'meta' => new SellerMeta($hasPremise, $totalListingCount, $name, $email),
            'phones' => $this->extractPhones($seller['phones'] ?? null),
        ];
    }

    /**
     * @param array<mixed, mixed> $contact
     * @return array{meta: SellerMeta, phones: list<string>}
     */
    private function extractContactSeller(array $contact): array
    {
        $name = is_string($contact['name'] ?? null) ? $contact['name'] : null;
        $email = is_string($contact['email'] ?? null) ? $contact['email'] : null;

        // A bare contact carries no premise or listing-count signal — treat it
        // as a private seller (hasPremise: false, totalListingCount: null).
        return [
            'meta' => new SellerMeta(false, null, $name, $email),
            'phones' => $this->extractPhones($contact['phones'] ?? null),
        ];
    }

    /**
     * Canonicalises a Sreality phones array (list of {code, type, number}) to a
     * de-duplicated list of E.164 numbers.
     *
     * @return list<string>
     */
    private function extractPhones(mixed $rawPhones): array
    {
        $phones = [];
        foreach (is_array($rawPhones) ? $rawPhones : [] as $phone) {
            if (! is_array($phone)) {
                continue;
            }
            $number = is_string($phone['number'] ?? null) ? $phone['number'] : '';
            $e164 = $this->toE164($number);
            if ($e164 !== null) {
                $phones[$e164] = true;
            }
        }

        return array_keys($phones);
    }
```

Leave `toE164()` and `options()` unchanged.

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Source/Sreality/SrealityClientTest.php`
Expected: PASS (all SrealityClient tests).

- [ ] **Step 6: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add tests/Fixtures/sreality_detail_private.json tests/Fixtures/sreality_detail_agency.json tests/Fixtures/sreality_detail_minimal.json tests/Unit/Source/Sreality/SrealityClientTest.php src/Source/Sreality/SrealityClient.php
git commit -m "fix: SrealityClient reads real text object and private-seller contact

The detail endpoint returns text as a {name, value} object and exposes
private sellers under the top-level contact key, not _embedded.seller.
The old fixtures were fictional and hid both bugs; replaced with real
API captures."
```

---

### Task 3: `MonitorRunner` — send owner listings without a phone

Move classification ahead of the phone check. A `REALTOR` verdict is always dropped. A phone-less listing is only dropped when it is **not** a confirmed `OWNER` — a confirmed owner is worth sending even with just an e-mail and a link.

**Files:**
- Modify: `tests/Unit/Monitor/MonitorRunnerTest.php`
- Modify: `src/Monitor/MonitorRunner.php`

- [ ] **Step 1: Write the failing test**

In `tests/Unit/Monitor/MonitorRunnerTest.php`, add this test method after `testSkipsListingWithoutPhone`:

```php
    public function testSendsOwnerListingWithoutAnyPhone(): void
    {
        // Owner self-identification in the text, but no phone anywhere.
        $runner = $this->runner($this->source([
            $this->listing('sreality:1', 'Prodam byt primo od majitele, bez provize'),
        ]));

        $runner->run();

        self::assertCount(1, $this->sentMessages);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Monitor/MonitorRunnerTest.php`
Expected: FAIL — `testSendsOwnerListingWithoutAnyPhone` expects 1 sent message, gets 0 (the phone gate drops it before classification).

- [ ] **Step 3: Reorder `processListing`**

In `src/Monitor/MonitorRunner.php`, replace the body of `processListing()` from the `$phones = ...` line through the end of the method with:

```php
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

        // No phone and no positive owner signal — nothing actionable to send.
        // A confirmed owner is still worth sending (e-mail / link in the message).
        if ($phones === [] && $verdict->classification !== Classification::OWNER) {
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
```

The `Classification` class is already imported (`use App\Domain\Classification;`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Monitor/MonitorRunnerTest.php`
Expected: PASS — the new test passes, and the existing ones still pass (`testSkipsListingWithoutPhone` uses neutral text → `UNKNOWN` → still dropped; `testSkipsRealtorListing` → `REALTOR` → still dropped; cap and failure-retry tests unchanged).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Monitor/MonitorRunner.php tests/Unit/Monitor/MonitorRunnerTest.php
git commit -m "feat: send owner listings even without a detected phone

Classify before the phone gate. Owner-classified listings are sent with
whatever contact they carry (email, link); phone-less UNKNOWN listings
are still skipped as noise."
```

---

### Task 4: `MessageFormatter` — render the seller e-mail

When the listing carries a seller e-mail, add a `📧` line so phone-less owner messages still have a contact.

**Files:**
- Modify: `tests/Unit/Notification/MessageFormatterTest.php`
- Modify: `src/Notification/MessageFormatter.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Notification/MessageFormatterTest.php`, add `use App\Domain\SellerMeta;` to the imports, then add these two test methods:

```php
    public function testIncludesSellerEmailWhenPresent(): void
    {
        $listing = new Listing(
            id: 'sreality:1',
            source: Source::SREALITY,
            title: 'Byt',
            price: 1,
            dealType: DealType::SALE,
            location: 'Praha',
            url: 'https://example.test/1',
            rawText: 'text',
            sellerMeta: new SellerMeta(
                hasPremise: false,
                totalListingCount: null,
                name: 'Jan',
                email: 'jan@example.cz',
            ),
            structuredPhones: [],
        );

        $message = (new MessageFormatter())->format(
            $listing,
            new Verdict(Classification::OWNER, Confidence::HIGH, ['x']),
            [],
        );

        self::assertStringContainsString('jan@example.cz', $message);
        self::assertStringContainsString('📧', $message);
    }

    public function testOmitsEmailLineWhenSellerMetaHasNoEmail(): void
    {
        $message = (new MessageFormatter())->format(
            $this->listing(),
            new Verdict(Classification::OWNER, Confidence::HIGH, ['x']),
            [new DetectedPhone('+420777123456', '777 123 456', PhoneOrigin::TEXT, null)],
        );

        self::assertStringNotContainsString('📧', $message);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Notification/MessageFormatterTest.php`
Expected: FAIL — `testIncludesSellerEmailWhenPresent` fails (no `📧` / e-mail in output).

- [ ] **Step 3: Add the e-mail line**

In `src/Notification/MessageFormatter.php`, in `format()`, immediately after the `foreach ($phones as $phone) { ... }` loop and before the `$badge = ...` line, insert:

```php
        $email = $listing->sellerMeta?->email;
        if ($email !== null && $email !== '') {
            $lines[] = '📧 ' . $this->escape($email);
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose run --rm bot vendor/bin/phpunit tests/Unit/Notification/MessageFormatterTest.php`
Expected: PASS (all MessageFormatter tests).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer phpstan && docker compose run --rm bot composer ecs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Notification/MessageFormatter.php tests/Unit/Notification/MessageFormatterTest.php
git commit -m "feat: render seller email line in Telegram message"
```

---

### Task 5: End-to-end verification

Confirm the full suite is green and a real monitoring pass now surfaces private-owner listings.

**Files:** none (verification only).

- [ ] **Step 1: Full test suite + quality tools**

Run:
```bash
docker compose run --rm bot vendor/bin/phpunit
docker compose run --rm bot composer phpstan
docker compose run --rm bot composer ecs
```
Expected: all tests pass; PHPStan no errors; ECS clean.

- [ ] **Step 2: Real monitoring pass on a fresh database**

Run:
```bash
rm -f var/monitor.db
docker compose run --rm bot php bin/console app:monitor:run
```
Expected: finishes with "Monitoring pass finished." and **no** "Telegram send failed" errors.

- [ ] **Step 3: Confirm owner listings now flow**

Run:
```bash
docker compose run --rm bot vendor/bin/phpunit  # sanity re-check, still green
sqlite3 var/monitor.db "SELECT source, COUNT(*) FROM seen_listings GROUP BY source;"
```
Then confirm with the operator that the Telegram chat received **more than one** listing this run, and that at least one is a Sreality private-owner listing (badge `👤 majitel`, often with a `📧` e-mail line and no `📞`). If zero owner listings appear, that is a data-window artifact, not a regression — re-run after some time; the unit tests are the authoritative proof of the fix.

- [ ] **Step 4: Commit any final fixes**

```bash
git add -A
git commit -m "chore: end-to-end verification fixes" || echo "nothing to commit"
```

---

## Self-Review

**1. Spec coverage** — every root-cause item maps to a task:
- Sreality `text` `{name, value}` bug → Task 2 (`extractText`).
- Sreality private-seller `contact` bug → Task 2 (`extractSeller` → `extractContactSeller`).
- Seller e-mail needs a home in the domain → Task 1 (`SellerMeta::$email`), populated in Task 2, rendered in Task 4.
- Phone-required gate drops owners → Task 3 (classify before the gate; send phone-less `OWNER`).
- Fictional fixtures hid the bugs → Task 2 Step 1 (real-shaped fixtures).
- End-to-end proof → Task 5.

**2. Placeholder scan** — no "TBD"/"implement later". Every code step shows complete code; every command step shows the exact command and expected output.

**3. Type consistency** — checked across tasks:
- `SellerMeta(bool $hasPremise, ?int $totalListingCount, ?string $name, ?string $email = null)` (Task 1) — Task 2 calls it positionally `new SellerMeta($hasPremise, $totalListingCount, $name, $email)` and `new SellerMeta(false, null, $name, $email)`; Task 4 test calls it with named args including `email:`. All four-arg-compatible. Existing call sites (`TieredAdvertiserClassifierTest`, `ListingTest`, `MonitorRunnerTest`) use named args without `email` — the `null` default keeps them valid.
- `extractText(array $data): string`, `extractSeller(array $data): array{meta: ?SellerMeta, phones: list<string>}`, `extractBrokerSeller(array): array{meta: SellerMeta, phones: list<string>}`, `extractContactSeller(array): array{meta: SellerMeta, phones: list<string>}`, `extractPhones(mixed): list<string>` (Task 2) — `hydrate()` consumes `$seller['meta']` and `$seller['phones']` exactly as before; the return shape is unchanged from the original `extractSeller`.
- `MonitorRunner::processListing` (Task 3) still returns `bool` and still calls `seenStore->markSeen`, `contactRegistry->recordEvidence`, `classifier->classify`, `formatter->format`, `notifier->send` with unchanged signatures — only statement order and one new guard changed.
- `MessageFormatter::format` (Task 4) signature unchanged; reads `$listing->sellerMeta?->email` (`?string`), which exists as of Task 1.
