# Manual upgrade and rollback

## Build and verify a release

Run these commands from a clean repository checkout:

```bash
composer install --no-interaction
npm ci
composer qa --no-interaction
npm run build
php tools/release/assemble.php --version=1.1.0 --output=release/formvex-spoke-1.1.0.zip
php tools/release/verify.php --archive=release/formvex-spoke-1.1.0.zip
```

The builder creates a production dependency tree with `composer install
--no-dev`. The ZIP does not contain the development dependency tree, tests,
`node_modules`, customer data, private runtime data, or the `logoslab` fixture.

## Verify on a customer host

Use the hosting account's CLI or cPanel terminal as the application owner. The
private root below is an example and must be replaced with the actual private
root:

```bash
sudo -u formvex env \
  APP_ENV=prod APP_DEBUG=0 \
  FORMVEX_APPLICATION_ROOT=/home/formvex/app/formvex/var/manual-spoke \
  php8.4 apps/spoke/bin/console formvex:spoke:release:verify \
  --application-root=/home/formvex/app/formvex/var/manual-spoke \
  --archive=/home/formvex/releases/formvex-spoke-1.1.0.zip
```

The verification command is read-only. A failed verification must be fixed
before an upgrade is started.

## Upgrade and rollback boundary

The hosting administrator must retain the exact previous verified package and
the final pre-upgrade backup. The upgrade command enters maintenance, drains
admitted requests and worker leases, creates the final backup, stages and
verifies the package, migrates, checks health and scheduler evidence, and only
then reopens the installation. A failed phase leaves the installation in its
safe maintenance state.

The rollback command uses the exact previous package together with the paired
final pre-upgrade archive. It never guesses a reverse migration and never
claims that an email already accepted by SMTP was undone.

The application can observe its own worker heartbeat, but it cannot inspect a
hosting provider's crontab directly. Configure the delivery and retention
commands in the hosting scheduler and then run the documented scheduler check.

## Run a manual upgrade

Run the following as the account that owns the Spoke installation. Set
`FORMVEX_APPLICATION_ROOT` in every command; a web request's environment is not
used by the CLI workflow.

```bash
export FORMVEX_ROOT=/home/example/private/formvex
export FORMVEX_PACKAGE=/home/example/releases/formvex-spoke-1.1.0.zip
export FORMVEX_SHA256=$(cat "$FORMVEX_PACKAGE.sha256" | awk '{print $1}')

env APP_ENV=prod APP_DEBUG=0 \
  FORMVEX_APPLICATION_ROOT="$FORMVEX_ROOT" \
  php8.4 apps/spoke/bin/console formvex:spoke:release:verify \
  --application-root="$FORMVEX_ROOT" \
  --archive="$FORMVEX_PACKAGE" \
  --sha256="$FORMVEX_SHA256"

env APP_ENV=prod APP_DEBUG=0 \
  FORMVEX_APPLICATION_ROOT="$FORMVEX_ROOT" \
  php8.4 apps/spoke/bin/console formvex:spoke:scheduler:check \
  --application-root="$FORMVEX_ROOT"

env APP_ENV=prod APP_DEBUG=0 \
  FORMVEX_APPLICATION_ROOT="$FORMVEX_ROOT" \
  php8.4 apps/spoke/bin/console formvex:spoke:upgrade \
  --application-root="$FORMVEX_ROOT" \
  --archive="$FORMVEX_PACKAGE" \
  --sha256="$FORMVEX_SHA256" \
  --drain-timeout=300
```

The upgrade command performs these actions in this order: verifies the package,
acquires the private upgrade lock, rejects new public submissions and new
worker claims, waits for admitted request/worker leases to finish, creates and
verifies the final `pre_upgrade` ZIP, stages and verifies the package, publishes
the code, applies ordered migrations, checks SQLite/private-storage health,
checks application-observed scheduler heartbeats, and clears the maintenance
marker last. A failed backup, publication, migration, health check, or
scheduler check leaves the installation unavailable and returns a nonzero exit
status. It does not inspect the hosting provider's crontab.

The command output contains only release/schema/backup/operation identifiers and
safe failure categories. It does not print absolute paths, credentials,
visitor values, SQL, or stack traces.

## Recover a failed upgrade

Do not delete the maintenance marker or edit the SQLite database manually.
Locate the final verified archive under the private
`backups/pre-upgrade/` directory and keep the exact previous release ZIP and
its external digest.

```bash
export FORMVEX_CHECKPOINT="$FORMVEX_ROOT/backups/pre-upgrade/<archive-id>.zip"
export FORMVEX_PREVIOUS=/home/example/releases/formvex-spoke-1.0.1.zip
export FORMVEX_PREVIOUS_SHA256=$(cat "$FORMVEX_PREVIOUS.sha256" | awk '{print $1}')

env APP_ENV=prod APP_DEBUG=0 \
  FORMVEX_APPLICATION_ROOT="$FORMVEX_ROOT" \
  php8.4 apps/spoke/bin/console formvex:spoke:rollback \
  --application-root="$FORMVEX_ROOT" \
  --archive="$FORMVEX_CHECKPOINT" \
  --previous-package="$FORMVEX_PREVIOUS" \
  --sha256="$FORMVEX_PREVIOUS_SHA256" \
  --drain-timeout=300
```

Rollback refuses a package without its external digest, a package whose schema
does not match the checkpoint, an archive outside the private pre-upgrade
directory, an invalid archive, or a concurrent release operation. It restores
the paired database/private data first, publishes the previously verified
package, runs health and scheduler checks, and clears the release hold only
after every check succeeds. A failed rollback leaves the hold in place.

## Verify after the operation

```bash
env APP_ENV=prod APP_DEBUG=0 \
  FORMVEX_APPLICATION_ROOT="$FORMVEX_ROOT" \
  php8.4 apps/spoke/bin/console formvex:spoke:health:check \
  --application-root="$FORMVEX_ROOT"

env APP_ENV=prod APP_DEBUG=0 \
  FORMVEX_APPLICATION_ROOT="$FORMVEX_ROOT" \
  php8.4 apps/spoke/bin/console formvex:spoke:scheduler:check \
  --application-root="$FORMVEX_ROOT"
```

The application reports heartbeat evidence only. It cannot prove that a cPanel
or system cron entry exists, so the administrator must confirm the scheduler
configuration separately.
