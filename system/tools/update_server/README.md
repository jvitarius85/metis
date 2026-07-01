# Metis Update Server

This scaffold provides the first-pass Metis update server implementation:

- installation registration with per-install RSA keypairs
- signed update checks
- installation-scoped custom module visibility
- tokenized package downloads
- signed package manifests
- file-level `core_full`, `core_delta`, `module_full`, and `module_delta` packages
- admin operations dashboard with verify/publish/GitHub release controls
- password login, PAM-backed server-account login, and one-time shell-user login links
- CLI install and publish tooling

## Layout

- `public/`
  - `index.php` routes JSON APIs and the basic admin UI
  - `download.php` serves tokenized package downloads
- `src/`
  - `App.php` request routing and application logic
  - `bootstrap.php` shared app bootstrap
- `cli/`
  - `install.php` writes config, creates keys, and provisions schema
  - `trigger-cron.php` signs and dispatches cron triggers to registered installations
  - `install-auth-helper.php` compiles and installs the PAM auth helper
  - `admin-map-system-user.php` maps an admin account to a shell username
  - `admin-link.php` mints a one-time browser login link for a mapped shell user
  - `verify.php` runs the release publish verification gate
  - `publish.php` builds signed packages and stores release metadata
- `sql/schema.sql`
  - MariaDB schema

## Install

Run this on the update server after copying the scaffold into `/var/www/update.vitarius.org`:

```bash
php cli/install.php \
  --dsn='mysql:host=127.0.0.1;dbname=metis_updates;charset=utf8mb4' \
  --db-user='metis_updates' \
  --db-pass='replace-me' \
  --base-url='https://update.vitarius.org' \
  --admin-email='admin@example.com' \
  --admin-password='replace-me' \
  --system-user='server-login-user'
```

This creates:

- `storage/config.php`
- server signing keypair in `storage/keys/`
- database schema from `sql/schema.sql`
- initial admin user

If you did not pass `--system-user`, you can map it later:

```bash
php cli/admin-map-system-user.php --email='admin@example.com' --system-user='server-login-user'
```

Then mint a browser login link from the shell account:

```bash
php cli/admin-link.php --email='admin@example.com' --system-user='server-login-user'
```

Open the returned `login_url` in your browser to sign in without typing the admin password.

## PAM Server Login

If you want the admin page to accept the same server username and password you use for shell or Cockpit access, install the PAM helper on the server:

```bash
sudo apt-get update
sudo apt-get install -y gcc libpam0g-dev

cd /var/www/update.vitarius.org
sudo php cli/install-auth-helper.php --shell-user='jvitarius85'
sudo systemctl restart php8.4-fpm
```

Then rerun `cli/install.php` so the helper path is stored in `storage/config.php`:

```bash
php cli/install.php \
  --dsn='mysql:host=127.0.0.1;dbname=metis_updates;charset=utf8mb4' \
  --db-user='metis_updates' \
  --db-pass='replace-me' \
  --base-url='https://update.vitarius.org' \
  --admin-email='admin@example.com' \
  --admin-password='replace-me' \
  --system-user='server-login-user' \
  --auth-helper='/usr/local/bin/metis-update-auth-helper' \
  --pam-service='login' \
  --auth-exec-group='metis-update-auth'
```

The helper is installed as a setuid root binary owned by `root:metis-update-auth` with mode `4750`. Only members of that Unix group, including `www-data`, can execute it.

## Publish

Run the publish verification gate by itself:

```bash
php cli/verify.php \
  --type=core_full \
  --version=26.6.1 \
  --source-dir=/path/to/metis \
  --verify-root=/path/to/metis
```

Current `publish_gate` profile runs:

- `php tools/governance/run-ajax-ui-hardening-regression.php`
- `php system/tests/people_directory_service_contract_test.php`

`cli/publish.php` runs the same gate automatically, records the verification run in `verification_runs`, and fails closed if verification cannot run or if any command fails.

Core full package:

```bash
php cli/publish.php \
  --type=core_full \
  --tag=v26.6.1 \
  --version=26.6.1 \
  --source-dir=/path/to/metis-build \
  --verify-root=/path/to/metis \
  --notes='Release notes'
```

For Metis split-repo verification, `--verify-root` must point at the core `metis` checkout and any private modules referenced by the contract suite must exist beside it as a sibling `metis-private` directory.

Core delta package:

```bash
php cli/publish.php \
  --type=core_delta \
  --tag=v26.6.2 \
  --version=26.6.2 \
  --from-version=26.6.1 \
  --source-dir=/path/to/metis-26.6.2 \
  --from-dir=/path/to/metis-26.6.1 \
  --verify-root=/path/to/metis-26.6.2
```

Module full package:

```bash
php cli/publish.php \
  --type=module_full \
  --module-id=website \
  --version=1.0.1 \
  --minimum-metis=26.6.0 \
  --source-dir=/path/to/module \
  --verify-root=/path/to/metis \
  --visibility=public
```

Module delta package:

```bash
php cli/publish.php \
  --type=module_delta \
  --module-id=website \
  --version=1.0.2 \
  --from-version=1.0.1 \
  --minimum-metis=26.6.0 \
  --source-dir=/path/to/module-1.0.2 \
  --from-dir=/path/to/module-1.0.1 \
  --verify-root=/path/to/metis \
  --visibility=public
```

Installation-scoped custom modules should be published with:

```bash
--visibility=installation --installation-id=<installation_uuid>
```

Emergency bypass is available but should stay rare:

```bash
--skip-verify=1
```

## GitHub Release

The admin UI can optionally mirror a published package to GitHub through `gh release create`.

Prerequisites on the server:

```bash
gh auth login
```

The GitHub release action uploads the already-signed package archive produced by the update server, so the GitHub asset remains the same signed artifact that Metis installs.

## Installation Cron Trigger

The update server can act as the minute-based scheduler for registered Metis installations. It signs each cron request with the update server private key and targets the installation's public `/e/cj` endpoint while binding the signature to Metis's canonical `/api/cron` route.

Trigger every active installation once:

```bash
php cli/trigger-cron.php
```

Trigger one installation by UUID:

```bash
php cli/trigger-cron.php --installation-id=<installation_uuid>
```

Suggested update-server crontab:

```cron
* * * * * /usr/bin/php8.4 /var/www/update.vitarius.org/cli/trigger-cron.php >/dev/null 2>&1
```
