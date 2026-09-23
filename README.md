# Leadscaptain demo app

A Laravel 12 app that hosts the local `packages/leadscaptain` package and runs
the whole stack (PHP 8.4-FPM, Nginx, MySQL, and optionally Redis and Horizon)
in Docker.

## Prerequisites

- Docker Desktop (WSL 2 engine on Windows) with Docker Compose v2
- `make` (optional; raw commands are listed below)

## First-time setup

```bash
make setup
```

Without `make`:

```bash
docker compose build
docker compose run --rm --no-deps -u www-data app sh scripts/bootstrap.sh
docker compose up -d --wait
docker compose exec -u www-data app php artisan migrate
```

`scripts/bootstrap.sh` creates the Laravel skeleton, points `.env` at the
Docker services, links the package through a Composer path repository and
publishes its config. It is safe to re-run.

Open http://localhost:8080.

Then put your key in `.env`:

```env
LEADSCAPTAIN_API_KEY=your-key-here
```

## Everyday commands

| Task | make | Raw command |
|---|---|---|
| Start | `make up` | `docker compose up -d --wait` |
| Stop | `make down` | `docker compose down` |
| App tests | `make test` | `docker compose exec -u www-data app php artisan test` |
| Package tests | `make package-test` | `docker compose --profile test run --rm package-tests` |
| Queue stack | `make queue-up` | `docker compose --profile queue up -d` |
| Shell | `make shell` | `docker compose exec -u www-data app sh` |
| Logs | `make logs` | `docker compose logs -f` |

Always run `composer` and `artisan` inside the container. `DB_HOST=db` only
resolves inside Docker, and path-repository symlinks created on Windows do
not work in Linux containers.

## Structure

```
.
├── packages/leadscaptain/   reusable package (own Dockerfile + tests)
├── docker/                  nginx and php config
├── scripts/bootstrap.sh     demo app setup
├── Dockerfile               app image (base / dev / prod targets)
├── docker-compose.yml       app, nginx, db (+ redis, horizon, package-tests profiles)
└── Makefile
```

## Production image

```bash
docker build --target prod -t leadscaptain-app .
```

## API notes

The live OpenAPI spec documents `GET /api/v1/leads` with `page` and `limit`
query parameters. The path and parameter names are configurable through
`LEADSCAPTAIN_LEADS_PATH` and `LEADSCAPTAIN_PAGE_SIZE_PARAM`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Port already allocated | Set `APP_PORT` or `FORWARD_DB_PORT` in `.env` |
| `db` never healthy | `docker compose logs db`; after changing DB passwords run `docker compose down -v` |
| 502 Bad Gateway | `docker compose logs app` |
| Permission denied on `storage/` | `docker compose exec app chmod -R 775 storage bootstrap/cache` |
