# Owner Contact Monitor Bot — Design

*Date: 2026-05-14*

## Purpose

Personal Telegram bot for a single user (the operator). It periodically scans Czech
real-estate listing sites for **new listings posted by property owners** (not
real-estate agencies) that expose a **reachable phone number**, and pushes them to
the operator's Telegram chat. The operator then calls the owners directly.

There is no interactive interface — the operator never sends commands or filters.
The bot runs unattended on a schedule.

## Scope

- **In scope (v1):** Sreality + Bezrealitky; region Prague; deal types sale + rent;
  Tier 0–2 advertiser classification.
- **Later:** Bazoš source; Tier 3 LLM classifier; additional regions.
- **Out of scope:** multi-user, interactive search/filters, automatically
  calling/messaging owners.

## Legal note

Bulk scraping may conflict with these sites' Terms of Service, and calling owners
has GDPR/ePrivacy implications in the Czech Republic. This is treated as a personal
tool for the operator's own real-estate business — contacting an owner about the
listing they themselves published. The operator is responsible for ToS and consent
compliance.

## Stack

Symfony 8, PHP 8.4, Docker. Everything runs in containers (no local PHP). A
scheduler runs `php bin/console app:monitor:run` every ~20 minutes
(`MONITOR_INTERVAL`).

## Core idea: classify the contact, not the listing

A single listing is ambiguous — owner vs. realtor cannot be reliably told from one
listing's structured fields (see Reconnaissance below). A **phone number, however,
accumulates evidence** across listings and sites.

The bot maintains a **contact registry** (SQLite): each phone number maps to the
listings and sites it appeared on, associated names, first-seen timestamp, and a
verdict (owner / realtor / unknown). Every new listing is *evidence* that updates a
contact's record. The verdict is on the contact, not the listing. Once a number is
classified realtor, all future listings carrying it are dropped silently. This
naturally handles deduplication, cross-posting, and self-improvement over time.

## Reconnaissance findings (2026-05-14)

Verified against the live APIs:

- **Sreality** — the list endpoint (`/api/cs/v2/estates`) carries no description or
  phone. The **detail endpoint** (`/api/cs/v2/estates/{hash_id}`) returns `text`
  (full description) and `_embedded.seller` with `phones[]` (structured number) and
  `specialization` (the seller's per-category listing counts). Agencies are marked
  by `_embedded.seller._embedded.premise`. In a sample of 8 freshest Prague flats,
  **all 8 were agency listings** — fresh Sreality inventory is overwhelmingly
  realtor-posted.
- **Bezrealitky** — GraphQL `Advert` type exposes `description` (full text), but
  `user` / `contactUser` return `null` without authentication, so **no structured
  phone is available**. `broker` / `type` / `project` are also null/`UNDEFINED`
  unattributed — **no structured realtor signal at all**. Phone numbers are
  obtainable only when the owner pasted them into the description text.

Conclusion: structured "is this a realtor" signals are weak (Sreality) or absent
(Bezrealitky). Text analysis plus the contact registry must carry the
classification.

## Flow (one run)

```
scheduler → MonitorCommand → MonitorRunner:
  for each ListingSource (Sreality, Bezrealitky):
     fetchRecentListings()                → Listing[]
     for each Listing:
        SeenStore.isSeen(listing.id)?  yes → skip
        PhoneDetector.detect(listing)  → DetectedPhone[]   (none → mark seen, skip)
        ContactRegistry.recordEvidence(listing, phones)
        AdvertiserClassifier.classify(listing, registry) → Verdict
        Verdict = realtor                  → mark seen, skip
        Verdict = owner | unknown          → MessageFormatter → TelegramNotifier.send()
        on send success                    → SeenStore.markSeen(listing.id)
```

## Advertiser classification — tiered funnel

Ordered cheap→expensive, deterministic→probabilistic. The first tier to produce a
verdict wins. ~90% of listings are expected to resolve for free in Tiers 0–2.

**Tier 0 — deterministic EXCLUDE (free, from data already fetched):**
- Sreality: listing's `_embedded.seller` has `_embedded.premise` → realtor.
- Sreality: seller's total listing count > 2 (sum of
  `seller.specialization.category[].num`) → realtor.
- Phone number already marked realtor in `ContactRegistry` → realtor.

**Tier 1 — deterministic INCLUDE (free, high precision):**
- Description contains owner self-identification (configurable list): `RK nevolat`,
  `bez RK`, `přímo od majitele`, `bez provize`, `soukromý prodej`,
  `makléře nekontaktujte` → owner, high confidence.
- Sreality: seller's total listing count (same `specialization` sum as Tier 0) is
  exactly 1 and no `premise` → owner. (A seller count of exactly 2 matches neither
  Tier 0 nor Tier 1 and falls through to Tier 2 — intentional.)

**Tier 2 — cross-reference (cheap, strong):**
- Phone number appears on ≥3 distinct listings, or on ≥2 different sites
  (per `ContactRegistry`) → realtor; the contact is marked realtor permanently.
- Description contains realtor language (`makléř`, `realitní kancelář`, `provize`,
  `zprostředkování`) with no owner self-ID → realtor.

**Tier 3 — LLM (later, not built in v1):**
- Whatever survives Tiers 0–2 as `unknown` → description sent to Claude Haiku →
  owner / realtor / unknown + reason.
- v1 ships the `AdvertiserClassifier` interface so the LLM tier plugs in without
  touching the rest of the system.

**Verdict** = `{ classification: owner|realtor|unknown, confidence: high|medium|low,
reasons: string[] }`.

In v1 (no Tier 3), `unknown` listings **are** sent, clearly tagged `❓ nejasné`, so
the operator decides. This is revisited once Tier 3 lands.

## Components

| Component | Responsibility | Depends on |
|---|---|---|
| `ListingSource` (interface) | `fetchRecentListings(): Listing[]` | — |
| `SrealityClient` | list endpoint → hash_ids; detail endpoint per new id → full `Listing` incl. seller meta, structured phones, description text | HttpClient |
| `BezrealitkyClient` | GraphQL `listAdverts` with `description` → `Listing` (no structured phone available) | HttpClient |
| `Listing` (DTO) | normalized listing: id, source, title, price, dealType, location, url, rawText, sellerMeta?, structuredPhones[] | — |
| `PhoneDetector` | extract phone numbers from `rawText` (CZ regex + markers) and `structuredPhones` → `DetectedPhone[]` | — |
| `AdvertiserClassifier` (interface) | `classify(Listing, ContactRegistry): Verdict`; v1 impl = tiered Tier 0–2 | ContactRegistry |
| `ContactRegistry` | SQLite-backed contact evidence + verdict store; holds the learned realtor blocklist | SQLite |
| `SeenStore` | SQLite-backed processed-listing-id set (dedup) | SQLite |
| `MessageFormatter` | `Listing` + `Verdict` + phones → Telegram message text | — |
| `TelegramNotifier` | send a message to the configured chat id | HttpClient / telegram-bot/api |
| `MonitorRunner` | orchestrate the flow above | all of the above |
| `MonitorCommand` | thin Symfony console command wrapping `MonitorRunner` | MonitorRunner |

Adding Bazoš later is a new `ListingSource` implementation only — nothing else changes.

## PhoneDetector detail

- CZ phone regex: optional `+420` / `00420`, then 9 digits, tolerant of spaces,
  slashes, and dashes (`777 123 456`, `+420777123456`, `777/123/456`).
- Marker words near digit groups (`tel`, `telefon`, `tel na mě`, `volejte`, `mob`,
  `kontakt`) raise confidence and help reject non-phone digit groups (area code,
  year, m²).
- Returns unique `DetectedPhone { e164, raw, source: structured|text, marker? }`.
- This is the most heavily unit-tested component (CZ number formats, false positives).

## Persistence

A single SQLite file (`var/monitor.db`), tables:
- `seen_listings(listing_id PK, source, first_seen_at)`
- `contacts(phone_e164 PK, verdict, confidence, first_seen_at, updated_at)`
- `contact_evidence(id PK, phone_e164 FK, listing_id, source, name?, seen_at)`

## Configuration (`.env.local`)

```
TELEGRAM_BOT_TOKEN=          # secret — operator fills in by hand, never committed
TELEGRAM_CHAT_ID=            # operator's chat id — sole recipient
MONITOR_REGION=praha         # region (designed to extend)
MONITOR_DEAL_TYPES=sale,rent
MONITOR_INTERVAL=1200        # seconds between runs (~20 min)
MONITOR_FIRST_RUN_LIMIT=15   # per source on first run
```

## First run

With `ContactRegistry` / `SeenStore` empty, the bot would otherwise dump hundreds of
listings at once. On the first run it sends the newest `MONITOR_FIRST_RUN_LIMIT`
listings per source and marks everything else seen silently. Subsequent runs send
only genuinely new listings.

## Telegram message format

One listing = one message:

```
🏠 Prodej bytu 2+kk 50 m²
📍 Praha 7 — Holešovice
💰 7 500 000 Kč
📞 +420 777 123 456
👤 pravděpodobně majitel — důvod: "přímo od majitele" v textu
🔗 https://sreality.cz/detail/...
📝 "...kontaktujte mě na tel ..."
🌐 Sreality
```

Classification badge: `👤 majitel` or `❓ nejasné`. Listings with a realtor verdict
are not sent.

## Error handling

- One source failing (network / API) is caught per-source and logged; other sources
  continue. One site never aborts a run.
- A Sreality detail fetch failing for a single id → skip that id, do not mark seen
  (retried next run).
- A Telegram send failing → log, do **not** mark seen → retried next run.
- SQLite database auto-created on first run.
- Politeness: realistic User-Agent, a small delay between Sreality detail requests,
  ~20 min interval — to reduce block risk.

## Testing

- **Unit:** `PhoneDetector` (CZ formats, false positives, markers);
  `AdvertiserClassifier` Tier 0–2 (each rule, with a fake `ContactRegistry`);
  `ContactRegistry` and `SeenStore` (in-memory SQLite); `MessageFormatter`.
- **Mapping:** `SrealityClient` / `BezrealitkyClient` response→`Listing` mapping
  tested against saved fixture responses (recorded real API payloads); no live
  calls in tests.
- **Orchestration:** `MonitorRunner` with fake sources and in-memory stores.

## Open items / future work

- Bezrealitky Prague `regionId` must be resolved via the `listRegions` query (a known
  quirk) during implementation.
- Tier 3 LLM classifier (Claude Haiku) — a separate later step; the interface ships
  in v1.
- Bazoš source — later.
- Proactive reverse phone lookup (a Tier 2 enhancement) — later.
