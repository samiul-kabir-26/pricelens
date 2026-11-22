# PriceLens Architecture

PriceLens is a multi-tenant price-intelligence service. A client sends a product (title, image, GTIN/UPC/EAN,
MPN, brand or a reference URL). PriceLens finds matching listings on tenant-defined sites and search engines,
extracts and normalizes the offers, scores each match, and returns a ranked list. Results come back through the
REST API, signed webhooks and Reverb real-time events.

## 1. System overview

```mermaid
flowchart LR
    subgraph Clients
        M["Machine client<br/>API key pl_live_..."]
        U["Browser user"]
    end

    subgraph Edge
        N["nginx :8080"]
    end

    subgraph App["Application containers"]
        W["web<br/>Next.js 16 BFF<br/>API key stays server-side"]
        A["api<br/>Laravel 13 REST /api/v1"]
        H["horizon<br/>queue workers"]
        S["scheduler<br/>schedule:work"]
        R["reverb<br/>WebSocket events"]
        RD["renderer<br/>Node 22 + Fastify + Playwright<br/>internal only"]
    end

    subgraph Data
        DB[("MySQL 8.4<br/>tenants, searches, offers,<br/>price_snapshots partitions")]
        RS[("Redis 7.4<br/>queues, cache, throttles,<br/>circuit breakers")]
    end

    subgraph External
        SITES["Retailer sites"]
        ENG["Search / shopping engines<br/>SerpApi, Brave"]
        VIS["Google Vision<br/>reverse image"]
        LLM["Anthropic Claude<br/>LLM extraction fallback"]
        ECB["ECB FX rates"]
        HOOK["Tenant webhooks<br/>and callback URLs"]
    end

    M --> N
    U --> N
    N --> W
    N --> A
    N --> R
    W -->|"server-side calls"| A
    A --> DB
    A --> RS
    A -->|"dispatch jobs"| RS
    RS --> H
    S -->|"cron jobs"| RS
    H --> DB
    H -->|"fetch"| SITES
    H -->|"JS-heavy pages"| RD
    RD --> SITES
    H --> ENG
    H --> VIS
    H --> LLM
    H --> ECB
    H -->|"signed deliveries"| HOOK
    H -->|"broadcast"| R
    R -->|"live progress and offers"| U
```

## 2. How a search flows

```mermaid
sequenceDiagram
    autonumber
    participant C as Client
    participant API as Laravel API
    participant Q as Redis queue / Horizon
    participant P as PlanSearchJob
    participant D as DiscoverJob batch
    participant F as Fetch / Render jobs
    participant X as ExtractOfferJob
    participant O as OfferRecorder
    participant Z as FinalizeSearchJob
    participant RV as Reverb

    C->>API: POST /api/v1/searches
    API->>API: auth, quota, rate limit, idempotency
    API->>Q: queue PlanSearchJob
    API-->>C: 202 search id, status queued

    Q->>P: run
    P->>P: normalize query, GTIN, model, brand, attributes
    P->>P: image ingest + pHash, reverse image
    P->>Q: Bus::batch of DiscoverJob

    par discovery sources
        D->>D: site search
        D->>D: search and shopping engines
        D->>D: reverse image
        D->>D: feeds and sitemaps
        D->>D: reference URL
    end
    D->>Q: candidate URLs

    Q->>F: FetchCandidateJob or RenderCandidateJob
    F->>X: page snapshot
    X->>X: JSON-LD, microdata/RDFa, meta, embedded state,<br/>adapter selectors, then LLM fallback
    alt page looks client-rendered
        X->>Q: hand off to RenderCandidateJob
    end
    X->>O: raw offer
    O->>O: locale-aware price, ECB FX conversion,<br/>availability and condition, match + confidence score
    O->>RV: OfferFound / SearchProgressUpdated
    RV-->>C: live events

    Q->>Z: batch finished
    Z->>Z: SKU dedupe, MAD outliers, stats, final status
    Z->>Z: monitor snapshots and alerts
    Z->>C: signed search.completed webhook / callback
    Z->>RV: SearchCompleted
    C->>API: GET offers, ranked list
```

## 3. The fetch guard chain

Every candidate URL passes these checks before it is fetched. A failed check stops the fetch or puts the job back
on the queue.

```mermaid
flowchart LR
    U["Candidate URL"] --> G1{"SSRF guard<br/>private IPs blocked"}
    G1 -->|blocked| STOP["Drop"]
    G1 -->|ok| G2{"Domain policy"}
    G2 -->|denied| STOP
    G2 -->|ok| G3{"robots.txt"}
    G3 -->|disallowed| STOP
    G3 -->|ok| G4{"Snapshot cache hit?"}
    G4 -->|yes| EX["ExtractOfferJob"]
    G4 -->|no| G5{"Circuit breaker<br/>per domain"}
    G5 -->|open| REL["Release job,<br/>retry later"]
    G5 -->|closed| G6{"Per-domain throttle<br/>shared across tenants"}
    G6 -->|over limit| REL
    G6 -->|ok| FE["HTTP fetch or<br/>Playwright render"]
    FE --> SN["Store snapshot"] --> EX
```

## 4. Queue job middleware

Every pipeline job runs through this middleware stack in order.

```mermaid
flowchart LR
    J["Pipeline job"] --> M1["TenantAware<br/>load tenant scope"]
    M1 --> M2["TenantFairness<br/>no tenant starves others"]
    M2 --> M3["EnsureSearchNotCancelled"]
    M3 --> M4["DomainCircuitBreaker"]
    M4 --> M5["DomainThrottle"]
    M5 --> H["handle()"]
```

## 5. Core data model

```mermaid
erDiagram
    PLAN ||--o{ TENANT : "subscribed by"
    TENANT ||--o{ TENANT_USER : has
    USER ||--o{ TENANT_USER : "member of"
    TENANT ||--o{ API_KEY : issues
    TENANT ||--o{ SOURCE : defines
    TENANT ||--o{ SEARCH : runs
    TENANT ||--o{ WEBHOOK_ENDPOINT : registers
    TENANT ||--o{ TRACKED_PRODUCT : tracks
    TENANT ||--o{ USAGE_RECORD : metered
    SEARCH ||--o{ SEARCH_SOURCE : uses
    SOURCE ||--o{ SEARCH_SOURCE : "used in"
    SEARCH ||--o{ SEARCH_TASK : "pipeline steps"
    SEARCH ||--o{ OFFER : finds
    TRACKED_PRODUCT ||--o{ MONITOR : "watched by"
    MONITOR ||--o{ PRICE_SNAPSHOT : records
    MONITOR ||--o{ ALERT : raises
    WEBHOOK_ENDPOINT ||--o{ WEBHOOK_DELIVERY : sends
```

## 6. Background and scheduled work

```mermaid
flowchart TB
    SCH["scheduler"] --> MON["DispatchDueMonitorsJob<br/>re-run tracked product searches"]
    SCH --> FX["RefreshFxRatesJob<br/>ECB rates"]
    SCH --> PART["MaintainSnapshotPartitionsJob<br/>monthly price_snapshots partitions"]
    SCH --> ROLL["RollupPriceDailyJob"]
    SCH --> USE["AggregateUsageJob<br/>metering and quotas"]
    SCH --> FEED["RefreshFeedJob / RefreshSitemapJob<br/>IndexSitemapFileJob"]
    SCH --> ROB["WarmRobotsCacheJob"]
    SCH --> PUR["PurgeExpiredDataJob"]
    MON --> PIPE["Search pipeline"]
    PIPE --> AE["AlertEvaluator"] --> WH["DeliverWebhookJob"]
```
