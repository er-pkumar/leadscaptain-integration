# Leadscaptain: architecture and flows

Reference for the `packages/leadscaptain` package and the demo app that hosts it.
Diagrams are Mermaid (rendered by GitHub and the VS Code Markdown preview).

**Status legend** used in the tables:

- ✅ exists in the repo
- 🟡 described in the plan, not in the repo yet
- ❓ open decision (see [Open decisions](#10-open-decisions))

---

## 1. System context (runtime)

```mermaid
flowchart LR
    user([Operator / reviewer])
    api[(Leadscaptain API<br/>api.leadscaptain.com)]
    grpc[[gRPC microservices<br/>future]]
    alert[[leadscaptain log channel<br/>stderr JSON]]
    mock[(Mock Leadscaptain API<br/>mock-api:8081<br/>profile: mock)]

    subgraph docker["docker compose: leadscaptain"]
        nginx["nginx :8080"]
        app["app<br/>PHP 8.4-FPM<br/>Laravel 12 + package"]
        horizon["horizon<br/>queue workers<br/>profile: queue"]
        db[("MySQL 8.4<br/>db:3306 / host 3307")]
        redis[("Redis 7<br/>redis:6379 / host 6380<br/>profile: queue")]
    end

    user -- "HTTP /api/leadscaptain/*" --> nginx
    user -- "artisan leadscaptain:sync" --> app
    nginx -- "FastCGI :9000" --> app
    app -- "dispatch orchestrator job" --> redis
    horizon -- "pull jobs" --> redis
    horizon -- "GET /api/v1/leads<br/>X-API-Key" --> api
    horizon -- "rate-limit timestamps" --> redis
    horizon -- "upsert leads, job_batches" --> db
    app -- "read leads, batch progress" --> db
    horizon -. "LeadSyncFailed notification (log)" .-> alert
    horizon -. "until a key exists:<br/>LEADSCAPTAIN_BASE_URL=http://mock-api:8081" .-> mock
    horizon -. "domain events (stub)" .-> grpc
```

| Container | Image / target | Role |
|---|---|---|
| `app` | `Dockerfile` target `dev` (bind mount) / `prod` (baked) | PHP-FPM, artisan, HTTP endpoints |
| `nginx` | `nginx:1.27-alpine` | Serves `public/`, forwards `.php` to `app:9000` |
| `db` | `mysql:8.4` | Leads, `job_batches`, `jobs`, `failed_jobs`, sessions, cache |
| `redis` | `redis:7-alpine` | Queue, Horizon state, rate-limiter window |
| `horizon` | same image as `app` | Runs `SyncLeadsOrchestratorJob` and `ImportLeadPageJob` |
| `package-tests` | `packages/leadscaptain/Dockerfile` | Standalone Testbench suite (no host app) |
| `mock-api` | `php:8.4-cli-alpine` + `docker/mock-api/router.php` | Offline stand-in for the API (documented shape, simulated 401/429/500/503); profile `mock` |

---

## 2. Application architecture (Onion / DDD)

Dependencies point inwards only. `LayerBoundariesTest` enforces: Domain and Application are
framework-free (no Illuminate, Laravel, Guzzle or helpers such as `config()`), Application
never imports Infrastructure/Presentation, and Presentation never imports Infrastructure.

```mermaid
flowchart TB
    subgraph P["Presentation (Laravel)"]
        cmd["SyncLeadsCommand<br/>leadscaptain:sync {--now}"]
        ctrl["LeadsController<br/>SyncController"]
        res["LeadResource"]
    end

    subgraph I["Infrastructure (Laravel adapters)"]
        http["LeadscaptainHttpClient<br/>Http + Http::pool"]
        rl["RedisRateLimiter"]
        repo["EloquentLeadRepository<br/>LeadModel"]
        sched["BatchPageImportScheduler<br/>Bus::batch"]
        jobs["SyncLeadsOrchestratorJob<br/>ImportLeadPageJob"]
        pub["LaravelDomainEventPublisher<br/>GrpcDomainEventPublisher stub"]
        notif["LeadSyncFailedNotification"]
        sp["LeadscaptainServiceProvider<br/>bindings, SyncSettings, migrations, routes"]
    end

    subgraph A["Application (framework-free)"]
        uc["Use cases<br/>StartLeadSync<br/>ImportLeadPage<br/>SyncLeadsImmediately"]
        svc["Services<br/>LastPageResolver<br/>ConcurrentPageImporter"]
        map["LeadMapper"]
        ports["Ports<br/>LeadsApiClient<br/>PageImportScheduler<br/>DomainEventPublisher"]
        dto["DTOs + SyncSettings<br/>RawPage, PaginationMeta, ..."]
    end

    subgraph D["Domain (pure PHP)"]
        lead["Lead, LeadCollection<br/>ProfileKey, Email, CountryCode"]
        repoI["LeadRepository interface"]
        sync["SyncId, PageNumber, SyncStatus"]
        ev["DomainEvent<br/>LeadsPageImported<br/>LeadSyncCompleted<br/>LeadSyncFailed"]
    end

    P --> A
    I --> A
    I --> D
    A --> D

    http -. implements .-> ports
    sched -. implements .-> ports
    pub -. implements .-> ports
    repo -. implements .-> repoI
    jobs --> uc
```

### Components by layer

| Layer | Component | Responsibility | Status |
|---|---|---|---|
| Domain | `Lead`, `LeadCollection`, value objects | Lead identity (`ProfileKey`), normalisation, dedup (last one wins) | ✅ |
| Domain | `LeadRepository` | `upsertMany()` (idempotent), `findByProfileKey()`, `count()` | ✅ |
| Domain | `SyncId`, `PageNumber`, `SyncStatus` | Sync identity, page arithmetic, allowed status transitions | ✅ |
| Domain | `LeadsPageImported`, `LeadSyncCompleted`, `LeadSyncFailed` | Events with primitive payloads (gRPC-ready) | ✅ |
| Application | `LeadsApiClient` port | `fetchPage`, `fetchPages(...)` → `RawPage\|PageFetchFailure` per page, `countLeads` | ✅ |
| Application | `PageImportScheduler` port | `schedule(SyncId, PageNumber ...)` | ✅ |
| Application | `DomainEventPublisher` port | Publish domain events to any transport | ✅ |
| Application | `SyncSettings` | `pageSize`, `maxPages`, `concurrency` (no `config()` in Application) | ✅ |
| Application | `LeadMapper` | Raw record → `Lead`; aliases; skip records without a key; drop invalid email/country | ✅ |
| Application | `LastPageResolver` | Work out the last page (see §4.3) | ✅ |
| Application | `ConcurrentPageImporter` | Import pages in windows of `concurrency` via `fetchPages` | ✅ |
| Application | `StartLeadSync` | Orchestrator: page 1 → resolve → schedule 2..N (or import inline) | ✅ |
| Application | `ImportLeadPage` | Fetch → map → upsert → publish `LeadsPageImported` | ✅ |
| Application | `SyncLeadsImmediately` | `--now` path, no queue | ✅ |
| Infrastructure | `LeadscaptainHttpClient`, `ApiSettings`, `RetryPolicy` | Base URL, `X-API-Key` or Bearer, timeouts, `page`/`limit`, per-attempt logging; `fetchPages` pools, rate-limits and retries | ✅ step 4 |
| Infrastructure | `RedisRateLimiter` (`RequestRateLimiter`) | Sliding window in a Redis sorted set (atomic Lua, Redis clock): `attempt()`, `availableIn()` | ✅ step 4 |
| Infrastructure | `LeadModel`, `EloquentLeadRepository`, migration | `upsert()` on `profile_key` in chunks of 500, one transaction; loaded via `loadMigrationsFrom` | ✅ step 5 |
| Infrastructure | Jobs, `BatchPageImportScheduler`, publishers, notification | Queue, batch lifecycle, events, alerts | 🟡 step 6 |
| Infrastructure | `LeadscaptainServiceProvider` | Config, log channel ✅; bindings, migrations, commands, routes 🟡 | ✅/🟡 |
| Presentation | `leadscaptain:sync {--now}`, 3 routes, `LeadResource` | Entry points; call Application only | 🟡 step 7 |

### Port → adapter bindings (service provider)

| Contract | Bound to |
|---|---|
| `Application\Contract\LeadsApiClient` | `Infrastructure\Http\LeadscaptainHttpClient` |
| `Application\Contract\PageImportScheduler` | `Infrastructure\Queue\BatchPageImportScheduler` |
| `Application\Contract\DomainEventPublisher` | `Infrastructure\Events\LaravelDomainEventPublisher` (gRPC stub alongside it) |
| `Domain\Lead\LeadRepository` | `Infrastructure\Persistence\EloquentLeadRepository` |
| Rate limiter interface | `Infrastructure\RateLimit\RedisRateLimiter` |
| `Application\Config\SyncSettings` | Built from `config('leadscaptain.*')` |

---

## 3. Data architecture

### 3.1 Data pipeline: API → domain → database

```mermaid
flowchart LR
    json["API JSON page<br/>data[] + pagination{}"] --> raw["RawPage<br/>records + PaginationMeta"]
    raw --> mapper{"LeadMapper<br/>aliases"}
    mapper -- "no profile key" --> skip["SkippedRecord<br/>counted, logged"]
    mapper -- "valid" --> leadN["Lead<br/>invalid email/country → null"]
    leadN --> coll["LeadCollection<br/>dedup by profile_key"]
    coll --> mapped["MappedLeads"]
    mapped --> upsert["LeadRepository::upsertMany"]
    upsert --> table[("leadscaptain_leads<br/>UNIQUE profile_key")]
    upsert --> evt["LeadsPageImported<br/>imported / skipped counts"]
```

Field mapping, based on the **documented sample response** (not yet checked
against the live API). `LeadMapper::ALIASES` keeps the other names as a fallback
until a live response confirms the shape.

Sample page (`GET /api/v1/leads?page=1&limit=20`):

```json
{
  "data": [
    {
      "id": 123,
      "first_name": "John",
      "last_name": "Doe",
      "email": "john.doe@example.com",
      "position_title": "Senior Developer",
      "company_name": "ABC Technologies",
      "country_code": "IN",
      "industry_name": "Technology",
      "email_status": "verified"
    }
  ],
  "pagination": { "page": 1, "limit": 20, "total": 1, "total_pages": 1 }
}
```

| API field (sample) | Other accepted names | Domain | Column |
|---|---|---|---|
| `id` (int) ✅ decided | `profile_key`, `PROFILE_KEY` | `ProfileKey` (cast to string, trimmed, ≤ 191) | `profile_key` VARCHAR(191) UNIQUE |
| `first_name` + `last_name` | `full_name`, `name` | `fullName` = trimmed "first last", blank → null | `full_name` |
| `email` | | `Email` (lower-case; invalid → null) | `email` |
| `position_title` | `title` | `positionTitle` | `position_title` |
| `company_name` | `company` | `companyName` | `company_name` |
| `industry_name` | `industry` | `industry` | `industry` |
| (not in sample) | `location`, `city` | `location` → null | `location` |
| `country_code` | `country` | `CountryCode` (ISO alpha-2, upper; invalid → null) | `country_code` CHAR(2) |
| `email_status` ✅ decided | | kept in `attributes` only | inside `attributes` |
| whole record | | `attributes` (includes `first_name`, `last_name`, `email_status`) | `raw_attributes` JSON (renamed: `attributes` clashes with Eloquent) |
| (sync context) | | not passed to the repository ❓ | `last_synced_at` (set on every upsert); `last_sync_id` not added yet |

Pagination block → `PaginationMeta`:

| Sample field | Other accepted names | `PaginationMeta` |
|---|---|---|
| `pagination.total_pages` | `meta.last_page`, `last_page` | `lastPage` |
| `pagination.total` | `meta.total`, `total` | `total` |
| `pagination.limit` | `meta.per_page`, `per_page` | `perPage` |
| `pagination.page` | `meta.current_page`, `current_page` | `currentPage` |

Response codes:

| Status | Body | Handling |
|---|---|---|
| `200` | `data[]` + `pagination{}` | Map and upsert |
| `401` | `{"message":"Missing or invalid API token"}` | **Not retried**: fail the sync at once, log it, send the notification |
| `429` | (not in the doc; required by the spec) | Retry: `Retry-After`, else 1s / 2s / 4s |
| `503` | `{"message":"Database is initializing"}` | Retry: 1s / 5s / 30s |
| other `5xx`, timeout | | Retry: 1s / 5s / 30s |
| other `4xx` | | Not retried: page fails |

Authentication: `apiKeyAuth` or `bearerAuth`. The API-key header name is not in
the doc (the live spec says `X-API-Key`), so both the scheme and the header name are
configurable: `LEADSCAPTAIN_AUTH_SCHEME` (`api_key` by default, or `bearer`) and
`LEADSCAPTAIN_API_KEY_HEADER` (default `X-API-Key`).

Filters (`q`, `position_title`, `company_name`, `country_code`, `industry_name`,
`email_status`) exist, but the assignment is "fetch **all** leads", so the sync
sends only `page` and `limit`. Supporting filters is optional, later work.

### 3.2 Storage model

```mermaid
erDiagram
    LEADSCAPTAIN_LEADS {
        bigint id PK
        varchar profile_key UK "191, upsert key"
        varchar full_name "nullable"
        varchar email "nullable"
        varchar position_title "nullable"
        varchar company_name "nullable"
        varchar industry "nullable"
        varchar location "nullable"
        char country_code "2, nullable"
        json raw_attributes "full source record"
        timestamp last_synced_at "nullable"
        timestamp created_at
        timestamp updated_at
    }
    JOB_BATCHES {
        varchar id PK "SyncId"
        varchar name "leadscaptain:{syncId}"
        int total_jobs
        int pending_jobs
        int failed_jobs
        longtext failed_job_ids
        mediumtext options
        int cancelled_at "nullable"
        int created_at
        int finished_at "nullable"
    }
    FAILED_JOBS {
        bigint id PK
        varchar uuid UK
        text connection
        text queue
        longtext payload
        longtext exception
        timestamp failed_at
    }
    JOB_BATCHES ||..o{ LEADSCAPTAIN_LEADS : "imports (no stored link yet)"
    JOB_BATCHES ||--o{ FAILED_JOBS : "failed_job_ids"
```

| Store | Key / table | Contents | Owner |
|---|---|---|---|
| MySQL | `leadscaptain_leads` | Leads (idempotent upsert) | Package migration (`loadMigrationsFrom`) |
| MySQL | `job_batches`, `jobs`, `failed_jobs` | Batch progress and failures | Laravel host migrations ✅ |
| MySQL | `sessions`, `cache`, `cache_locks` | Laravel defaults (need `migrate`) | Host ✅ |
| Redis | Rate-limit sorted set (key name ❓, e.g. `leadscaptain:rate_limit`) | Request timestamps within `window_seconds` | `RedisRateLimiter` |
| Redis | `queues:leadscaptain` + Horizon keys | Pending jobs, metrics | Laravel / Horizon |
| stderr | `leadscaptain` log channel (JSON lines) | One line per attempt: page, status, duration_ms, attempt, sync_id | Service provider ✅ |

**Idempotency:** `LeadCollection` removes duplicates within a page, and `upsert()` on
`profile_key` removes them across pages and re-runs. A retried job rewrites the same
rows, and `created_at` is excluded from the update columns.

---

## 4. Flows

### 4.1 Queued sync, end to end (default path)

```mermaid
sequenceDiagram
    autonumber
    actor Op as Operator
    participant Cmd as leadscaptain:sync / POST /sync
    participant Q as Redis queue
    participant Orc as SyncLeadsOrchestratorJob
    participant Start as StartLeadSync
    participant Api as LeadsApiClient
    participant Res as LastPageResolver
    participant Sch as BatchPageImportScheduler
    participant Job as ImportLeadPageJob x N
    participant Imp as ImportLeadPage
    participant Repo as LeadRepository
    participant Pub as DomainEventPublisher

    Op->>Cmd: run
    Cmd->>Q: dispatch orchestrator (via Application port ❓)
    Cmd-->>Op: sync id (202 / console), returns at once
    Q->>Orc: handle()
    Orc->>Start: execute(syncId)
    Start->>Api: fetchPage(1)
    Api-->>Start: RawPage
    Start->>Imp: import page 1
    Imp->>Repo: upsertMany
    Imp->>Pub: LeadsPageImported(page 1)
    Start->>Res: resolve(RawPage, settings)
    Res-->>Start: LastPageResolution(strategy, lastPage)
    alt last page known and > 1
        Start->>Sch: schedule(syncId, 2..N)
        Sch->>Q: Bus::batch(N-1 jobs) on queue "leadscaptain"
        par Horizon workers (concurrency 10–20)
            Q->>Job: handle(page k)
            Job->>Imp: execute(syncId, page k)
            Imp->>Api: fetchPage(k)
            Imp->>Repo: upsertMany
            Imp->>Pub: LeadsPageImported(page k)
        end
        Note over Sch,Pub: then → LeadSyncCompleted · catch → LeadSyncFailed + notification · finally → log
    else last page unknown
        Start->>Start: ConcurrentPageImporter inline until empty/short page (§4.4)
    else only one page
        Start->>Pub: LeadSyncCompleted
    end
```

### 4.2 `ImportLeadPageJob`: rate limit and retry decisions

```mermaid
flowchart TD
    start([Job picked up]) --> cancelled{"batch cancelled?"}
    cancelled -- yes --> stop([return, do nothing])
    cancelled -- no --> slot{"RateLimiter attempt()"}
    slot -- "no slot" --> rel1["release(availableIn())<br/>never sleep()"]
    slot -- ok --> call["ImportLeadPage → fetchPage(k)"]
    call --> ok{"2xx?"}
    ok -- yes --> done([upsert + LeadsPageImported])
    ok -- no --> kind{"LeadsApiException"}
    kind -- "429" --> ra["delay = Retry-After<br/>else 1s / 2s / 4s"]
    kind -- "5xx / 503 / timeout" --> bo["delay = 1s / 5s / 30s"]
    kind -- "other 4xx" --> fail
    ra --> left{"retries left?<br/>max 3 ❓"}
    bo --> left
    left -- yes --> rel2["release(delay)"]
    left -- no --> fail["job fails"]
    fail --> batchfail["batch catch():<br/>batch failed + LeadSyncFailed<br/>+ LeadSyncFailedNotification"]
```

> ❓ Every `release()` increments `attempts()`. With a plain `$tries = 3`, time spent
> waiting for the rate limiter would use up retries. Planned approach: `retryUntil()`
> plus a separate count of real API failures (max 3).

### 4.3 `LastPageResolver`

```mermaid
flowchart TD
    p1["RawPage (page 1)"] --> a{"pagination.total_pages?<br/>(alias meta.last_page)"}
    a -- yes --> known
    a -- no --> b{"pagination.total and limit?"}
    b -- yes --> calc["ceil(total / limit)"] --> known
    b -- no --> c{"records < pageSize?"}
    c -- yes --> one["last page = 1"] --> known
    c -- no --> d{"countLeads() != null?"}
    d -- yes --> calc2["ceil(count / pageSize)"] --> known
    d -- no --> unk(["Unknown"])
    known["lastPage"] --> cap["min(lastPage, maxPages)"] --> res(["LastPageResolution(strategy, lastPage)"])
```

### 4.4 Direct path: `--now` or unknown last page (`ConcurrentPageImporter`)

```mermaid
flowchart TD
    s([start at page 2]) --> win["window = next 'concurrency' pages"]
    win --> rl["take one rate-limit slot per request ❓"]
    rl --> pool["LeadsApiClient::fetchPages(window)<br/>Http::pool"]
    pool --> each["for each page: RawPage or PageFetchFailure"]
    each --> imp["map + upsert + LeadsPageImported"]
    imp --> finished{"known range done<br/>or empty/short page<br/>or maxPages hit?"}
    finished -- no --> win
    finished -- yes --> rep(["RangeImportResult → SyncReport"])
```

### 4.5 Sync status lifecycle (`SyncStatus`)

```mermaid
stateDiagram-v2
    [*] --> Pending: sync requested
    Pending --> Running: orchestrator starts
    Pending --> Failed: page 1 fails after retries
    Running --> Completed: batch then()
    Running --> Failed: batch catch()
    Completed --> [*]
    Failed --> [*]
```

### 4.6 Domain events → transports

```mermaid
flowchart LR
    uc["Use cases / batch callbacks"] --> port["DomainEventPublisher port"]
    port --> lar["LaravelDomainEventPublisher<br/>event dispatcher → listeners, logs"]
    port --> grpc["GrpcDomainEventPublisher stub<br/>logs payload; proto ❓"]
```

| Event | `eventName()` | Payload |
|---|---|---|
| `LeadsPageImported` | `leadscaptain.leads_page_imported` | `sync_id, page, imported_count, skipped_count, occurred_at` |
| `LeadSyncCompleted` | `leadscaptain.lead_sync_completed` | `sync_id, total_pages, total_leads, occurred_at` |
| `LeadSyncFailed` | `leadscaptain.lead_sync_failed` | `sync_id, reason, failed_page, occurred_at` |

---

## 5. Entry points (Presentation)

| Entry | Behaviour | Calls |
|---|---|---|
| `php artisan leadscaptain:sync` | Queue orchestrator, print sync id | Application (port for dispatch ❓) |
| `php artisan leadscaptain:sync --now` | Run in process, print summary table | `SyncLeadsImmediately` |
| `GET /api/leadscaptain/leads` | Paginated `LeadResource` | Read use case / query ❓ |
| `POST /api/leadscaptain/sync` | `202` + sync id | Same as the command |
| `GET /api/leadscaptain/sync/{batchId}` | Batch progress | Batch status query ❓ |

Route prefix is configurable and uses the `api` middleware.

---

## 6. Configuration → runtime

```mermaid
flowchart LR
    env[".env LEADSCAPTAIN_*"] --> cfg["config/leadscaptain.php<br/>(published)"]
    cfg --> sp["Service provider"]
    sp --> ss["SyncSettings<br/>pageSize, maxPages, concurrency"]
    sp --> httpc["HTTP client<br/>base_url, api_key, paths, params, timeouts"]
    sp --> rlc["RedisRateLimiter<br/>max_requests, window_seconds, redis_connection"]
    sp --> jobc["Jobs<br/>retry times, backoff_ms, rate_limit_backoff_ms, queue"]
    sp --> logc["Log channel<br/>channel, stream, level"]
    sp --> nc["Failure notification<br/>log channel (mail later)"]
```

---

## 7. Logging (one JSON line per attempt)

```json
{"message":"leadscaptain.page_attempt","context":{"sync_id":"9c1e…","page":7,"attempt":2,"status":503,"duration_ms":412},"level_name":"WARNING","channel":"leadscaptain"}
```

---

## 8. Build order

| Step | Scope | Main artefacts |
|---|---|---|
| 1–3 | Domain, Application, test fakes | ✅ Domain, Application, `tests/Support` fakes |
| 4 | Infrastructure / HTTP + rate limit | `LeadscaptainHttpClient`, `RedisRateLimiter` |
| 5 | Infrastructure / persistence | migration, `LeadModel`, `EloquentLeadRepository` |
| 6 | Infrastructure / queue, events, notifications, wiring | jobs, scheduler, publishers, notification, provider bindings |
| 7 | Presentation | command, controllers, resource, routes |
| 8 | CI | `.github/workflows/ci.yml` (Pint, PHPStan, PHPUnit ≥ 90%, MySQL + Redis, prod build, deploy) |
| 9 | Docs and evidence | README, real API run output |

---

## 9. Architecture rules (summary)

- **Domain:** pure PHP. Nothing from `Illuminate\`, `Laravel\`, `GuzzleHttp\` or outer layers.
- **Application:** Domain only. No facades or `config()`; settings arrive via `SyncSettings`.
- **Infrastructure:** implements Application ports and Domain interfaces with Laravel.
- **Presentation:** calls Application use cases only.
- Every `src/` file declares `strict_types`.

---

## 10. Open decisions

| # | Topic | Options / note |
|---|---|---|
| 1 | Real `/api/v1/leads` response shape | Documented sample: `data[]` + `pagination{page, limit, total, total_pages}`. Confirm against the live API, then narrow `LeadMapper::ALIASES` and the fixtures; Domain unchanged |
| 2 | Unique key | ✅ Decided: `id` from the response is the profile key (`profile_key`/`PROFILE_KEY` only as fallbacks) |
| 2a | `email_status` | ✅ Decided: kept in `attributes` only; Domain unchanged |
| 2c | Developing without an API key | ✅ Decided: a mock Leadscaptain API container serves the documented shape; going live = change `LEADSCAPTAIN_BASE_URL` and `LEADSCAPTAIN_API_KEY` |
| 2d | Failure notification channel | ✅ Decided: `LeadSyncFailedNotification` writes to the `leadscaptain` log channel for now; mail (`LEADSCAPTAIN_ALERT_MAIL`) can be added later |
| 2b | Auth scheme / header | ✅ Decided: `LEADSCAPTAIN_AUTH_SCHEME` = `api_key` (default) or `bearer`, `LEADSCAPTAIN_API_KEY_HEADER` (default `X-API-Key`) |
| 3 | `release()` counts as an attempt | `retryUntil()` + own count of API failures (max 3) |
| 4 | `last_sync_id` source | Open: the column is left out. `upsertMany()` has no `SyncId`; adding one is a Domain interface change (needs approval) plus one migration. `last_synced_at` is stored meanwhile |
| 5 | `Http::pool` and the rate limiter | ✅ Decided: `fetchPages()` takes one slot per request and retries itself; `fetchPage()` leaves both to the page job |
| 6 | Presentation → queue dispatch | The command must not import Infrastructure: needs an Application port/use case to start a sync, plus read ports for leads and batch status |
| 7 | Orchestrator runtime when the last page is unknown | Raise job and Horizon `timeout` for the `leadscaptain` queue |
| 8 | Deploy target | Placeholder CI job until a target is given |
| 9 | gRPC contract | Stub publisher until a `.proto` is provided |
