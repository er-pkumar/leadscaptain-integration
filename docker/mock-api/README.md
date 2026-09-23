# Mock Leadscaptain API

Stands in for `https://api.leadscaptain.com` until a real API key is available.
It serves the documented response shape, so the package code does not change
when switching to the live API.

## Start and use

```bash
docker compose --profile mock up -d --wait mock-api
```

Point the app at it in `.env`, then run `php artisan config:clear` in the container:

```env
LEADSCAPTAIN_BASE_URL=http://mock-api:8081
LEADSCAPTAIN_API_KEY=local-dev-key
```

To go live, set the real values instead:

```env
LEADSCAPTAIN_BASE_URL=https://api.leadscaptain.com
LEADSCAPTAIN_API_KEY=<real key>
```

From Windows the mock is at http://localhost:8081 (change with `FORWARD_MOCK_API_PORT`).

## Routes

| Route | Auth | Response |
|---|---|---|
| `GET /api/v1/leads?page=&limit=` | `X-API-Key: <key>` or `Authorization: Bearer <key>` | `{"data": [...], "pagination": {"page", "limit", "total", "total_pages"}}` |
| `GET /api/v1/leads/count` | same | `{"count": <total>}` |
| `GET /health` | none | `{"status": "ok"}` |
| `POST /__mock/reset` | none | Clears request counters (rate limit, flaky pages, 503 warm-up) |
| `GET /__mock/state` | none | Current settings (without the key) and counters |

Errors match the documentation: `401 {"message":"Missing or invalid API token"}`
and `503 {"message":"Database is initializing"}`. Leads are generated from their
id (`1..MOCK_TOTAL_LEADS`), so repeated runs return identical data.

## Settings (environment variables)

Set them in the shell or in `.env` before `docker compose --profile mock up -d mock-api`.

| Variable | Default | Effect |
|---|---|---|
| `MOCK_API_KEY` | `local-dev-key` | Accepted key (API-key header or Bearer) |
| `MOCK_TOTAL_LEADS` | `1234` | Number of leads |
| `MOCK_DEFAULT_LIMIT` / `MOCK_MAX_LIMIT` | `20` / `100` | Page size when `limit` is missing / upper bound |
| `MOCK_LATENCY_MS` | `0` | Delay added to every API response |
| `MOCK_FAIL_PAGES` | (none) | Comma list of pages that always return 500 |
| `MOCK_FLAKY_PAGES` | (none) | Pages that return 500 once, then succeed (retry testing) |
| `MOCK_503_FIRST_REQUESTS` | `0` | First N API requests return 503 "Database is initializing" |
| `MOCK_RATE_LIMIT` / `MOCK_RATE_WINDOW` | `0` (off) / `60` | Max requests per window; extra requests get 429 with `Retry-After` |
| `MOCK_HIDE_PAGINATION` | `0` | `1` drops the `pagination` block (tests the last-page fallbacks) |
| `MOCK_WORKERS` | `8` | Parallel PHP workers, so concurrent requests are served in parallel |

Example: rate limiting plus a flaky page:

```bash
MOCK_RATE_LIMIT=20 MOCK_FLAKY_PAGES=3 docker compose --profile mock up -d --wait mock-api
curl -X POST http://localhost:8081/__mock/reset
```
