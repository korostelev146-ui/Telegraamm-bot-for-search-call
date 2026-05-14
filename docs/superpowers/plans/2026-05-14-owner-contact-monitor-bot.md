# Owner Contact Monitor Bot — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a personal Symfony 8 / PHP 8.4 Telegram bot that, on a ~20-minute schedule, scans Sreality and Bezrealitky for new Prague property listings posted by owners (not realtors) with a reachable phone number, and pushes them to one operator's Telegram chat.

**Architecture:** A CLI console command (`app:monitor:run`) run in a loop inside Docker. Each run pulls listings from pluggable `ListingSource` implementations, extracts phone numbers (`PhoneDetector`), records each number as evidence in a SQLite `ContactRegistry`, classifies owner-vs-realtor through a tiered deterministic funnel (`TieredAdvertiserClassifier`), deduplicates against a SQLite `SeenStore`, and sends owner/unknown listings to Telegram. The core idea is to classify the *contact* (phone number, which accumulates evidence across listings) rather than the ambiguous single listing.

**Tech Stack:** Symfony 8.0 (console, http-client, framework-bundle, runtime, dotenv), PHP 8.4, SQLite (pdo_sqlite), Docker (php:8.4-cli-alpine), PHPUnit 13, PHPStan level 10, EasyCodingStandard.

**Spec:** `docs/superpowers/specs/2026-05-14-owner-contact-monitor-bot-design.md`

**Reference project:** `/Users/vladislav/Desktop/Projects/simple-house-estate/` — a working Symfony 8 Telegram bot with the same stack. Its scaffolding, Dockerfile, quality-tool configs, and HTTP-client patterns are reused. It is referred to below as "the reference project".

---

## Context

The operator manually hunts Czech listing sites for private (non-agency) sellers to call about their property. This bot automates the hunt. Reconnaissance (recorded in the spec) established:

- **Sreality** exposes the seller phone and full description text on its *detail* endpoint, and marks agencies via `_embedded.seller._embedded.premise`. Fresh Prague inventory is overwhelmingly agency-posted, so realtor filtering matters a lot.
- **Bezrealitky** exposes the full description but **no** structured phone (it returns `null` unauthenticated) and **no** structured realtor signal. Phone numbers are only obtainable when the owner pasted them into the description text.

Therefore the phone-number text scanner and the classifier are the heart of the system, and classification must lean on text signals plus cross-listing phone-frequency evidence rather than structured API fields.

---

## File Structure

New project root: `/Users/vladislav/Desktop/Projects/TElegramm bot call/Telegraamm-bot-for-search-call/`
(referred to below as `<root>`; it already contains `.git`, `.gitignore`, `.env.local`, `docs/`).

**Scaffolding (Task 0):**
- `composer.json`, `composer.lock`, `symfony.lock` — dependencies
- `bin/console` — Symfony console entry point
- `public/index.php` — runtime entry point (kept for `symfony/flex` auto-scripts)
- `src/Kernel.php` — microkernel
- `config/services.yaml`, `config/bundles.php`, `config/packages/framework.yaml`, `config/packages/cache.yaml`, `config/routes.yaml` — framework config + DI wiring
- `.env`, `.env.test` — env defaults
- `phpstan.neon`, `ecs.php`, `phpunit.dist.xml`, `tests/bootstrap.php` — quality tooling
- `Dockerfile`, `docker-compose.yml`, `.dockerignore`, `docker-entrypoint.sh` — containerisation
- `AGENTS.md` — coding conventions (copied from reference)

**Domain (Task 1):**
- `src/Domain/Source.php` — enum: which site a listing came from
- `src/Domain/DealType.php` — enum: sale / rent
- `src/Domain/SellerMeta.php` — DTO: Sreality-only seller metadata
- `src/Domain/Listing.php` — DTO: a normalised listing
- `src/Domain/PhoneOrigin.php` — enum: structured field vs text
- `src/Domain/DetectedPhone.php` — DTO: an extracted phone number
- `src/Domain/Classification.php` — enum: owner / realtor / unknown
- `src/Domain/Confidence.php` — enum: high / medium / low
- `src/Domain/Verdict.php` — DTO: classification result

**Phone detection (Task 2):**
- `src/Phone/PhoneDetector.php` — extracts phone numbers from a `Listing`

**Persistence (Tasks 3–5):**
- `src/Persistence/Database.php` — opens the SQLite connection, creates the schema
- `src/Persistence/SeenStore.php` — processed-listing-id set (deduplication)
- `src/Persistence/ContactRegistry.php` — phone-number evidence + verdict store

**Classification (Task 6):**
- `src/Classification/AdvertiserClassifier.php` — interface
- `src/Classification/TieredAdvertiserClassifier.php` — Tier 0–2 deterministic funnel

**Sources (Tasks 7–8):**
- `src/Source/ListingSource.php` — interface (`fetchRecentListings`, `hydrate`)
- `src/Source/Sreality/SrealityClient.php` — Sreality REST source
- `src/Source/Bezrealitky/BezrealitkyClient.php` — Bezrealitky GraphQL source

**Notification (Tasks 9–10):**
- `src/Notification/MessageFormatter.php` — `Listing` + `Verdict` → Telegram HTML
- `src/Notification/TelegramNotifier.php` — sends a message to the operator's chat

**Orchestration (Tasks 11–12):**
- `src/Monitor/MonitorRunner.php` — one monitoring pass
- `src/Command/MonitorCommand.php` — thin console command wrapper

Test files mirror `src/` under `tests/Unit/` and fixtures live in `tests/Fixtures/`.

---

## Conventions (from the reference project's AGENTS.md — apply throughout)

- `declare(strict_types=1);` in every PHP file.
- Typed properties, arguments, returns. No `mixed` where avoidable (PHPStan level 10).
- Immutable DTOs: `final readonly class`.
- Constructor injection only. `final` classes. One responsibility per file.
- No business logic in the command or in transport classes (`TelegramNotifier`).
- No silent failures — log and continue, never swallow.
- Explicit names (`PhoneDetector`, `TieredAdvertiserClassifier`), not `Helper`/`Manager`.

**Running tooling** (after Task 0 the project is volume-mounted into the `bot` container):
- Tests: `docker compose run --rm bot vendor/bin/phpunit`
- One test: `docker compose run --rm bot vendor/bin/phpunit --filter <TestMethodName>`
- Static analysis: `docker compose run --rm bot composer phpstan`
- Code style: `docker compose run --rm bot composer ecs` (fix: `composer ecs:fix`)

**Commit discipline:** commit after every task's tests pass. Work directly on `main` (solo-dev workflow). Commit messages: `feat:` / `test:` / `chore:` prefixes.

---

### Task 0: Scaffold the Symfony 8 project

Creates a working, empty Symfony 8 CLI project with Docker and quality tooling, mirroring the reference project.

**Files:**
- Create: all scaffolding files listed under "File Structure → Scaffolding" above.

- [ ] **Step 1: Copy proven scaffolding from the reference project**

Run from `<root>`:
```bash
REF="/Users/vladislav/Desktop/Projects/simple-house-estate"
cp "$REF/bin/console" bin/console 2>/dev/null || (mkdir -p bin && cp "$REF/bin/console" bin/console)
mkdir -p public config/packages tests src
cp "$REF/public/index.php" public/index.php
cp "$REF/src/Kernel.php" src/Kernel.php
cp "$REF/config/bundles.php" config/bundles.php
cp "$REF/config/routes.yaml" config/routes.yaml
cp "$REF/config/packages/cache.yaml" config/packages/cache.yaml
cp "$REF/config/packages/framework.yaml" config/packages/framework.yaml
cp "$REF/phpunit.dist.xml" phpunit.dist.xml
cp "$REF/tests/bootstrap.php" tests/bootstrap.php
cp "$REF/ecs.php" ecs.php
cp "$REF/.dockerignore" .dockerignore
cp "$REF/AGENTS.md" AGENTS.md
chmod +x bin/console
```

- [ ] **Step 2: Write `composer.json`**

Create `<root>/composer.json` (trimmed from the reference — no `telegram-bot/api`, since we only *send* messages via plain HTTP; adds the `pdo_sqlite` requirement):
```json
{
    "type": "project",
    "license": "proprietary",
    "minimum-stability": "stable",
    "prefer-stable": true,
    "require": {
        "php": ">=8.4",
        "ext-ctype": "*",
        "ext-iconv": "*",
        "ext-pdo_sqlite": "*",
        "symfony/console": "8.0.*",
        "symfony/dotenv": "8.0.*",
        "symfony/flex": "^2",
        "symfony/framework-bundle": "8.0.*",
        "symfony/http-client": "8.0.*",
        "symfony/runtime": "8.0.*",
        "symfony/yaml": "8.0.*"
    },
    "config": {
        "allow-plugins": {
            "php-http/discovery": true,
            "symfony/flex": true,
            "symfony/runtime": true,
            "phpstan/extension-installer": true
        },
        "bump-after-update": true,
        "sort-packages": true
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": "tests/"
        }
    },
    "replace": {
        "symfony/polyfill-ctype": "*",
        "symfony/polyfill-iconv": "*",
        "symfony/polyfill-php72": "*",
        "symfony/polyfill-php73": "*",
        "symfony/polyfill-php74": "*",
        "symfony/polyfill-php80": "*",
        "symfony/polyfill-php81": "*",
        "symfony/polyfill-php82": "*",
        "symfony/polyfill-php83": "*",
        "symfony/polyfill-php84": "*"
    },
    "scripts": {
        "auto-scripts": {
            "cache:clear": "symfony-cmd",
            "assets:install %PUBLIC_DIR%": "symfony-cmd"
        },
        "post-install-cmd": [
            "@auto-scripts"
        ],
        "post-update-cmd": [
            "@auto-scripts"
        ],
        "phpstan": "vendor/bin/phpstan analyse",
        "ecs": "vendor/bin/ecs check",
        "ecs:fix": "vendor/bin/ecs check --fix",
        "test": "vendor/bin/phpunit",
        "lint": [
            "@phpstan",
            "@ecs"
        ]
    },
    "conflict": {
        "symfony/symfony": "*"
    },
    "extra": {
        "symfony": {
            "allow-contrib": false,
            "require": "8.0.*"
        }
    },
    "require-dev": {
        "phpstan/extension-installer": "*",
        "phpstan/phpstan": "*",
        "phpstan/phpstan-symfony": "*",
        "phpunit/phpunit": "^13.0",
        "symplify/easy-coding-standard": "*"
    }
}
```

- [ ] **Step 3: Write `.env` and `.env.test`**

Create `<root>/.env`:
```
APP_ENV=dev
APP_SECRET=
DEFAULT_URI=http://localhost

# Telegram — real values live in .env.local (gitignored), filled in by the operator
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=

# Monitor settings
MONITOR_REGION=praha
MONITOR_DEAL_TYPES=sale,rent
MONITOR_INTERVAL=1200
MONITOR_FIRST_RUN_LIMIT=15
DATABASE_PATH=%kernel.project_dir%/var/monitor.db
```

Create `<root>/.env.test`:
```
KERNEL_CLASS='App\Kernel'
APP_SECRET='$ecretf0rt3st'
TELEGRAM_BOT_TOKEN='test-token'
TELEGRAM_CHAT_ID='123456'
DATABASE_PATH=':memory:'
```

- [ ] **Step 4: Write `config/services.yaml`**

Create `<root>/config/services.yaml`:
```yaml
parameters:

services:
    _defaults:
        autowire: true
        autoconfigure: true
        bind:
            $telegramToken: '%env(TELEGRAM_BOT_TOKEN)%'
            $telegramChatId: '%env(TELEGRAM_CHAT_ID)%'
            $databasePath: '%env(resolve:DATABASE_PATH)%'
            $monitorRegion: '%env(MONITOR_REGION)%'
            $monitorDealTypes: '%env(MONITOR_DEAL_TYPES)%'
            $firstRunLimit: '%env(int:MONITOR_FIRST_RUN_LIMIT)%'

    _instanceof:
        App\Source\ListingSource:
            tags: ['app.listing_source']

    App\:
        resource: '../src/'

    App\Monitor\MonitorRunner:
        arguments:
            $sources: !tagged_iterator app.listing_source
```

- [ ] **Step 5: Write the Dockerfile**

Create `<root>/Dockerfile` (reference Dockerfile + `pdo_sqlite` extension + entrypoint script; the run command is the monitor loop):
```dockerfile
FROM php:8.4-cli-alpine

RUN apk add --no-cache icu-libs sqlite-libs \
    && apk add --no-cache --virtual .build-deps icu-dev sqlite-dev \
    && docker-php-ext-install intl pdo_sqlite \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./

RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && composer run-script post-install-cmd --no-interaction \
    && chmod +x bin/console docker-entrypoint.sh

CMD ["./docker-entrypoint.sh"]
```

- [ ] **Step 6: Write `docker-entrypoint.sh`**

Create `<root>/docker-entrypoint.sh` (the ~20-minute loop — the "scheduler"):
```bash
#!/bin/sh
set -e

INTERVAL="${MONITOR_INTERVAL:-1200}"

echo "Monitor loop starting (interval ${INTERVAL}s)"
while true; do
    php bin/console app:monitor:run || echo "monitor run failed, continuing"
    sleep "$INTERVAL"
done
```

- [ ] **Step 7: Write `docker-compose.yml`**

Create `<root>/docker-compose.yml` (reference compose + a volume mount so code edits are live and `vendor/`/`var/` persist on the host for the dev loop):
```yaml
services:
  bot:
    build: .
    restart: unless-stopped
    env_file:
      - .env
      - .env.local
    environment:
      APP_ENV: prod
    volumes:
      - .:/app
```

- [ ] **Step 8: Write `phpstan.neon`**

Create `<root>/phpstan.neon`:
```neon
parameters:
    level: 10
    paths:
        - src
    symfony:
        containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
```

- [ ] **Step 9: Build the image and install dependencies**

Run from `<root>`:
```bash
docker compose build
docker compose run --rm bot composer install
```
Expected: `composer install` completes; `vendor/` and `composer.lock` appear on the host; `symfony.lock` is created.

- [ ] **Step 10: Verify the skeleton runs**

Run:
```bash
docker compose run --rm bot php bin/console list
```
Expected: Symfony lists built-in commands with no errors (no `app:monitor:run` yet — that arrives in Task 12).

- [ ] **Step 11: Commit**

```bash
cd "/Users/vladislav/Desktop/Projects/TElegramm bot call/Telegraamm-bot-for-search-call"
git add -A
git commit -m "chore: scaffold Symfony 8 project with Docker and quality tooling"
```

---

### Task 1: Domain DTOs and enums

Pure value objects with no behaviour beyond construction. They are the shared vocabulary for every later task.

**Files:**
- Create: `src/Domain/Source.php`, `src/Domain/DealType.php`, `src/Domain/SellerMeta.php`, `src/Domain/Listing.php`, `src/Domain/PhoneOrigin.php`, `src/Domain/DetectedPhone.php`, `src/Domain/Classification.php`, `src/Domain/Confidence.php`, `src/Domain/Verdict.php`
- Test: `tests/Unit/Domain/ListingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Domain/ListingTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\DealType;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;
use App\Domain\SellerMeta;
use App\Domain\Source;
use PHPUnit\Framework\TestCase;

final class ListingTest extends TestCase
{
    public function testListingHoldsNormalisedFields(): void
    {
        $listing = new Listing(
            id: 'sreality:602509388',
            source: Source::SREALITY,
            title: 'Prodej bytu 3+1 89 m2',
            price: 8_500_000,
            dealType: DealType::SALE,
            location: 'Praha - Zbraslav',
            url: 'https://www.sreality.cz/detail/602509388',
            rawText: 'Volejte na 777 123 456',
            sellerMeta: new SellerMeta(hasPremise: false, totalListingCount: 1, name: 'Jan Novak'),
            structuredPhones: ['+420774956705'],
        );

        self::assertSame('sreality:602509388', $listing->id);
        self::assertSame(Source::SREALITY, $listing->source);
        self::assertSame(DealType::SALE, $listing->dealType);
        self::assertFalse($listing->sellerMeta?->hasPremise);
        self::assertSame(['+420774956705'], $listing->structuredPhones);
    }

    public function testListingWithoutSellerMetaIsAllowed(): void
    {
        $listing = new Listing(
            id: 'bezrealitky:1002810',
            source: Source::BEZREALITKY,
            title: 'Prodej bytu 2+kk',
            price: null,
            dealType: DealType::SALE,
            location: 'Praha 7 - Holesovice',
            url: 'https://www.bezrealitky.cz/nemovitosti-byty-domy/1002810',
            rawText: 'Bez realitky, primo od majitele',
            sellerMeta: null,
            structuredPhones: [],
        );

        self::assertNull($listing->sellerMeta);
        self::assertSame([], $listing->structuredPhones);
    }

    public function testDetectedPhoneCarriesOriginAndMarker(): void
    {
        $phone = new DetectedPhone(
            e164: '+420777123456',
            raw: '777 123 456',
            origin: PhoneOrigin::TEXT,
            marker: 'volejte',
        );

        self::assertSame('+420777123456', $phone->e164);
        self::assertSame(PhoneOrigin::TEXT, $phone->origin);
        self::assertSame('volejte', $phone->marker);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter ListingTest`
Expected: FAIL — `Class "App\Domain\Listing" not found`.

- [ ] **Step 3: Write the enums**

Create `src/Domain/Source.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

enum Source: string
{
    case SREALITY = 'sreality';
    case BEZREALITKY = 'bezrealitky';
}
```

Create `src/Domain/DealType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

enum DealType: string
{
    case SALE = 'sale';
    case RENT = 'rent';
}
```

Create `src/Domain/PhoneOrigin.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

enum PhoneOrigin: string
{
    case STRUCTURED = 'structured';
    case TEXT = 'text';
}
```

Create `src/Domain/Classification.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

enum Classification: string
{
    case OWNER = 'owner';
    case REALTOR = 'realtor';
    case UNKNOWN = 'unknown';
}
```

Create `src/Domain/Confidence.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

enum Confidence: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
}
```

- [ ] **Step 4: Write the DTOs**

Create `src/Domain/SellerMeta.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Seller metadata that only Sreality exposes. Bezrealitky listings carry null.
 */
final readonly class SellerMeta
{
    public function __construct(
        public bool $hasPremise,
        public ?int $totalListingCount,
        public ?string $name,
    ) {
    }
}
```

Create `src/Domain/Listing.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Listing
{
    /**
     * @param list<string> $structuredPhones E.164 numbers from a structured API field
     */
    public function __construct(
        public string $id,
        public Source $source,
        public string $title,
        public ?int $price,
        public DealType $dealType,
        public string $location,
        public string $url,
        public string $rawText,
        public ?SellerMeta $sellerMeta,
        public array $structuredPhones,
    ) {
    }

    /**
     * @param list<string> $structuredPhones
     */
    public function withDetails(string $rawText, ?SellerMeta $sellerMeta, array $structuredPhones): self
    {
        return new self(
            $this->id,
            $this->source,
            $this->title,
            $this->price,
            $this->dealType,
            $this->location,
            $this->url,
            $rawText,
            $sellerMeta,
            $structuredPhones,
        );
    }
}
```

Create `src/Domain/DetectedPhone.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class DetectedPhone
{
    public function __construct(
        public string $e164,
        public string $raw,
        public PhoneOrigin $origin,
        public ?string $marker,
    ) {
    }
}
```

Create `src/Domain/Verdict.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Verdict
{
    /**
     * @param list<string> $reasons human-readable signals that produced this verdict
     */
    public function __construct(
        public Classification $classification,
        public Confidence $confidence,
        public array $reasons,
    ) {
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter ListingTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan reports no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Domain tests/Unit/Domain
git commit -m "feat: add domain DTOs and enums"
```

---

### Task 2: PhoneDetector

Extracts Czech phone numbers from a listing — the core text-scanning component. Combines structured phones (already E.164) with numbers found in the description text.

**Files:**
- Create: `src/Phone/PhoneDetector.php`
- Test: `tests/Unit/Phone/PhoneDetectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Phone/PhoneDetectorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Phone;

use App\Domain\DealType;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;
use App\Domain\Source;
use App\Phone\PhoneDetector;
use PHPUnit\Framework\TestCase;

final class PhoneDetectorTest extends TestCase
{
    private function listing(string $rawText, array $structuredPhones = []): Listing
    {
        return new Listing(
            id: 'sreality:1',
            source: Source::SREALITY,
            title: 't',
            price: null,
            dealType: DealType::SALE,
            location: 'Praha',
            url: 'https://example.test/1',
            rawText: $rawText,
            sellerMeta: null,
            structuredPhones: $structuredPhones,
        );
    }

    public function testExtractsSpacedNumberFromText(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Volejte mi na 777 123 456 kdykoliv'));

        self::assertCount(1, $phones);
        self::assertSame('+420777123456', $phones[0]->e164);
        self::assertSame(PhoneOrigin::TEXT, $phones[0]->origin);
        self::assertSame('volejte', $phones[0]->marker);
    }

    public function testExtractsNumberWithExplicitPrefix(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('tel +420 608 444 111'));

        self::assertCount(1, $phones);
        self::assertSame('+420608444111', $phones[0]->e164);
        self::assertSame('tel', $phones[0]->marker);
    }

    public function testExtractsCompactNineDigitNumber(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('kontakt 731431957'));

        self::assertCount(1, $phones);
        self::assertSame('+420731431957', $phones[0]->e164);
    }

    public function testDoesNotMatchPrice(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Cena 8 500 000 Kc, k jednani'));

        self::assertSame([], $phones);
    }

    public function testDoesNotMatchCompactNumberFollowedByCurrency(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Cena 750000000 Kc'));

        self::assertSame([], $phones);
    }

    public function testStructuredPhonesAreIncludedAndDeduplicated(): void
    {
        $listing = $this->listing(
            rawText: 'Volejte na 774 956 705',
            structuredPhones: ['+420774956705'],
        );

        $phones = (new PhoneDetector())->detect($listing);

        self::assertCount(1, $phones);
        self::assertSame('+420774956705', $phones[0]->e164);
        self::assertSame(PhoneOrigin::STRUCTURED, $phones[0]->origin);
    }

    public function testMarkerIsNullWhenNoMarkerWordPrecedesNumber(): void
    {
        $phones = (new PhoneDetector())->detect($this->listing('Hezky byt, 777 123 456, sluneny'));

        self::assertCount(1, $phones);
        self::assertNull($phones[0]->marker);
    }

    public function testReturnsEmptyWhenNothingFound(): void
    {
        self::assertSame([], (new PhoneDetector())->detect($this->listing('Zadny kontakt zde')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter PhoneDetectorTest`
Expected: FAIL — `Class "App\Phone\PhoneDetector" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Phone/PhoneDetector.php`:
```php
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
 */
final class PhoneDetector
{
    private const PHONE_PATTERN =
        '/(?<!\d)(?:\+?420[\s\-]?|00420[\s\-]?)?([2-9]\d{2})[\s\-]?(\d{3})[\s\-]?(\d{3})(?!\d)/u';

    /** Marker words that, when they appear shortly before a number, mark it as a contact. */
    private const MARKERS = [
        'tel', 'telefon', 'mobil', 'mob', 'volejte', 'volat', 'zavolejte',
        'kontakt', 'cislo', 'na me', 'na mne',
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

        foreach ($this->scanText($listing->rawText) as $detected) {
            // Structured numbers win — keep the already-stored one if present.
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
            $trailing = substr($text, $offset + strlen($rawMatch), 6);
            if (preg_match('/^\s*(?:Kc|kc|K\x{010d}|k\x{010d}|,-)/u', $trailing) === 1) {
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

    private function markerBefore(string $text, int $offset): ?string
    {
        $window = strtolower(substr($text, max(0, $offset - 25), min($offset, 25)));

        foreach (self::MARKERS as $marker) {
            if (str_contains($window, $marker)) {
                return $marker;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter PhoneDetectorTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Phone tests/Unit/Phone
git commit -m "feat: add PhoneDetector"
```

---

### Task 3: Database (SQLite connection + schema)

Owns the SQLite connection and creates the schema. `SeenStore` and `ContactRegistry` both depend on it.

**Files:**
- Create: `src/Persistence/Database.php`
- Test: `tests/Unit/Persistence/DatabaseTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Persistence/DatabaseTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Persistence;

use App\Persistence\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testMigrateCreatesAllTables(): void
    {
        $database = new Database(':memory:');
        $database->migrate();

        $tables = $database->pdo()
            ->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['contact_evidence', 'contacts', 'seen_listings'], $tables);
    }

    public function testMigrateIsIdempotent(): void
    {
        $database = new Database(':memory:');
        $database->migrate();
        $database->migrate(); // must not throw

        $count = $database->pdo()
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")
            ->fetchColumn();

        self::assertSame(3, (int) $count);
    }

    public function testPdoReturnsSameConnection(): void
    {
        $database = new Database(':memory:');

        self::assertSame($database->pdo(), $database->pdo());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter DatabaseTest`
Expected: FAIL — `Class "App\Persistence\Database" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Persistence/Database.php`:
```php
<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * Owns the single SQLite connection and the schema.
 *
 * $databasePath is either an absolute file path or ':memory:' (tests).
 */
final class Database
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $databasePath,
    ) {
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO('sqlite:' . $this->databasePath);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $this->pdo;
    }

    public function migrate(): void
    {
        $pdo = $this->pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS seen_listings (
                listing_id    TEXT PRIMARY KEY,
                source        TEXT NOT NULL,
                first_seen_at TEXT NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS contacts (
                phone_e164    TEXT PRIMARY KEY,
                verdict       TEXT,
                confidence    TEXT,
                first_seen_at TEXT NOT NULL,
                updated_at    TEXT NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS contact_evidence (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_e164 TEXT NOT NULL,
                listing_id TEXT NOT NULL,
                source     TEXT NOT NULL,
                name       TEXT,
                seen_at    TEXT NOT NULL,
                UNIQUE (phone_e164, listing_id)
            )
            SQL);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter DatabaseTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/Database.php tests/Unit/Persistence/DatabaseTest.php
git commit -m "feat: add SQLite Database with schema migration"
```

---

### Task 4: SeenStore

Tracks which listing IDs have already been processed, so the bot never sends a listing twice. Also exposes `count()` so the runner can detect a first run.

**Files:**
- Create: `src/Persistence/SeenStore.php`
- Test: `tests/Unit/Persistence/SeenStoreTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Persistence/SeenStoreTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Persistence;

use App\Domain\Source;
use App\Persistence\Database;
use App\Persistence\SeenStore;
use PHPUnit\Framework\TestCase;

final class SeenStoreTest extends TestCase
{
    private function store(): SeenStore
    {
        $database = new Database(':memory:');
        $database->migrate();

        return new SeenStore($database);
    }

    public function testUnknownListingIsNotSeen(): void
    {
        self::assertFalse($this->store()->isSeen('sreality:1'));
    }

    public function testMarkedListingIsSeen(): void
    {
        $store = $this->store();
        $store->markSeen('sreality:1', Source::SREALITY);

        self::assertTrue($store->isSeen('sreality:1'));
    }

    public function testMarkSeenIsIdempotent(): void
    {
        $store = $this->store();
        $store->markSeen('sreality:1', Source::SREALITY);
        $store->markSeen('sreality:1', Source::SREALITY); // must not throw

        self::assertSame(1, $store->count());
    }

    public function testCountReflectsStoredListings(): void
    {
        $store = $this->store();
        self::assertSame(0, $store->count());

        $store->markSeen('sreality:1', Source::SREALITY);
        $store->markSeen('bezrealitky:2', Source::BEZREALITKY);

        self::assertSame(2, $store->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter SeenStoreTest`
Expected: FAIL — `Class "App\Persistence\SeenStore" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Persistence/SeenStore.php`:
```php
<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Domain\Source;

/**
 * The set of listing IDs the bot has already processed. Deduplication lives here.
 */
final class SeenStore
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function isSeen(string $listingId): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT 1 FROM seen_listings WHERE listing_id = :id',
        );
        $statement->execute(['id' => $listingId]);

        return $statement->fetchColumn() !== false;
    }

    public function markSeen(string $listingId, Source $source): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT OR IGNORE INTO seen_listings (listing_id, source, first_seen_at)
             VALUES (:id, :source, :now)',
        );
        $statement->execute([
            'id' => $listingId,
            'source' => $source->value,
            'now' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function count(): int
    {
        return (int) $this->database->pdo()
            ->query('SELECT COUNT(*) FROM seen_listings')
            ->fetchColumn();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter SeenStoreTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/SeenStore.php tests/Unit/Persistence/SeenStoreTest.php
git commit -m "feat: add SeenStore for listing deduplication"
```

---

### Task 5: ContactRegistry

Stores every phone number as an accumulating record of evidence (which listings/sites it appeared on, names seen) plus a verdict. This is what makes "classify the contact, not the listing" possible.

**Files:**
- Create: `src/Persistence/ContactRegistry.php`
- Test: `tests/Unit/Persistence/ContactRegistryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Persistence/ContactRegistryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Persistence;

use App\Domain\Classification;
use App\Domain\Confidence;
use App\Domain\Source;
use App\Persistence\ContactRegistry;
use App\Persistence\Database;
use PHPUnit\Framework\TestCase;

final class ContactRegistryTest extends TestCase
{
    private function registry(): ContactRegistry
    {
        $database = new Database(':memory:');
        $database->migrate();

        return new ContactRegistry($database);
    }

    public function testRecordEvidenceCountsDistinctListings(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, 'Jan');
        $registry->recordEvidence('+420777123456', 'sreality:2', Source::SREALITY, 'Jan');

        self::assertSame(2, $registry->listingCount('+420777123456'));
    }

    public function testRecordEvidenceIsIdempotentPerListing(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, 'Jan');
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, 'Jan');

        self::assertSame(1, $registry->listingCount('+420777123456'));
    }

    public function testSiteCountCountsDistinctSources(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, null);
        $registry->recordEvidence('+420777123456', 'sreality:2', Source::SREALITY, null);
        $registry->recordEvidence('+420777123456', 'bezrealitky:9', Source::BEZREALITKY, null);

        self::assertSame(2, $registry->siteCount('+420777123456'));
    }

    public function testVerdictIsNullUntilSet(): void
    {
        self::assertNull($this->registry()->getVerdict('+420777123456'));
    }

    public function testSetVerdictPersistsClassification(): void
    {
        $registry = $this->registry();
        $registry->recordEvidence('+420777123456', 'sreality:1', Source::SREALITY, null);
        $registry->setVerdict('+420777123456', Classification::REALTOR, Confidence::HIGH);

        self::assertSame(Classification::REALTOR, $registry->getVerdict('+420777123456'));
    }

    public function testSetVerdictWorksEvenWithoutPriorEvidence(): void
    {
        $registry = $this->registry();
        $registry->setVerdict('+420777123456', Classification::OWNER, Confidence::MEDIUM);

        self::assertSame(Classification::OWNER, $registry->getVerdict('+420777123456'));
    }

    public function testUnknownPhoneHasZeroCounts(): void
    {
        $registry = $this->registry();

        self::assertSame(0, $registry->listingCount('+420000000000'));
        self::assertSame(0, $registry->siteCount('+420000000000'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter ContactRegistryTest`
Expected: FAIL — `Class "App\Persistence\ContactRegistry" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Persistence/ContactRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Domain\Classification;
use App\Domain\Confidence;
use App\Domain\Source;

/**
 * Accumulates evidence about phone numbers across listings and sites, and stores
 * the verdict for each number. The verdict is on the contact, not the listing.
 */
final class ContactRegistry
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function recordEvidence(string $phoneE164, string $listingId, Source $source, ?string $name): void
    {
        $pdo = $this->database->pdo();
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $contact = $pdo->prepare(
            'INSERT INTO contacts (phone_e164, verdict, confidence, first_seen_at, updated_at)
             VALUES (:phone, NULL, NULL, :now, :now)
             ON CONFLICT (phone_e164) DO UPDATE SET updated_at = :now',
        );
        $contact->execute(['phone' => $phoneE164, 'now' => $now]);

        $evidence = $pdo->prepare(
            'INSERT OR IGNORE INTO contact_evidence (phone_e164, listing_id, source, name, seen_at)
             VALUES (:phone, :listing, :source, :name, :now)',
        );
        $evidence->execute([
            'phone' => $phoneE164,
            'listing' => $listingId,
            'source' => $source->value,
            'name' => $name,
            'now' => $now,
        ]);
    }

    public function listingCount(string $phoneE164): int
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(DISTINCT listing_id) FROM contact_evidence WHERE phone_e164 = :phone',
        );
        $statement->execute(['phone' => $phoneE164]);

        return (int) $statement->fetchColumn();
    }

    public function siteCount(string $phoneE164): int
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(DISTINCT source) FROM contact_evidence WHERE phone_e164 = :phone',
        );
        $statement->execute(['phone' => $phoneE164]);

        return (int) $statement->fetchColumn();
    }

    public function getVerdict(string $phoneE164): ?Classification
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT verdict FROM contacts WHERE phone_e164 = :phone',
        );
        $statement->execute(['phone' => $phoneE164]);

        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            return null;
        }

        return Classification::from($value);
    }

    public function setVerdict(string $phoneE164, Classification $classification, Confidence $confidence): void
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO contacts (phone_e164, verdict, confidence, first_seen_at, updated_at)
             VALUES (:phone, :verdict, :confidence, :now, :now)
             ON CONFLICT (phone_e164)
             DO UPDATE SET verdict = :verdict, confidence = :confidence, updated_at = :now',
        );
        $statement->execute([
            'phone' => $phoneE164,
            'verdict' => $classification->value,
            'confidence' => $confidence->value,
            'now' => $now,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter ContactRegistryTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Persistence/ContactRegistry.php tests/Unit/Persistence/ContactRegistryTest.php
git commit -m "feat: add ContactRegistry for phone-number evidence"
```

---

### Task 6: TieredAdvertiserClassifier

The owner-vs-realtor funnel. Tier 0 excludes obvious realtors, Tier 1 includes obvious owners, Tier 2 cross-references the contact registry. First tier to produce a verdict wins; anything left over is `UNKNOWN`. The interface ships so a Tier 3 LLM classifier can be added later without touching callers.

**Files:**
- Create: `src/Classification/AdvertiserClassifier.php`, `src/Classification/TieredAdvertiserClassifier.php`
- Test: `tests/Unit/Classification/TieredAdvertiserClassifierTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Classification/TieredAdvertiserClassifierTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Classification;

use App\Classification\TieredAdvertiserClassifier;
use App\Domain\Classification;
use App\Domain\DealType;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;
use App\Domain\SellerMeta;
use App\Domain\Source;
use App\Persistence\ContactRegistry;
use App\Persistence\Database;
use PHPUnit\Framework\TestCase;

final class TieredAdvertiserClassifierTest extends TestCase
{
    private ContactRegistry $registry;
    private TieredAdvertiserClassifier $classifier;

    protected function setUp(): void
    {
        $database = new Database(':memory:');
        $database->migrate();
        $this->registry = new ContactRegistry($database);
        $this->classifier = new TieredAdvertiserClassifier($this->registry);
    }

    private function listing(string $rawText, ?SellerMeta $sellerMeta): Listing
    {
        return new Listing(
            id: 'sreality:1',
            source: Source::SREALITY,
            title: 't',
            price: null,
            dealType: DealType::SALE,
            location: 'Praha',
            url: 'https://example.test/1',
            rawText: $rawText,
            sellerMeta: $sellerMeta,
            structuredPhones: [],
        );
    }

    private function phone(string $e164): DetectedPhone
    {
        return new DetectedPhone($e164, $e164, PhoneOrigin::TEXT, null);
    }

    public function testTier0PremiseIsRealtor(): void
    {
        $listing = $this->listing('hezky byt', new SellerMeta(hasPremise: true, totalListingCount: 1, name: 'RK'));

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testTier0HighSellerCountIsRealtor(): void
    {
        $listing = $this->listing('hezky byt', new SellerMeta(hasPremise: false, totalListingCount: 9, name: 'Jan'));

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testTier0KnownRealtorNumberIsRealtor(): void
    {
        $this->registry->setVerdict('+420777123456', Classification::REALTOR, \App\Domain\Confidence::HIGH);
        $listing = $this->listing('hezky byt', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testTier1OwnerSelfIdPhraseIsOwner(): void
    {
        $listing = $this->listing('Prodej primo od majitele, RK nevolat', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::OWNER, $verdict->classification);
        self::assertSame(\App\Domain\Confidence::HIGH, $verdict->confidence);
    }

    public function testTier1SingleListingSellerIsOwner(): void
    {
        $listing = $this->listing('hezky byt', new SellerMeta(hasPremise: false, totalListingCount: 1, name: 'Jan'));

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::OWNER, $verdict->classification);
    }

    public function testTier2FrequentNumberIsRealtor(): void
    {
        foreach (['sreality:10', 'sreality:11', 'sreality:12'] as $listingId) {
            $this->registry->recordEvidence('+420777123456', $listingId, Source::SREALITY, null);
        }
        $listing = $this->listing('hezky byt', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
        self::assertSame(Classification::REALTOR, $this->registry->getVerdict('+420777123456'));
    }

    public function testTier2CrossSiteNumberIsRealtor(): void
    {
        $this->registry->recordEvidence('+420777123456', 'sreality:10', Source::SREALITY, null);
        $this->registry->recordEvidence('+420777123456', 'bezrealitky:20', Source::BEZREALITKY, null);
        $listing = $this->listing('hezky byt', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testTier2RealtorLanguageIsRealtor(): void
    {
        $listing = $this->listing('Nase realitni kancelar nabizi, provize v cene', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::REALTOR, $verdict->classification);
    }

    public function testUnclassifiedListingIsUnknown(): void
    {
        $listing = $this->listing('Hezky slunny byt v klidne lokalite', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::UNKNOWN, $verdict->classification);
    }

    public function testOwnerSelfIdBeatsRealtorLanguage(): void
    {
        // Tier 1 runs before Tier 2: owner self-ID wins even if realtor words also appear.
        $listing = $this->listing('Provize zadna, prodej primo od majitele', null);

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertSame(Classification::OWNER, $verdict->classification);
    }

    public function testVerdictCarriesReasons(): void
    {
        $listing = $this->listing('hezky byt', new SellerMeta(hasPremise: true, totalListingCount: 1, name: 'RK'));

        $verdict = $this->classifier->classify($listing, [$this->phone('+420777123456')]);

        self::assertNotEmpty($verdict->reasons);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter TieredAdvertiserClassifierTest`
Expected: FAIL — `Class "App\Classification\TieredAdvertiserClassifier" not found`.

- [ ] **Step 3: Write the interface**

Create `src/Classification/AdvertiserClassifier.php`:
```php
<?php

declare(strict_types=1);

namespace App\Classification;

use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\Verdict;

interface AdvertiserClassifier
{
    /**
     * @param list<DetectedPhone> $phones phones already extracted from the listing
     */
    public function classify(Listing $listing, array $phones): Verdict;
}
```

- [ ] **Step 4: Write the implementation**

Create `src/Classification/TieredAdvertiserClassifier.php`:
```php
<?php

declare(strict_types=1);

namespace App\Classification;

use App\Domain\Classification;
use App\Domain\Confidence;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\Verdict;
use App\Persistence\ContactRegistry;

/**
 * Owner-vs-realtor classification as a deterministic funnel:
 *  - Tier 0 excludes obvious realtors
 *  - Tier 1 includes obvious owners
 *  - Tier 2 cross-references the ContactRegistry
 * The first tier to produce a verdict wins. Anything left over is UNKNOWN.
 */
final class TieredAdvertiserClassifier implements AdvertiserClassifier
{
    /** Phrases owners use to identify themselves (matched case-insensitively, accent-stripped). */
    private const OWNER_PHRASES = [
        'rk nevolat', 'bez rk', 'bez realitky', 'bez provize',
        'primo od majitele', 'soukromy prodej', 'makleri nevolejte',
    ];

    /** Phrases that indicate a realtor wrote the listing. */
    private const REALTOR_PHRASES = [
        'makler', 'realitni kancelar', 'provize', 'zprostredkovani', 'nase kancelar',
    ];

    private const FREQUENT_LISTING_THRESHOLD = 3;
    private const CROSS_SITE_THRESHOLD = 2;
    private const HIGH_SELLER_COUNT_THRESHOLD = 2;

    public function __construct(
        private readonly ContactRegistry $registry,
    ) {
    }

    public function classify(Listing $listing, array $phones): Verdict
    {
        $haystack = $this->normalise($listing->rawText);

        return $this->tier0($listing, $phones)
            ?? $this->tier1($listing, $haystack)
            ?? $this->tier2($listing, $phones, $haystack)
            ?? new Verdict(Classification::UNKNOWN, Confidence::LOW, ['no decisive signal']);
    }

    /**
     * @param list<DetectedPhone> $phones
     */
    private function tier0(Listing $listing, array $phones): ?Verdict
    {
        $meta = $listing->sellerMeta;
        if ($meta !== null && $meta->hasPremise) {
            return new Verdict(Classification::REALTOR, Confidence::HIGH, ['seller has a premise (agency)']);
        }

        if ($meta !== null && $meta->totalListingCount !== null
            && $meta->totalListingCount > self::HIGH_SELLER_COUNT_THRESHOLD) {
            return new Verdict(
                Classification::REALTOR,
                Confidence::HIGH,
                [sprintf('seller has %d listings', $meta->totalListingCount)],
            );
        }

        foreach ($phones as $phone) {
            if ($this->registry->getVerdict($phone->e164) === Classification::REALTOR) {
                return new Verdict(
                    Classification::REALTOR,
                    Confidence::HIGH,
                    [sprintf('%s is a known realtor number', $phone->e164)],
                );
            }
        }

        return null;
    }

    private function tier1(Listing $listing, string $haystack): ?Verdict
    {
        foreach (self::OWNER_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return new Verdict(
                    Classification::OWNER,
                    Confidence::HIGH,
                    [sprintf('owner self-identification: "%s"', $phrase)],
                );
            }
        }

        $meta = $listing->sellerMeta;
        if ($meta !== null && !$meta->hasPremise && $meta->totalListingCount === 1) {
            return new Verdict(
                Classification::OWNER,
                Confidence::HIGH,
                ['seller has exactly one listing and no premise'],
            );
        }

        return null;
    }

    /**
     * @param list<DetectedPhone> $phones
     */
    private function tier2(Listing $listing, array $phones, string $haystack): ?Verdict
    {
        foreach ($phones as $phone) {
            $listingCount = $this->registry->listingCount($phone->e164);
            $siteCount = $this->registry->siteCount($phone->e164);

            if ($listingCount >= self::FREQUENT_LISTING_THRESHOLD || $siteCount >= self::CROSS_SITE_THRESHOLD) {
                $this->registry->setVerdict($phone->e164, Classification::REALTOR, Confidence::MEDIUM);

                return new Verdict(
                    Classification::REALTOR,
                    Confidence::MEDIUM,
                    [sprintf(
                        '%s seen on %d listings across %d sites',
                        $phone->e164,
                        $listingCount,
                        $siteCount,
                    )],
                );
            }
        }

        foreach (self::REALTOR_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return new Verdict(
                    Classification::REALTOR,
                    Confidence::MEDIUM,
                    [sprintf('realtor language: "%s"', $phrase)],
                );
            }
        }

        return null;
    }

    private function normalise(string $text): string
    {
        $lower = mb_strtolower($text);
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT', $lower);

        return $stripped === false ? $lower : $stripped;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter TieredAdvertiserClassifierTest`
Expected: PASS (11 tests).

- [ ] **Step 6: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Classification tests/Unit/Classification
git commit -m "feat: add tiered advertiser classifier"
```

---

### Task 7: ListingSource interface + SrealityClient

Defines the source contract and implements Sreality: a cheap list call returns shallow listings; `hydrate` fetches the detail endpoint to fill in description text, seller metadata, and structured phones.

**Files:**
- Create: `src/Source/ListingSource.php`, `src/Source/Sreality/SrealityClient.php`
- Test: `tests/Unit/Source/Sreality/SrealityClientTest.php`
- Fixtures: `tests/Fixtures/sreality_list.json`, `tests/Fixtures/sreality_detail_private.json`, `tests/Fixtures/sreality_detail_agency.json`

- [ ] **Step 1: Create the fixtures**

Create `tests/Fixtures/sreality_list.json` (minimal shape of the Sreality list endpoint):
```json
{
  "result_size": 2,
  "_embedded": {
    "estates": [
      {
        "hash_id": 111,
        "name": "Prodej bytu 2+kk 50 m2",
        "locality": "Praha 7 - Holesovice",
        "price": 7500000,
        "seo": { "locality": "praha-7-holesovice" }
      },
      {
        "hash_id": 222,
        "name": "Prodej bytu 3+1 80 m2",
        "locality": "Praha 5 - Smichov",
        "price": 9900000,
        "seo": { "locality": "praha-5-smichov" }
      }
    ]
  }
}
```

Create `tests/Fixtures/sreality_detail_private.json` (a private seller — no premise, one listing):
```json
{
  "name": "Prodej bytu 2+kk 50 m2",
  "text": "Prodam slunny byt 2+kk. Volejte na 777 123 456, jsem majitel.",
  "_embedded": {
    "seller": {
      "user_name": "Jan Novak",
      "phones": [ { "code": "420", "type": "MOB", "number": "777123456" } ],
      "specialization": { "category": [ { "category_main_cb": 1, "num": 1 } ] }
    }
  }
}
```

Create `tests/Fixtures/sreality_detail_agency.json` (an agency — has a premise):
```json
{
  "name": "Prodej bytu 3+1 80 m2",
  "text": "Nabizime k prodeji byt 3+1. Kontaktujte naseho maklere.",
  "_embedded": {
    "seller": {
      "user_name": "Lukas Helesic",
      "phones": [ { "code": "420", "type": "MOB", "number": "774956705" } ],
      "specialization": { "category": [ { "category_main_cb": 1, "num": 9 } ] },
      "_embedded": { "premise": { "name": "Qara s.r.o." } }
    }
  }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Source/Sreality/SrealityClientTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source\Sreality;

use App\Domain\DealType;
use App\Domain\Source;
use App\Source\Sreality\SrealityClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SrealityClientTest extends TestCase
{
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    public function testFetchRecentListingsMapsShallowListings(): void
    {
        $http = new MockHttpClient([new MockResponse($this->fixture('sreality_list.json'))]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $listings = $client->fetchRecentListings();

        self::assertCount(2, $listings);
        self::assertSame('sreality:111', $listings[0]->id);
        self::assertSame(Source::SREALITY, $listings[0]->source);
        self::assertSame(DealType::SALE, $listings[0]->dealType);
        self::assertSame('Praha 7 - Holesovice', $listings[0]->location);
        self::assertStringContainsString('111', $listings[0]->url);
    }

    public function testFetchRecentListingsCombinesSaleAndRent(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_list.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale,rent');

        self::assertCount(4, $client->fetchRecentListings());
    }

    public function testHydratePrivateListingFillsSellerMetaAndPhones(): void
    {
        $http = new MockHttpClient([
            new MockResponse($this->fixture('sreality_list.json')),
            new MockResponse($this->fixture('sreality_detail_private.json')),
        ]);
        $client = new SrealityClient($http, new NullLogger(), 'praha', 'sale');

        $shallow = $client->fetchRecentListings()[0];
        $hydrated = $client->hydrate($shallow);

        self::assertStringContainsString('777 123 456', $hydrated->rawText);
        self::assertNotNull($hydrated->sellerMeta);
        self::assertFalse($hydrated->sellerMeta->hasPremise);
        self::assertSame(1, $hydrated->sellerMeta->totalListingCount);
        self::assertSame('Jan Novak', $hydrated->sellerMeta->name);
        self::assertSame(['+420777123456'], $hydrated->structuredPhones);
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

        self::assertNotNull($hydrated->sellerMeta);
        self::assertTrue($hydrated->sellerMeta->hasPremise);
        self::assertSame(9, $hydrated->sellerMeta->totalListingCount);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter SrealityClientTest`
Expected: FAIL — `Class "App\Source\Sreality\SrealityClient" not found`.

- [ ] **Step 4: Write the interface**

Create `src/Source/ListingSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Source;

use App\Domain\Listing;

interface ListingSource
{
    /**
     * Cheap call: returns shallow listings (rawText/sellerMeta/structuredPhones may be empty).
     *
     * @return list<Listing>
     */
    public function fetchRecentListings(): array;

    /**
     * Fills rawText, sellerMeta and structuredPhones. May perform an HTTP call.
     * For sources whose list call already returns everything, this returns the listing unchanged.
     */
    public function hydrate(Listing $listing): Listing;
}
```

- [ ] **Step 5: Write the implementation**

Create `src/Source/Sreality/SrealityClient.php`:
```php
<?php

declare(strict_types=1);

namespace App\Source\Sreality;

use App\Domain\DealType;
use App\Domain\Listing;
use App\Domain\SellerMeta;
use App\Domain\Source;
use App\Source\ListingSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sreality source. The list endpoint returns shallow listings; hydrate() fetches
 * the detail endpoint, which is the only place description text, seller metadata
 * and structured phones are exposed.
 */
final class SrealityClient implements ListingSource
{
    private const LIST_URL = 'https://www.sreality.cz/api/cs/v2/estates';
    private const DETAIL_URL = 'https://www.sreality.cz/api/cs/v2/estates/';
    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /** Sreality region id for Prague (verified in reconnaissance). */
    private const REGION_IDS = ['praha' => 10];

    /** Sreality category_type_cb codes. */
    private const DEAL_TYPE_CODES = ['sale' => 1, 'rent' => 2];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $monitorRegion,
        private readonly string $monitorDealTypes,
    ) {
    }

    public function fetchRecentListings(): array
    {
        $regionId = self::REGION_IDS[$this->monitorRegion] ?? self::REGION_IDS['praha'];
        $listings = [];

        foreach ($this->dealTypes() as $dealType) {
            $query = http_build_query([
                'category_main_cb' => 1,
                'category_type_cb' => self::DEAL_TYPE_CODES[$dealType->value],
                'locality_region_id' => $regionId,
                'per_page' => 60,
                'sort' => 'date',
            ]);

            $this->logger->info('Sreality list request', ['query' => $query]);

            $data = $this->httpClient
                ->request('GET', self::LIST_URL . '?' . $query, $this->options())
                ->toArray();

            $embedded = is_array($data['_embedded'] ?? null) ? $data['_embedded'] : [];
            $estates = is_array($embedded['estates'] ?? null) ? $embedded['estates'] : [];

            foreach ($estates as $estate) {
                if (is_array($estate)) {
                    $listings[] = $this->mapShallow($estate, $dealType);
                }
            }
        }

        return $listings;
    }

    public function hydrate(Listing $listing): Listing
    {
        $hashId = substr($listing->id, strlen('sreality:'));

        $this->logger->info('Sreality detail request', ['hash_id' => $hashId]);

        $data = $this->httpClient
            ->request('GET', self::DETAIL_URL . $hashId, $this->options())
            ->toArray();

        $text = is_string($data['text'] ?? null) ? $data['text'] : '';
        $seller = $this->extractSeller($data);

        return $listing->withDetails(
            rawText: $text,
            sellerMeta: $seller['meta'],
            structuredPhones: $seller['phones'],
        );
    }

    /**
     * @return list<DealType>
     */
    private function dealTypes(): array
    {
        $types = [];
        foreach (explode(',', $this->monitorDealTypes) as $raw) {
            $type = DealType::tryFrom(trim($raw));
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types === [] ? [DealType::SALE] : $types;
    }

    /**
     * @param array<mixed, mixed> $estate
     */
    private function mapShallow(array $estate, DealType $dealType): Listing
    {
        $hashId = is_int($estate['hash_id'] ?? null) ? $estate['hash_id'] : 0;
        $name = is_string($estate['name'] ?? null) ? $estate['name'] : '';
        $locality = is_string($estate['locality'] ?? null) ? $estate['locality'] : '';
        $price = is_int($estate['price'] ?? null) ? $estate['price'] : null;

        return new Listing(
            id: 'sreality:' . $hashId,
            source: Source::SREALITY,
            title: $name,
            price: $price,
            dealType: $dealType,
            location: $locality,
            url: 'https://www.sreality.cz/detail/' . $hashId,
            rawText: '',
            sellerMeta: null,
            structuredPhones: [],
        );
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array{meta: ?SellerMeta, phones: list<string>}
     */
    private function extractSeller(array $data): array
    {
        $embedded = is_array($data['_embedded'] ?? null) ? $data['_embedded'] : [];
        $seller = is_array($embedded['seller'] ?? null) ? $embedded['seller'] : null;

        if ($seller === null) {
            return ['meta' => null, 'phones' => []];
        }

        $sellerEmbedded = is_array($seller['_embedded'] ?? null) ? $seller['_embedded'] : [];
        $hasPremise = isset($sellerEmbedded['premise']);

        $name = is_string($seller['user_name'] ?? null) ? $seller['user_name'] : null;

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

        $phones = [];
        $rawPhones = is_array($seller['phones'] ?? null) ? $seller['phones'] : [];
        foreach ($rawPhones as $phone) {
            if (!is_array($phone)) {
                continue;
            }
            $code = is_string($phone['code'] ?? null) ? $phone['code'] : '420';
            $number = is_string($phone['number'] ?? null) ? $phone['number'] : '';
            if ($number !== '') {
                $phones['+' . $code . $number] = true;
            }
        }

        return [
            'meta' => new SellerMeta($hasPremise, $totalListingCount, $name),
            'phones' => array_keys($phones),
        ];
    }

    /**
     * @return array{headers: array<string, string>}
     */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter SrealityClientTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Source tests/Unit/Source/Sreality tests/Fixtures
git commit -m "feat: add ListingSource interface and SrealityClient"
```

---

### Task 8: BezrealitkyClient

Implements Bezrealitky over GraphQL. Unlike Sreality, the list query already returns the description, so `fetchRecentListings` returns full listings and `hydrate` is the identity.

**Files:**
- Create: `src/Source/Bezrealitky/BezrealitkyClient.php`
- Test: `tests/Unit/Source/Bezrealitky/BezrealitkyClientTest.php`
- Fixtures: `tests/Fixtures/bezrealitky_list.json`

- [ ] **Step 1: Create the fixture**

Create `tests/Fixtures/bezrealitky_list.json` (minimal shape of the `listAdverts` GraphQL response):
```json
{
  "data": {
    "listAdverts": {
      "totalCount": 2,
      "list": [
        {
          "id": "1002810",
          "uri": "byt-2-kk-praha-argentinska",
          "title": "Prodej bytu 2+kk",
          "address": "Argentinska, Praha - Holesovice",
          "price": 7500000,
          "offerType": "PRODEJ",
          "description": "Prostorny byt 2+kk. Bez realitky, volejte na 608 444 111."
        },
        {
          "id": "1002811",
          "uri": "byt-1-kk-praha-zizkov",
          "title": "Pronajem bytu 1+kk",
          "address": "Zizkov, Praha 3",
          "price": 18000,
          "offerType": "PRONAJEM",
          "description": "Pekny byt k pronajmu."
        }
      ]
    }
  }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Source/Bezrealitky/BezrealitkyClientTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source\Bezrealitky;

use App\Domain\DealType;
use App\Domain\Source;
use App\Source\Bezrealitky\BezrealitkyClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BezrealitkyClientTest extends TestCase
{
    private function fixture(): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/bezrealitky_list.json');
        self::assertIsString($contents);

        return $contents;
    }

    public function testFetchRecentListingsMapsFullListings(): void
    {
        $http = new MockHttpClient([new MockResponse($this->fixture())]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent');

        $listings = $client->fetchRecentListings();

        self::assertCount(2, $listings);
        self::assertSame('bezrealitky:1002810', $listings[0]->id);
        self::assertSame(Source::BEZREALITKY, $listings[0]->source);
        self::assertSame(DealType::SALE, $listings[0]->dealType);
        self::assertSame(DealType::RENT, $listings[1]->dealType);
        self::assertStringContainsString('608 444 111', $listings[0]->rawText);
        self::assertStringContainsString('bezrealitky.cz', $listings[0]->url);
        self::assertNull($listings[0]->sellerMeta);
    }

    public function testHydrateIsIdentity(): void
    {
        $http = new MockHttpClient([new MockResponse($this->fixture())]);
        $client = new BezrealitkyClient($http, new NullLogger(), 'praha', 'sale,rent');

        $listing = $client->fetchRecentListings()[0];

        self::assertSame($listing, $client->hydrate($listing));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter BezrealitkyClientTest`
Expected: FAIL — `Class "App\Source\Bezrealitky\BezrealitkyClient" not found`.

- [ ] **Step 4: Write the implementation**

Create `src/Source/Bezrealitky/BezrealitkyClient.php`:
```php
<?php

declare(strict_types=1);

namespace App\Source\Bezrealitky;

use App\Domain\DealType;
use App\Domain\Listing;
use App\Domain\Source;
use App\Source\ListingSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bezrealitky source over GraphQL. The list query already returns the full
 * description, so hydrate() is the identity. Bezrealitky exposes no structured
 * phone or seller metadata unauthenticated, so structuredPhones is always empty
 * and sellerMeta is always null — phone numbers come purely from the text.
 */
final class BezrealitkyClient implements ListingSource
{
    private const GRAPHQL_URL = 'https://api.bezrealitky.cz/graphql/';
    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /** Bezrealitky internal region id for Prague (from the reference project's verified mapping). */
    private const REGION_IDS = ['praha' => '486'];

    private const DEAL_TYPE_CODES = ['sale' => 'PRODEJ', 'rent' => 'PRONAJEM'];

    private const QUERY = <<<'GQL'
        query AdvertList($offerType: [OfferType], $regionId: ID, $limit: Int, $order: ResultOrder) {
            listAdverts(offerType: $offerType, estateType: [BYT], regionId: $regionId, limit: $limit, order: $order) {
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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $monitorRegion,
        private readonly string $monitorDealTypes,
    ) {
    }

    public function fetchRecentListings(): array
    {
        $regionId = self::REGION_IDS[$this->monitorRegion] ?? self::REGION_IDS['praha'];

        $variables = [
            'offerType' => $this->offerTypes(),
            'regionId' => $regionId,
            'limit' => 60,
            'order' => 'TIMEORDER_DESC',
        ];

        $this->logger->info('Bezrealitky GraphQL request', ['variables' => $variables]);

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

        $listAdverts = is_array($data['data']['listAdverts'] ?? null) ? $data['data']['listAdverts'] : [];
        $rawList = is_array($listAdverts['list'] ?? null) ? $listAdverts['list'] : [];

        $listings = [];
        foreach ($rawList as $item) {
            if (is_array($item)) {
                $listings[] = $this->map($item);
            }
        }

        return $listings;
    }

    public function hydrate(Listing $listing): Listing
    {
        return $listing; // the list query already returned everything we can get
    }

    /**
     * @return list<string>
     */
    private function offerTypes(): array
    {
        $codes = [];
        foreach (explode(',', $this->monitorDealTypes) as $raw) {
            $code = self::DEAL_TYPE_CODES[trim($raw)] ?? null;
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $codes === [] ? [self::DEAL_TYPE_CODES['sale']] : $codes;
    }

    /**
     * @param array<mixed, mixed> $item
     */
    private function map(array $item): Listing
    {
        $id = is_string($item['id'] ?? null) ? $item['id'] : (string) ($item['id'] ?? '');
        $uri = is_string($item['uri'] ?? null) ? $item['uri'] : '';
        $title = is_string($item['title'] ?? null) ? $item['title'] : '';
        $address = is_string($item['address'] ?? null) ? $item['address'] : '';
        $price = is_int($item['price'] ?? null) ? $item['price'] : null;
        $description = is_string($item['description'] ?? null) ? $item['description'] : '';
        $offerType = is_string($item['offerType'] ?? null) ? $item['offerType'] : 'PRODEJ';

        return new Listing(
            id: 'bezrealitky:' . $id,
            source: Source::BEZREALITKY,
            title: $title,
            price: $price,
            dealType: $offerType === 'PRONAJEM' ? DealType::RENT : DealType::SALE,
            location: $address,
            url: 'https://www.bezrealitky.cz/nemovitosti-byty-domy/' . $uri,
            rawText: $description,
            sellerMeta: null,
            structuredPhones: [],
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter BezrealitkyClientTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Verify the Prague region id against the live API**

Run:
```bash
curl -s -X POST 'https://api.bezrealitky.cz/graphql/' \
  -H 'Content-Type: application/json' \
  -d '{"query":"query{listAdverts(offerType:[PRODEJ],estateType:[BYT],regionId:\"486\",limit:3,order:TIMEORDER_DESC){list{address(locale:CS)}}}"}'
```
Expected: addresses are in Prague. If they are not, query `listRegions(locale: CS, cached: true)` to find the correct Prague id and update `BezrealitkyClient::REGION_IDS`.

- [ ] **Step 7: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Source/Bezrealitky tests/Unit/Source/Bezrealitky tests/Fixtures/bezrealitky_list.json
git commit -m "feat: add BezrealitkyClient"
```

---

### Task 9: MessageFormatter

Turns a classified listing into the Telegram HTML message the operator receives.

**Files:**
- Create: `src/Notification/MessageFormatter.php`
- Test: `tests/Unit/Notification/MessageFormatterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notification/MessageFormatterTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Domain\Classification;
use App\Domain\Confidence;
use App\Domain\DealType;
use App\Domain\DetectedPhone;
use App\Domain\Listing;
use App\Domain\PhoneOrigin;
use App\Domain\Source;
use App\Domain\Verdict;
use App\Notification\MessageFormatter;
use PHPUnit\Framework\TestCase;

final class MessageFormatterTest extends TestCase
{
    private function listing(): Listing
    {
        return new Listing(
            id: 'sreality:111',
            source: Source::SREALITY,
            title: 'Prodej bytu 2+kk 50 m2',
            price: 7_500_000,
            dealType: DealType::SALE,
            location: 'Praha 7 - Holesovice',
            url: 'https://www.sreality.cz/detail/111',
            rawText: 'Volejte na 777 123 456',
            sellerMeta: null,
            structuredPhones: [],
        );
    }

    public function testFormatsOwnerListing(): void
    {
        $message = (new MessageFormatter())->format(
            $this->listing(),
            new Verdict(Classification::OWNER, Confidence::HIGH, ['owner self-identification: "primo od majitele"']),
            [new DetectedPhone('+420777123456', '777 123 456', PhoneOrigin::TEXT, 'volejte')],
        );

        self::assertStringContainsString('Prodej bytu 2+kk 50 m2', $message);
        self::assertStringContainsString('Praha 7 - Holesovice', $message);
        self::assertStringContainsString('7 500 000', $message);
        self::assertStringContainsString('+420777123456', $message);
        self::assertStringContainsString('https://www.sreality.cz/detail/111', $message);
        self::assertStringContainsString('majitel', $message);
        self::assertStringContainsString('Sreality', $message);
    }

    public function testFormatsUnknownListingWithQuestionBadge(): void
    {
        $message = (new MessageFormatter())->format(
            $this->listing(),
            new Verdict(Classification::UNKNOWN, Confidence::LOW, ['no decisive signal']),
            [new DetectedPhone('+420777123456', '777 123 456', PhoneOrigin::TEXT, null)],
        );

        self::assertStringContainsString('nejasné', $message);
    }

    public function testFormatsMultiplePhones(): void
    {
        $message = (new MessageFormatter())->format(
            $this->listing(),
            new Verdict(Classification::OWNER, Confidence::HIGH, ['x']),
            [
                new DetectedPhone('+420777123456', '777 123 456', PhoneOrigin::TEXT, null),
                new DetectedPhone('+420608444111', '608 444 111', PhoneOrigin::STRUCTURED, null),
            ],
        );

        self::assertStringContainsString('+420777123456', $message);
        self::assertStringContainsString('+420608444111', $message);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter MessageFormatterTest`
Expected: FAIL — `Class "App\Notification\MessageFormatter" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Notification/MessageFormatter.php`:
```php
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

        $badge = self::BADGES[$verdict->classification->value] ?? $verdict->classification->value;
        $reason = $verdict->reasons[0] ?? '';
        $lines[] = $badge . ($reason !== '' ? ' — ' . $this->escape($reason) : '');

        $lines[] = '🔗 ' . $this->escape($listing->url);

        $snippet = $this->snippet($listing->rawText);
        if ($snippet !== '') {
            $lines[] = '📝 ' . $this->escape($snippet);
        }

        $lines[] = '🌐 ' . (self::SOURCE_LABELS[$listing->source->value] ?? $listing->source->value);

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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter MessageFormatterTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Notification/MessageFormatter.php tests/Unit/Notification/MessageFormatterTest.php
git commit -m "feat: add MessageFormatter"
```

---

### Task 10: Notifier interface + TelegramNotifier

Sends a formatted message to the operator's chat via the Telegram Bot API. Pure transport — no business logic. Throws on failure so the runner can decide not to mark a listing seen. A `Notifier` interface is introduced so `MonitorRunner` can be unit-tested with a fake (the concrete `TelegramNotifier` does real HTTP and cannot be substituted otherwise).

**Files:**
- Create: `src/Notification/Notifier.php`, `src/Notification/TelegramNotifier.php`
- Test: `tests/Unit/Notification/TelegramNotifierTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notification/TelegramNotifierTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\TelegramNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
    }

    public function testSendThrowsOnApiError(): void
    {
        $http = new MockHttpClient(new MockResponse('{"ok":false}', ['http_code' => 400]));
        $notifier = new TelegramNotifier($http, 'secret-token', '123456');

        $this->expectException(\RuntimeException::class);
        $notifier->send('hello');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter TelegramNotifierTest`
Expected: FAIL — `Class "App\Notification\TelegramNotifier" not found`.

- [ ] **Step 3: Write the interface**

Create `src/Notification/Notifier.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notification;

interface Notifier
{
    /**
     * @throws \RuntimeException when the message could not be delivered
     */
    public function send(string $text): void;
}
```

- [ ] **Step 4: Write the implementation**

Create `src/Notification/TelegramNotifier.php`:
```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter TelegramNotifierTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Notification/Notifier.php src/Notification/TelegramNotifier.php tests/Unit/Notification/TelegramNotifierTest.php
git commit -m "feat: add Notifier interface and TelegramNotifier"
```

---

### Task 11: MonitorRunner

The orchestrator. One `run()` executes a full monitoring pass: per-source fetch, dedup, hydrate, detect phones, record evidence, classify, send, mark seen. It isolates per-source and per-listing failures, and on a first run (empty `SeenStore`) it caps how many listings are sent.

**Files:**
- Create: `src/Monitor/MonitorRunner.php`
- Test: `tests/Unit/Monitor/MonitorRunnerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Monitor/MonitorRunnerTest.php`:
```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter MonitorRunnerTest`
Expected: FAIL — `Class "App\Monitor\MonitorRunner" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Monitor/MonitorRunner.php`:
```php
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
        if ($phones === []) {
            $this->seenStore->markSeen($listing->id, $listing->source);

            return false;
        }

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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter MonitorRunnerTest`
Expected: PASS (5 tests).

Note: the test's anonymous source doubles `implement ListingSource` and the anonymous notifier double `implements Notifier`, so PHP's type system accepts them at runtime.

- [ ] **Step 5: Run quality tools**

Run: `docker compose run --rm bot composer ecs:fix && docker compose run --rm bot composer phpstan`
Expected: ECS clean, PHPStan no errors (PHPStan analyses `src/` only).

- [ ] **Step 6: Commit**

```bash
git add src/Monitor tests/Unit/Monitor
git commit -m "feat: add MonitorRunner orchestrator"
```

---

### Task 12: MonitorCommand + dependency wiring

The console command that the Docker loop calls. It also wires `Database::migrate()` so the schema exists before the first run.

**Files:**
- Create: `src/Command/MonitorCommand.php`
- Modify: `config/services.yaml` (add explicit `Database` path argument — see Step 3)
- Test: `tests/Unit/Command/MonitorCommandTest.php`

- [ ] **Step 1: Write the failing test**

`MonitorRunner` is a `final` concrete class, so the test constructs a real one with an empty `sources` iterable (which makes `run()` a no-op) rather than a double.

Create `tests/Unit/Command/MonitorCommandTest.php`:
```php
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
            firstRunLimit: 15,
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter MonitorCommandTest`
Expected: FAIL — `Class "App\Command\MonitorCommand" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Command/MonitorCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitor\MonitorRunner;
use App\Persistence\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:monitor:run',
    description: 'Run one monitoring pass over all listing sources',
)]
final class MonitorCommand extends Command
{
    public function __construct(
        private readonly Database $database,
        private readonly MonitorRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->database->migrate();
        $io->info('Monitoring pass started.');

        $this->runner->run();

        $io->success('Monitoring pass finished.');

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Verify `config/services.yaml` resolves the `Database` path**

`config/services.yaml` already binds `$databasePath` (Task 0, Step 4). Confirm `Database`'s constructor parameter is named `$databasePath` so the bind matches — it is (Task 3). No edit needed unless the container fails to compile in Step 6; if it does, add an explicit service definition:
```yaml
    App\Persistence\Database:
        arguments:
            $databasePath: '%env(resolve:DATABASE_PATH)%'
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm bot vendor/bin/phpunit --filter MonitorCommandTest`
Expected: PASS (1 test).

- [ ] **Step 6: Verify the command is wired in the real container**

Run: `docker compose run --rm bot php bin/console list`
Expected: `app:monitor:run` appears in the command list with no container-compile errors.

- [ ] **Step 7: Run the full test suite and quality tools**

Run:
```bash
docker compose run --rm bot vendor/bin/phpunit
docker compose run --rm bot composer ecs:fix
docker compose run --rm bot composer phpstan
```
Expected: all tests pass; ECS clean; PHPStan no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Command tests/Unit/Command config/services.yaml
git commit -m "feat: add app:monitor:run command and wiring"
```

---

### Task 13: End-to-end verification

Confirms the assembled bot performs a real monitoring pass and the Docker loop is healthy.

**Files:** none (verification only).

- [ ] **Step 1: Confirm the operator's secrets are present**

Confirm `<root>/.env.local` contains real `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` values (the operator set these earlier). Do not print the file.

- [ ] **Step 2: Run one real monitoring pass**

Run: `docker compose run --rm bot php bin/console app:monitor:run`
Expected: the command finishes with "Monitoring pass finished." `var/monitor.db` is created on the host. Because this is the first run, up to `MONITOR_FIRST_RUN_LIMIT` listings per source are sent to the operator's Telegram chat; the operator should receive those messages.

- [ ] **Step 3: Run a second pass to confirm deduplication**

Run: `docker compose run --rm bot php bin/console app:monitor:run`
Expected: finishes cleanly; few or no new messages (only genuinely new listings since the first run), proving the `SeenStore` works.

- [ ] **Step 4: Verify the looping container starts**

Run: `docker compose up -d && sleep 5 && docker compose logs bot`
Expected: logs show "Monitor loop starting" followed by a monitoring pass. Then stop it: `docker compose down`.

- [ ] **Step 5: Final full verification**

Run:
```bash
docker compose run --rm bot vendor/bin/phpunit
docker compose run --rm bot composer phpstan
docker compose run --rm bot composer ecs
```
Expected: all tests pass; PHPStan no errors; ECS clean.

- [ ] **Step 6: Commit any final fixes**

```bash
git add -A
git commit -m "chore: end-to-end verification fixes" || echo "nothing to commit"
```

---

## Self-Review

**1. Spec coverage** — every spec section maps to a task:
- Personal monitor, CLI, ~20-min schedule → Task 0 (`docker-entrypoint.sh` loop), Task 12 (`MonitorCommand`).
- "Classify the contact, not the listing" → Task 5 (`ContactRegistry`), used by Task 6 and Task 11.
- Sreality (list + detail, structured phone, premise, seller count) → Task 7.
- Bezrealitky (description only, no structured phone/seller) → Task 8.
- `PhoneDetector` (CZ regex + markers, structured + text, dedup) → Task 2.
- Tiered classifier Tier 0–2; `AdvertiserClassifier` interface for future Tier 3 → Task 6.
- `SeenStore` dedup → Task 4. `Database`/SQLite schema (3 tables) → Task 3.
- First-run cap (`MONITOR_FIRST_RUN_LIMIT`) → Task 11 (`firstRunLimit` logic) + Task 0 (`.env`).
- Telegram message format + badges → Task 9. Send to one chat → Task 10.
- Error handling (per-source isolation; send/hydrate failure leaves listing un-seen) → Task 11.
- Config (`.env.local` vars) → Task 0 Steps 3–4.
- Testing (unit for detector/classifier/stores/formatter; fixture-based client mapping; runner with fakes) → Tasks 2,4,5,6,7,8,9,11.
- Open items (Bezrealitky Prague `regionId` via `listRegions`) → Task 8 Step 6 verifies the id live.

**2. Placeholder scan** — no "TBD"/"implement later"/"add error handling" placeholders; every code step contains complete code; every command step contains the exact command and expected output.

**3. Type consistency** — checked across tasks:
- `Listing` constructor signature (Task 1) is used identically in Tasks 2, 6, 7, 8, 9, 11.
- `Listing::withDetails(rawText, sellerMeta, structuredPhones)` (Task 1) is called with those exact names in Task 7.
- `SellerMeta(hasPremise, totalListingCount, name)` (Task 1) matches all usages (Tasks 6, 7, 11).
- `DetectedPhone(e164, raw, origin, marker)` (Task 1) matches Tasks 2, 6, 9.
- `Verdict(classification, confidence, reasons)` (Task 1) matches Tasks 6, 9, 11.
- `AdvertiserClassifier::classify(Listing, array): Verdict` (Task 6) matches the call in Task 11 and the `TieredAdvertiserClassifier` constructor `(ContactRegistry)`.
- `ContactRegistry` methods `recordEvidence/listingCount/siteCount/getVerdict/setVerdict` (Task 5) match Tasks 6 and 11.
- `SeenStore` methods `isSeen/markSeen/count` (Task 4) match Task 11.
- `ListingSource::fetchRecentListings(): array` and `hydrate(Listing): Listing` (Task 7) match Tasks 8 and 11.
- `Notifier::send(string): void` (Task 10) is implemented by `TelegramNotifier` (Task 10) and depended on by `MonitorRunner` (Task 11); the test doubles in Tasks 11 and 12 `implement Notifier`, so PHP's runtime type checks pass.
- `MonitorRunner` constructor parameter order/names (Task 11) match the `services.yaml` `!tagged_iterator` wiring (Task 0) and the real `MonitorRunner` construction in `MonitorCommandTest` (Task 12).

Fixed during self-review: `MonitorRunner` originally depended on the concrete `TelegramNotifier`, which would have caused a runtime `TypeError` when tests passed anonymous doubles. Introduced the `Notifier` interface (Task 10) and rewrote `MonitorCommandTest` to construct a real `MonitorRunner` with an empty `sources` iterable instead of an anonymous runner double.
