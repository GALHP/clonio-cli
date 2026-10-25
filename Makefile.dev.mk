# ──────────────────────────────────────────────────────────────────────────────
# Dev environment — `dev` stage of the Dockerfile via compose.yaml. Every target
# uses `run --rm` (not `exec`) so the entrypoint maps the container user to the
# owner of the repository; no long-running container needed.
# ──────────────────────────────────────────────────────────────────────────────

.PHONY: dev-build dev-install dev-shell dev-test dev-lint

DEV_RUN = docker compose run --rm

dev-build:
	docker compose build php

dev-install:
	$(DEV_RUN) php composer install

dev-shell:
	$(DEV_RUN) php sh

dev-test:
	$(DEV_RUN) -e XDEBUG_MODE=coverage php composer test

dev-lint:
	$(DEV_RUN) php composer lint
