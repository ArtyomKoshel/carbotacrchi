ifeq ($(OS),Windows_NT)
    COPY_ENV = if not exist .env copy .env.example .env
else
    COPY_ENV = test -f .env || cp .env.example .env
endif

.PHONY: up down build restart logs shell migrate fresh status help

help:
	@echo ""
	@echo "  CarBot — Telegram Auction Mini App"
	@echo ""
	@echo "  make up        Start all containers (detached)"
	@echo "  make down      Stop and remove containers"
	@echo "  make build     Rebuild images (no cache)"
	@echo "  make restart   Restart PHP + Nginx"
	@echo "  make logs      Tail logs from all services"
	@echo "  make shell     Open shell in PHP container"
	@echo "  make migrate   Run Laravel migrations"
	@echo "  make fresh     Fresh migration (drops all tables)"
	@echo "  make status    Show container status"
	@echo ""

up:
	$(COPY_ENV)
	docker compose up -d

down:
	docker compose down

build:
	docker compose build --no-cache

restart:
	docker compose restart php nginx

logs:
	docker compose logs -f

shell:
	docker compose exec php sh

migrate:
	docker compose exec php php artisan migrate --force

fresh:
	docker compose exec php php artisan migrate:fresh --force

status:
	docker compose ps
