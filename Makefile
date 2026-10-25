# Local stack: docker/dev (docs/local-stack.md)
DC = docker compose -f docker/dev/compose.yml

.PHONY: up down build restart logs shell migrate dump status help

help:
	@echo ""
	@echo "  CarBot local stack (docs/local-stack.md) — http://localhost:8100"
	@echo ""
	@echo "  make up        Start the stack (detached)"
	@echo "  make down      Stop the stack, keep the data"
	@echo "  make build     Rebuild the web image"
	@echo "  make restart   Restart web (runs migrations)"
	@echo "  make logs      Tail web logs"
	@echo "  make shell     Shell in the web container"
	@echo "  make migrate   Run Laravel migrations"
	@echo "  make dump      Reload the database carbot from prod (read only, needs railway login)"
	@echo "  make status    Show container status"
	@echo ""

up:
	$(DC) up -d --build

down:
	$(DC) stop

build:
	$(DC) build web

restart:
	$(DC) restart web

logs:
	$(DC) logs -f web

shell:
	$(DC) exec web sh

migrate:
	$(DC) exec web php artisan migrate --force

dump:
	bash docker/dev/load-dump.sh prod

status:
	$(DC) ps
