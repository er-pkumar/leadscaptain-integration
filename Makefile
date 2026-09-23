DC   := docker compose
EXEC := $(DC) exec -u www-data app

.DEFAULT_GOAL := help
.PHONY: help setup build bootstrap up down migrate test package-test queue-up shell logs pint

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-14s %s\n", $$1, $$2}'

setup: build bootstrap up migrate ## First-time setup: build, create demo app, start, migrate

build: ## Build images
	$(DC) build

bootstrap: ## Create the Laravel demo app and link the local package
	$(DC) run --rm --no-deps -u www-data app sh scripts/bootstrap.sh

up: ## Start app, nginx and database
	$(DC) up -d --wait

down: ## Stop all containers (data is kept)
	$(DC) --profile queue --profile test down

migrate: ## Run database migrations
	$(EXEC) php artisan migrate --force

test: ## Run the demo app test suite
	$(EXEC) php artisan test

package-test: ## Run the package test suite standalone (Testbench)
	$(DC) --profile test run --rm package-tests

queue-up: ## Start Redis and Horizon
	$(DC) --profile queue up -d

shell: ## Open a shell in the app container
	$(EXEC) sh

logs: ## Follow container logs (stdout/stderr)
	$(DC) logs -f

pint: ## Format the package code with Laravel Pint
	$(DC) --profile test run --rm package-tests sh -c "composer install -q && vendor/bin/pint"
