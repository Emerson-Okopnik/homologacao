.PHONY: up down build install key migrate fresh seed test test-coverage lint lint-fix analyse fe-install fe-test fe-lint fe-typecheck fe-e2e check logs shell

DC = docker compose
ART = $(DC) exec app php artisan
TEST_ENV = -e APP_ENV=testing -e DB_DATABASE=homologa_test -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array -e SANCTUM_STATEFUL_DOMAINS=localhost

up:
	$(DC) up -d

down:
	$(DC) down

build:
	$(DC) build

install:
	cp -n backend/.env.example backend/.env || true
	$(DC) run --rm app composer install
	$(DC) run --rm app php artisan key:generate

migrate:
	$(ART) migrate

fresh:
	$(ART) migrate:fresh --seed

seed:
	$(ART) db:seed

test:
	$(DC) exec -T $(TEST_ENV) app php artisan test

test-coverage:
	$(DC) exec -T $(TEST_ENV) app php -d pcov.enabled=1 artisan test --coverage --min=80

lint:
	$(DC) exec app vendor/bin/pint --test

lint-fix:
	$(DC) exec app vendor/bin/pint

analyse:
	$(DC) exec app vendor/bin/phpstan analyse --memory-limit=1G

fe-install:
	cd frontend && pnpm install

fe-test:
	cd frontend && pnpm test

fe-lint:
	cd frontend && pnpm lint && pnpm format:check

fe-typecheck:
	cd frontend && pnpm typecheck

fe-e2e:
	cd frontend && pnpm e2e

check: lint analyse test fe-lint fe-typecheck fe-test

logs:
	$(DC) logs -f app queue nginx

shell:
	$(DC) exec app bash
