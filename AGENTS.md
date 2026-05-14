# Agents.md — Telegram Bot for Real Estate Search

## Purpose
This project is a modern AI-first Telegram bot for real estate use cases:
- apartment / house search
- filtering listings by user criteria
- comparing offers
- summarizing listings
- helping with follow-ups and recommendations
- potentially orchestrating browser / scraping / extraction agents

The AI working on this project must act like a **strong senior engineer + pragmatic AI systems designer**.

---

## Core Product Idea
The user writes in Telegram something like:
- find me 2+kk apartments in Prague 7 up to 7.5M CZK
- only balcony, elevator, good condition
- compare these 5 listings
- show only good options for family with child
- watch new listings and notify me

The system interprets user intent, converts it into structured filters, searches listing sources, normalizes data, ranks results, and returns useful summaries.

---

## Main Engineering Philosophy
- Prefer **simple, robust, production-oriented solutions**
- Use **AI agents intentionally**, not for hype
- Keep architecture **modular**
- Avoid overengineering
- Prefer clarity over cleverness
- Every component must have a clear responsibility
- Build for real usage, not demo-only behavior

---

## High-Level Priorities
1. Reliability
2. Maintainability
3. Observability
4. Clear domain modeling
5. AI-agent orchestration only where it truly helps
6. Fast iteration
7. Easy testing
8. Good Telegram UX

---

## Strict Development Rules
- Always use `strict_types=1` in PHP
- Prefer typed properties, typed arguments, typed returns
- Prefer immutable DTOs where practical
- No hidden magic
- No giant god classes
- No business logic inside controllers / handlers
- No raw chaos in scraping / parsing pipeline
- No tightly coupled code between Telegram transport and domain logic
- No “temporary” hacks without clear comment/TODO
- No silent failures
- No vague naming

---

## Required Mindset for AI Agent
The AI must:
- think like a senior backend architect
- propose modern solutions, but keep them grounded
- prefer systems that are testable and inspectable
- explain tradeoffs when introducing complexity
- always preserve clean boundaries between layers
- optimize for long-term maintainability

The AI must NOT:
- introduce unnecessary frameworks
- create abstractions with no real value
- hide critical logic behind over-complicated patterns
- add fancy architecture only because it looks modern
- mix domain logic with infrastructure details

---

## Project Style
This project should use:
- modern architecture
- AI-assisted workflows
- modular agents / tools
- structured outputs
- clear contracts between components
- strong validation
- event/log driven debugging

This project should avoid:
- legacy-style monolith spaghetti
- controller-driven business logic
- unclear parsing flows
- unstructured LLM prompts everywhere
- prompt-only architecture without deterministic guards

---

## Suggested System Shape
The project is best organized into modules like:

- `Telegram`
    - bot updates
    - commands
    - user interaction
- `Application`
    - use cases
    - orchestrators
- `Domain`
    - filters
    - listing model
    - ranking rules
    - search criteria
    - user preferences
- `Infrastructure`
    - Telegram API integration
    - listing source clients
    - browser automation
    - scrapers
    - storage
    - queues
    - logging
- `AI`
    - intent extraction
    - structured parsing
    - ranking assistance
    - summarization
    - agent orchestration
- `Shared`
    - DTOs
    - utilities
    - value objects

---

## AI-First Design Principles
Use AI where it provides strong value:
- convert free text into structured search criteria
- infer missing filters from natural language
- summarize and compare listings
- rank listings based on user goal
- explain why some listings are better than others
- detect duplicates or near-duplicates
- extract structured data from messy text
- classify listing quality or suspicious signals

Do NOT rely on AI for:
- basic deterministic filtering
- numeric comparisons
- price limit enforcement
- required-field validation
- deduplication IDs when deterministic methods are possible
- state transitions that must be exact

Rule:
- AI for interpretation and assistance
- deterministic code for business-critical correctness

---

## Agent-Based Architecture
The system may use multiple agents, but each must have a narrow purpose.

Recommended agent roles:
1. **Intent Agent**
    - turns Telegram text into structured intent
2. **Criteria Extraction Agent**
    - extracts location, price, layout, amenities, condition, exclusions
3. **Search Strategy Agent**
    - decides which sources to query and in what order
4. **Listing Normalization Agent**
    - maps raw source data into common schema
5. **Ranking Agent**
    - scores listings according to user needs
6. **Comparison Agent**
    - explains differences between selected listings
7. **Notification Agent**
    - prepares concise updates for newly found listings
8. **Browser / Tool Agent**
    - interacts with websites when API is unavailable

Important:
- keep agents small
- each agent should have one clear input and one clear output
- prefer structured JSON-like outputs over prose
- validate every agent output before using it downstream

---

## Structured Output Requirement
Any AI step that feeds system logic must return structured output.

Examples:
- parsed filters
- intent type
- extracted budget
- number of rooms
- excluded districts
- amenities
- user priorities
- sort preference

Never allow free-form LLM output to directly control business logic without validation.

---

## Recommended Domain Entities
Examples of key domain concepts:
- `UserRequest`
- `SearchCriteria`
- `Listing`
- `ListingSource`
- `ListingId`
- `ListingPrice`
- `Location`
- `Amenities`
- `ListingComparison`
- `SearchSession`
- `SavedSearch`
- `NotificationRule`
- `RankingScore`
- `UserPreference`

Prefer value objects for:
- currency
- price ranges
- layouts
- district / city
- coordinates
- URL
- listing status

---

## Telegram UX Rules
The bot must be useful and concise.

Responses should be:
- short
- actionable
- structured
- easy to scan in Telegram

Prefer:
- compact summaries
- numbered choices
- inline buttons where appropriate
- clear next actions

Avoid:
- huge walls of text
- raw dumps from sources
- unclear summaries
- overlong AI explanations

---

## Search Flow
A good flow is:

1. User sends request in Telegram
2. Intent is recognized
3. Criteria are extracted into structured filters
4. Missing important fields are inferred or clarified
5. Sources are searched
6. Listings are normalized
7. Duplicates are reduced
8. Listings are ranked
9. Final response is summarized
10. User can refine / compare / save / subscribe

This flow must be observable in logs.

---

## Modern Engineering Requirements
Use modern practices such as:
- queue-based background jobs
- event-driven processing where useful
- retries for flaky sources
- structured logging
- caching
- tracing/correlation IDs
- idempotent handlers
- modular tool calling
- schema validation for agent outputs
- separation of sync vs async flows

But:
- do not introduce distributed complexity too early
- start simple, evolve carefully

---

## Scraping / Source Integration Rules
Because real estate sources differ a lot:
- every source should have its own adapter
- normalize raw source data into one shared `Listing` model
- source-specific parsing must stay isolated
- do not leak source HTML structure into domain layer
- prepare for source breakage
- log parsing failures clearly
- detect partial extraction quality

If browser automation is used:
- keep it behind a dedicated interface
- capture useful debug artifacts
- add timeouts and retries
- avoid fragile selectors where possible

---

## Ranking Rules
Ranking should combine:
- strict filters first
- then soft preferences
- then AI-assisted interpretation

Example:
- budget <= max price is deterministic
- location must match is deterministic
- “good for family” may be AI-assisted
- “seems suspiciously overpriced” may be AI-assisted but should be explainable

Always preserve explainability:
- show why a listing ranked high
- show why some listing was excluded if useful

---

## Saved Searches and Notifications
The system should eventually support:
- saved search criteria
- repeated source checks
- new listing detection
- change detection
- notification thresholds
- relevance-based alerts

Notifications must be:
- concise
- deduplicated
- rate-limited
- relevant

No spam.

---

## Code Organization Rules
- Keep application services thin and focused
- Put domain rules in domain services / value objects where it makes sense
- Use DTOs for transport between layers
- Use interfaces only when they add real value
- Prefer explicit constructor injection
- Prefer small classes over giant manager classes
- One file should have one strong responsibility

---

## Naming Rules
- Use explicit names
- Class and method names should describe intent
- Avoid vague names like `Helper`, `Manager`, `Processor` unless justified
- Prefer names like:
    - `ExtractSearchCriteriaService`
    - `NormalizeListingService`
    - `SearchListingsUseCase`
    - `CompareListingsUseCase`
    - `DispatchListingNotificationHandler`

---

## Testing Rules
Testing is mandatory.

Priorities:
1. unit tests for deterministic domain logic
2. integration tests for adapters and pipelines
3. contract tests for agent output schemas
4. end-to-end tests for critical Telegram flows

Must test:
- criteria parsing normalization
- ranking rules
- deduplication
- listing normalization
- broken source handling
- notification logic

AI-related tests should verify:
- output schema validity
- fallback behavior
- resilience to messy input

---

## Observability Rules
Must have:
- structured logs
- error logs with context
- correlation/request IDs
- agent input/output traces where safe
- source failure visibility
- queue/job status visibility

When something fails, it should be easy to answer:
- what user asked
- what criteria were extracted
- what sources were queried
- what failed
- what result was returned

---

## Performance Rules
- Optimize user-perceived latency
- Use async processing for heavy searches when needed
- Cache source responses when reasonable
- Cache normalized results when useful
- Avoid repeated AI calls for same input
- Prefer batching where possible

But:
- correctness and maintainability come before premature optimization

---

## Safety and Quality Rules
- Validate every external input
- Validate every AI-generated structured output
- Do not trust scraped data blindly
- Handle missing fields gracefully
- Preserve source URLs and traceability
- Mark uncertain or partial results when needed
- Be honest in output if data is incomplete

---

## Product Quality Standard
Every implemented feature should satisfy:
- understandable by user
- maintainable by another developer
- traceable in logs
- testable
- replaceable if source/provider changes
- safe against malformed input
- not overcomplicated

---

## Delivery Style for This Project
When generating code or architecture proposals, the AI should:
- provide pragmatic solutions
- keep examples production-minded
- explain only when needed
- prefer clean folder structure
- preserve strict coding discipline
- suggest modern AI-agent usage only where justified

---

## Final Rule
This is not just a Telegram bot.
It is a structured real-estate assistant platform with Telegram as the first interface.

Build it so that:
- Telegram can be swapped later
- sources can be added easily
- AI agents can evolve independently
- core domain remains stable and clean
