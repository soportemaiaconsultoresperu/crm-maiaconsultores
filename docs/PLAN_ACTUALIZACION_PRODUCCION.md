# Safely update the production CRM

Use this runbook for every approved CRM update. It protects production data, preserves server-specific configuration, and makes the deployed version traceable.

> **Rule:** deploy one exact Git commit SHA. Do not deploy with `git pull`, `migrate:fresh`, or `docker compose down -v`.

## Quick path

1. Test the change locally and publish the approved commits to GitHub.
2. Create and verify a production backup.
3. On the server, preserve local configuration, check out the exact SHA, rebuild, and run `init`.
4. Confirm `init` exited successfully, start the services, and perform smoke tests.
5. Record the deployed SHA and outcome.

## Before starting

| Item | Required state |
| --- | --- |
| Change | Approved and committed to GitHub |
| Version | Exact full commit SHA recorded |
| Tests | Focused automated tests pass locally or a documented exception exists |
| Backup | Recent, verified database and storage backup exists |
| Access | SSH access to the production server works |
| Window | A maintainer is available to validate the application after deployment |

## 1. Prepare and publish the change locally

From the local repository:

```bash
# Use Laragon PHP when PHP is not on PATH.
"/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe" artisan test <FOCUSED_TEST_FILES>
git diff --check

git status --short
git add <ONLY_RELEVANT_FILES>
git commit -m "<TYPE>(<AREA>): <OUTCOME>"
git push origin main

git rev-parse HEAD
```

Copy the full SHA printed by the last command. Do not use `git add .` when unrelated local changes exist.

## 2. Create a production backup

Before changing production, create and verify a backup using the procedure in [DESPLIEGUE_PRODUCCION_UBUNTU.md](DESPLIEGUE_PRODUCCION_UBUNTU.md#6-copias-de-seguridad).

Do not continue if the backup location, timestamp, or integrity verification is unknown.

## 3. Connect and inspect the server

```bash
ssh deploy@<SERVER_HOST>
cd /var/www/crm-maia-consultores
git status --short
```

A clean working tree is ideal. If it contains changes, inspect them before deployment:

```bash
git diff -- <FILE_1> <FILE_2>
```

### Preserve intentional server configuration

Production may have local overrides such as the production domain in `docker/Caddyfile`. Preserve only the confirmed files:

```bash
git stash push -m "server-config-before-<CHANGE_NAME>" -- <CONFIRMED_SERVER_CONFIG_FILES>
```

Never stash `.env.docker`; it contains production secrets and is normally excluded from Git.

## 4. Deploy the exact version

Replace `<FULL_SHA>` with the SHA recorded in step 1:

```bash
git fetch origin
git cat-file -e <FULL_SHA>^{commit}
git checkout <FULL_SHA>
```

A detached `HEAD` is expected: it proves the server is running the exact approved commit.

Restore the server configuration only when it was intentionally preserved:

```bash
git stash pop
git status --short
```

If `git stash pop` reports a conflict, stop. Resolve the conflict deliberately before continuing.

## 5. Rebuild and migrate

```bash
docker compose --env-file .env.docker config --quiet
docker compose --env-file .env.docker up --build --wait mysql
docker compose --env-file .env.docker up --build --force-recreate init
docker compose --env-file .env.docker ps -a
```

**Gate:** `init` must show `Exited (0)`. It runs database migrations and initialization.

If it fails, do not start the application. Inspect the failure:

```bash
docker compose --env-file .env.docker logs --no-color init
```

## 6. Start services and verify

Only after `init` succeeds:

```bash
docker compose --env-file .env.docker up -d app caddy queue scheduler
docker compose --env-file .env.docker ps -a
curl -I https://crm.maiaconsultoresperu.com/login
```

Perform a smoke test for the changed feature. For example, after a Prospectos update:

- [ ] Log in successfully.
- [ ] Open the changed screen.
- [ ] Create or edit a representative record.
- [ ] Confirm the expected data persists after reload.
- [ ] Confirm no unexpected error appears in the application logs.

Inspect logs when needed:

```bash
docker compose --env-file .env.docker logs --tail=100 app queue scheduler
```

## 7. Record the deployment

Record these facts in the deployment log, issue, or team channel:

```text
Date/time:
Deployed SHA:
Change summary:
Backup location and timestamp:
Migration result: init Exited (0)
Smoke-test result:
Operator:
Follow-up or rollback notes:
```

## Failure and rollback decision

| Situation | Action |
| --- | --- |
| `init` fails before services restart | Stop, inspect `init` logs, and correct the root cause. |
| Application fails after deployment | Stop new operations, collect logs, and assess whether code rollback is safe. |
| A migration changed data/schema | Do not assume a Git rollback reverses the database. Restore only from a verified backup with an approved recovery plan. |
| Server config conflicts | Do not overwrite it. Resolve the conflict with the person who owns production configuration. |

## Never do this in production

```bash
php artisan migrate:fresh
docker compose down -v
git reset --hard
git pull
```

These commands can delete data, overwrite intentional configuration, or deploy an unreviewed version.
