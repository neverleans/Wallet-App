.PHONY: setup up down restart logs queue-logs migrate fresh-migrate test shell

setup:
	@echo "🚀 Iniciando ambiente com Docker Compose..."
	@test -f .env || cp .env.example .env
	docker compose up -d --build
	docker compose exec app composer install
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate --force
	@echo "🧪 Executando testes..."
	docker compose exec app php artisan test
	@echo "✅ Ambiente pronto! API disponível em http://localhost:8000"

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f app

queue-logs:
	docker compose logs -f queue

migrate:
	docker compose exec app php artisan migrate

fresh-migrate:
	docker compose exec app php artisan migrate:fresh --seed

test:
	docker compose exec app php artisan test

shell:
	docker compose exec app sh
