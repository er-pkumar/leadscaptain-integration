# laravel-leadscaptain

Laravel package that fetches all leads from the Leadscaptain API, requesting
pages concurrently and processing them through queued jobs.

## Layout (Onion / DDD)

```
src/
├── Domain/          Lead entity, value objects, repository contract, domain events
├── Application/     Use cases, DTOs, mappers
├── Infrastructure/  HTTP client, persistence, jobs, rate limiter
├── Presentation/    Console commands, controllers, API resources
└── LeadscaptainServiceProvider.php
```

The Domain layer must not import anything from `Illuminate\`.

## Running the tests standalone

```bash
docker build -t leadscaptain-package .
docker run --rm leadscaptain-package
```

Or from the demo app root: `make package-test`.
