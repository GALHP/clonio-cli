# Development Guide

## Requirements

- PHP 8.5
- Composer

## Setup

```bash
composer install
```

## Running locally

```bash
php clonio <command>
```

## Building a local PHAR

```bash
composer build
```

This runs `./clonio app:build clonio --no-interaction` and produces `builds/clonio`.

The version embedded in the PHAR is resolved from the latest git tag via `git describe`. If no tag exists yet, it will fall back to `UNRELEASED`.

> The `builds/` directory is git-ignored. The PHAR is not committed to the repository.

## Testing

Run the full test suite:

```bash
composer test
```

Individual stages:

| Command                    | What it does                              |
|----------------------------|-------------------------------------------|
| `composer test:unit`       | PestPHP with coverage (min 85%)           |
| `composer test:type-coverage` | Type coverage check (min 90%)          |
| `composer test:types`      | PHPStan static analysis (level max)       |
| `composer test:lint`       | Pint code style + Rector dry-run          |

## Linting / auto-fix

```bash
composer lint
```

Runs Rector and Pint in fix mode (modifies files in place).

## Docker (no local PHP)

Instead of installing PHP and Composer locally, you can run everything in a
container. The only requirement is Docker with Compose v2.

The image is the `dev` stage of the `Dockerfile`: `php:8.5-cli-alpine` with the
same PHP extensions as the published Docker image, plus Composer, git and Xdebug.
The repository is bind-mounted to `/app`, so changes on the host are visible
immediately.

| Make target        | Raw command (e.g. on Windows without make)                      |
|--------------------|-----------------------------------------------------------------|
| `make dev-build`   | `docker compose build php`                                      |
| `make dev-install` | `docker compose run --rm php composer install`                  |
| `make dev-shell`   | `docker compose run --rm php sh`                                |
| `make dev-test`    | `docker compose run --rm -e XDEBUG_MODE=coverage php composer test` |
| `make dev-lint`    | `docker compose run --rm php composer lint`                     |

Any other command works the same way, e.g.
`docker compose run --rm php php clonio connection:test`.

Notes:

- **File ownership**: the container entrypoint detects the owner of the
  repository and runs the command as that user, so `vendor/`, caches and
  coverage reports belong to you on Linux, macOS and Windows alike. Under
  rootless Docker/Podman the command runs as root, which maps back to your user.
- **Always use `run --rm`**, not `docker compose exec`: `exec` bypasses the
  entrypoint and would run as root.
- **Xdebug** is loaded but off (`XDEBUG_MODE=off`) to keep PHPStan and tests
  fast. `make dev-test` enables coverage mode; pass `-e XDEBUG_MODE=debug` for
  step debugging.
- **Windows**: keep the checkout inside the WSL2 filesystem (not `/mnt/c/...`)
  — bind mounts from Windows drives are slow.
