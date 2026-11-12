#!/bin/bash
# Magento 2.4.8 entrypoint (HU-8, §6.11). Idempotent: every step checks its own state.
#
#   1. composer: magento/project-community-edition from the Mage-OS mirror, WITH dev deps (PHPUnit).
#   2. setup:install + 2FA off + developer mode (only once; marker app/etc/.docker-installed).
#   3. Module sync for the active profile (dev: app/code mount registered; e2e: stale registration dropped).
#   4. Permissions for www-data, ready marker (/run/magento-ready), then Apache.
#
# Everything Magento/Composer runs as www-data, so `docker compose exec -u www-data …` stays consistent.
set -euo pipefail

ROOT=/var/www/html
PROFILE="${MAGENTO_DOCKER_PROFILE:-dev}"
READY=/run/magento-ready
INSTALLED_MARK="$ROOT/app/etc/.docker-installed"
PROFILE_MARK="$ROOT/app/etc/.docker-profile"
MODULE=Skydropx_Shipping
MODULE_PKG=skydropx/module-shipping
MODULE_DIR="$ROOT/app/code/Skydropx/Shipping"
VENDOR_DIR="$ROOT/vendor/skydropx/module-shipping"

log() { printf '[magento-docker] %s %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S')" "$*"; }
as_www() { runuser -u www-data -- env HOME=/var/www COMPOSER_HOME=/var/www/.composer "$@"; }
magento() { as_www php "$ROOT/bin/magento" "$@"; }

db_php() {
    # Runs a PDO snippet against the Magento DB; $pdo is ready inside the snippet.
    php -r '
        try {
            $pdo = new PDO(
                "mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"),
                getenv("DB_USER"),
                getenv("DB_PASSWORD"),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e) {
            exit(2);
        }
        '"$1"
}

module_enabled() { [ -f "$ROOT/app/etc/config.php" ] && grep -q "'$MODULE' => 1" "$ROOT/app/etc/config.php"; }
module_listed() { [ -f "$ROOT/app/etc/config.php" ] && grep -q "'$MODULE' =>" "$ROOT/app/etc/config.php"; }

rm -f "$READY"
cd "$ROOT"
log "Starting (profile: $PROFILE, Magento $MAGENTO_VERSION)"

# --- 0. Dependencies ----------------------------------------------------------------------------
log "Waiting for MySQL ($DB_HOST) and OpenSearch ($OPENSEARCH_HOST:$OPENSEARCH_PORT)"
for _ in $(seq 1 60); do db_php 'exit(0);' && break; sleep 2; done
db_php 'exit(0);' || { log "ERROR: MySQL is not reachable"; exit 1; }
for _ in $(seq 1 60); do curl -fsS -o /dev/null "http://$OPENSEARCH_HOST:$OPENSEARCH_PORT/" && break; sleep 2; done
curl -fsS -o /dev/null "http://$OPENSEARCH_HOST:$OPENSEARCH_PORT/" || { log "ERROR: OpenSearch is not reachable"; exit 1; }

# The volume root and the dirs Docker created as root for the dev bind mount must be writable by
# www-data. Never chown inside the mount itself (those are the host's files).
chown www-data:www-data "$ROOT"
for d in app app/code app/code/Skydropx; do
    [ -d "$ROOT/$d" ] && chown www-data:www-data "$ROOT/$d"
done

# --- 1. Composer ----------------------------------------------------------------------------------
if [ ! -f "$ROOT/vendor/autoload.php" ] || [ ! -f "$ROOT/bin/magento" ]; then
    log "Step 1/4: composer (first boot, 10-25 min)"
    if [ ! -f "$ROOT/composer.json" ]; then
        # create-project needs an empty dir, and the dev mount already put app/code/Skydropx in the
        # volume: fetch only the project composer.json into a temp dir, then install in place.
        tmp="$(mktemp -d)"
        chown www-data:www-data "$tmp"
        as_www composer create-project --no-install --no-progress \
            --repository-url=https://mirror.mage-os.org/ \
            "magento/project-community-edition:$MAGENTO_VERSION" "$tmp/project"
        as_www cp "$tmp/project/composer.json" "$ROOT/composer.json"
        rm -rf "$tmp"
    fi
    # No --no-dev: unit tests need PHPUnit and dev/tests/unit.
    as_www composer install --no-progress
    log "Step 1/4: composer done"
else
    log "Step 1/4: composer already done, skipping"
fi

# --- 2. setup:install -----------------------------------------------------------------------------
if [ -f "$INSTALLED_MARK" ] && ! db_php '$t = $pdo->query("SHOW TABLES LIKE \"core_config_data\"")->fetchColumn(); exit($t ? 0 : 1);'; then
    log "Step 2/4: install marker found but the database is empty (mysql volume removed?): reinstalling"
    rm -f "$INSTALLED_MARK"
fi

if [ ! -f "$INSTALLED_MARK" ]; then
    log "Step 2/4: setup:install (5-15 min)"
    extra=()
    if [ -f "$ROOT/app/etc/env.php" ]; then
        log "  a previous install did not finish: using --cleanup-database"
        extra+=(--cleanup-database)
    fi
    if [ -f "$MODULE_DIR/registration.php" ]; then
        # Keep the base install independent from the module under development; step 3 enables it.
        extra+=("--disable-modules=$MODULE")
    fi
    magento setup:install \
        --base-url="$MAGENTO_BASE_URL" \
        --db-host="$DB_HOST" --db-name="$DB_NAME" --db-user="$DB_USER" --db-password="$DB_PASSWORD" \
        --search-engine=opensearch \
        --opensearch-host="$OPENSEARCH_HOST" --opensearch-port="$OPENSEARCH_PORT" \
        --opensearch-enable-auth=0 --opensearch-index-prefix=magento2 --opensearch-timeout=15 \
        --admin-firstname=Admin --admin-lastname=Local --admin-email="$ADMIN_EMAIL" \
        --admin-user="$ADMIN_USER" --admin-password="$ADMIN_PASSWORD" \
        --language=es_MX --currency=MXN --timezone=America/Mexico_City \
        --backend-frontname=admin --use-rewrites=1 \
        "${extra[@]}"

    log "Step 2/4: disabling 2FA (local only)"
    magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth

    log "Step 2/4: deploy:mode:set developer"
    magento deploy:mode:set developer

    as_www touch "$INSTALLED_MARK"
    log "Step 2/4: install done"
else
    log "Step 2/4: Magento already installed, skipping"
fi

# --- 3. Module sync for the active profile ------------------------------------------------------
need_upgrade=0
last_profile="$(cat "$PROFILE_MARK" 2>/dev/null || true)"
if [ -n "$last_profile" ] && [ "$last_profile" != "$PROFILE" ]; then
    log "Step 3/4: profile changed ($last_profile -> $PROFILE)"
    need_upgrade=1
fi

if [ "$PROFILE" = "dev" ]; then
    vendor_clash=0
    if [ -d "$VENDOR_DIR" ]; then
        # Left over from e2e: it would clash with the app/code mount ("module already defined").
        # --no-plugins is MANDATORY here: magento/magento-composer-installer (Plugin::onPackageUnistall,
        # POST_PACKAGE_UNINSTALL) rm -rf's app/code/<Vendor>/<Name> for every removed package, which for
        # skydropx/module-shipping is app/code/Skydropx/Shipping = the ../plugin-source bind mount.
        # Without plugins, Composer's LibraryInstaller only deletes vendor/skydropx/module-shipping.
        log "Step 3/4: removing $MODULE_PKG installed from the artifact (e2e leftover), without Composer plugins"
        as_www composer config --unset repositories.skydropx || true
        if as_www composer remove --no-plugins --no-scripts --no-progress "$MODULE_PKG" && [ ! -d "$VENDOR_DIR" ]; then
            need_upgrade=1
        else
            vendor_clash=1
            log "ERROR: could not remove $MODULE_PKG from vendor/. Skipping module:enable/setup:upgrade so the store"
            log "ERROR: can still start. Fix by hand (as www-data), then restart the container:"
            log "ERROR:   composer remove --no-plugins --no-scripts $MODULE_PKG && bin/magento setup:upgrade"
        fi
        if [ ! -f "$MODULE_DIR/registration.php" ]; then
            log "ERROR: $MODULE_DIR/registration.php is missing after the cleanup: check ../plugin-source on the host"
        fi
    fi
    if [ "$vendor_clash" = 1 ]; then
        need_upgrade=0
    elif [ -f "$MODULE_DIR/registration.php" ]; then
        if ! module_enabled; then
            log "Step 3/4: $MODULE is mounted but not enabled: module:enable"
            magento module:enable "$MODULE" || log "WARN: module:enable $MODULE failed (see above)"
            need_upgrade=1
        fi
    else
        log "WARN: $MODULE_DIR/registration.php not found (is ../plugin-source empty?)"
    fi
else
    if module_listed && [ ! -f "$MODULE_DIR/registration.php" ] && [ ! -f "$VENDOR_DIR/registration.php" ]; then
        log "Step 3/4: $MODULE is in app/etc/config.php but its code is not on disk: dropping it"
        need_upgrade=1
    fi
fi

if [ "$need_upgrade" = 1 ]; then
    log "Step 3/4: setup:upgrade"
    # Non-fatal: a broken module under development must not keep the store down.
    if ! magento setup:upgrade; then
        log "WARN: setup:upgrade failed; fix the module and run: bin/magento setup:upgrade"
    fi
else
    log "Step 3/4: module registration up to date"
fi
printf '%s\n' "$PROFILE" > "$PROFILE_MARK"
chown www-data:www-data "$PROFILE_MARK"

# --- 4. Permissions and Apache --------------------------------------------------------------------
log "Step 4/4: permissions for www-data"
for d in var generated pub/static pub/media app/etc; do
    [ -d "$ROOT/$d" ] && chown -R www-data:www-data "$ROOT/$d"
done

touch "$READY"
log "READY: store http://localhost:8092/ · admin http://localhost:8092/admin ($ADMIN_USER)"
exec apache2-foreground
