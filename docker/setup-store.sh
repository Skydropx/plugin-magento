#!/bin/bash
# Local store data for the E2E (HU-8, §6.11). Idempotent: safe to run after every `up`.
#
#   docker --context default compose -f docker/docker-compose.yml --profile dev exec -T -u www-data magento setup-store
#   docker --context default compose -f docker/docker-compose.yml --profile e2e exec -T -u www-data magento-e2e setup-store
#
# - MX shipping origin: CMX, 06600, Reforma 1 / Juárez.
# - Weight unit kgs; default country MX.
# - Cash on delivery and check/money order enabled.
# - Simple product SKU E2E-1KG (1 kg, qty 100, in stock) via setup-product.php.
# - indexer:reindex + cache:flush.
set -euo pipefail

if [ "$(id -u)" = 0 ]; then
    exec runuser -u www-data -- env HOME=/var/www "$0" "$@"
fi

ROOT=/var/www/html
cd "$ROOT"

log() { printf '[setup-store] %s %s\n' "$(date -u '+%H:%M:%S')" "$*"; }
m() { php bin/magento "$@"; }

[ -f "$ROOT/app/etc/.docker-installed" ] || { log "ERROR: Magento is not installed yet (wait for READY)"; exit 1; }

region_id="$(php -r '
    $pdo = new PDO(
        "mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"),
        getenv("DB_USER"),
        getenv("DB_PASSWORD"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $s = $pdo->prepare("SELECT region_id FROM directory_country_region WHERE country_id = ? AND code = ?");
    $s->execute(["MX", "CMX"]);
    echo (string) $s->fetchColumn();
')"
[ -n "$region_id" ] || { log "ERROR: region MX/CMX not found in directory_country_region"; exit 1; }
log "MX/CMX region_id = $region_id"

log "Shipping origin (MX)"
m config:set shipping/origin/country_id MX
m config:set shipping/origin/region_id "$region_id"
m config:set shipping/origin/postcode 06600
m config:set shipping/origin/city "Ciudad de México"
m config:set shipping/origin/street_line1 "Reforma 1"
m config:set shipping/origin/street_line2 "Juárez"

log "Locale and payments"
m config:set general/locale/weight_unit kgs
m config:set general/country/default MX
m config:set payment/cashondelivery/active 1
m config:set payment/checkmo/active 1

log "Product E2E-1KG"
php /usr/local/share/magento-docker/setup-product.php

log "Reindex and cache flush"
m indexer:reindex
m cache:flush

log "Done"
