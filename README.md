# Skydropx Magento 2 Module

The **Skydropx Magento 2 module** (`Skydropx_Shipping`) connects a Magento 2 store to **Skydropx**. It shows signed Skydropx rates at checkout, pushes a signed event to Skydropx every time an order is created, changes state or is canceled, and connects the store with one click (or manually, by pasting a store ID and a signing key). Skydropx then reads the orders through Magento's standard REST API (`/rest/all/V1`) with an integration token the module creates. The module adds no REST endpoints of its own.

- **Contract version:** v1 (Magento extension contract, `docs/integrations/magento-extension-contract.md` in the Skydropx backend)
- **Brands:** Skydropx and Solo Envíos, built from one template (see [Build](#build)). The version is set by the build.
- **Package:** Composer `skydropx/module-shipping`, module `Skydropx_Shipping`, the same for every brand
- **Magento:** Open Source / Adobe Commerce 2.4.4 or later (tested on 2.4.8)

## Requirements

- Magento **2.4.4 or later** (`magento/framework ^103.0.4`).
- PHP **8.1 to 8.4** (`~8.1.0||~8.2.0||~8.3.0||~8.4.0`).
- **Magento cron running.** The order push runs from cron every minute. Without cron, orders reach Skydropx only through its own polling.
- Outbound HTTPS from the server to the Skydropx services of the build.
- For the one-click connection: a public **https** store URL, with the admin on the same domain and port as the store (see [Troubleshooting](#troubleshooting)).
- Inventory (MSI) is optional. Without it, the integration simply has no inventory permissions.

## Installation

Each build is a single zip with `composer.json` at its root: `builds/<slug>-v<version>-<environment>.zip` (see [Build](#build)). It installs in either of two ways.

**As a Composer artifact** (recommended, and the only one where `module:uninstall` works). From the Magento root:

```bash
mkdir -p /path/to/artifacts && cp skydropx-v1.0.0-production.zip /path/to/artifacts/
composer config repositories.skydropx artifact /path/to/artifacts
composer require skydropx/module-shipping:1.0.0
```

Composer reads every zip in the artifact directory, and every brand is `skydropx/module-shipping`. Keep only one zip per version there.

**Into `app/code`**. From the Magento root:

```bash
mkdir -p app/code/Skydropx/Shipping && unzip skydropx-v1.0.0-production.zip -d app/code/Skydropx/Shipping
```

**Then enable it** (both ways):

```bash
bin/magento module:enable Skydropx_Shipping && bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:enable skydropx_rates && bin/magento cache:flush
```

This creates the order outbox table `skydropx_order_event`, the cache type `skydropx_rates` (*Shipping Extension Rates*) and three cron jobs in the `default` group. To update, install the new zip the same way (`composer require skydropx/module-shipping:<version>` or replace the folder) and run the same commands.

Only one brand can be installed in a Magento installation: every brand is the same module.

## Configuration

Everything is in **Stores > Configuration > Sales > Shipping Methods > {brand}** (section `carriers`, group `skydropx`). Admin users need the ACL resource **Stores > Settings > Configuration > Shipping Extension Connection** (`Skydropx_Shipping::connect`) to connect, test or disconnect.

| Setting | Path | Notes |
|---|---|---|
| Connection panel | — | Status, connection details, package, last rates result, order push queue and the Connect / Test connection / Disconnect buttons (see [Connecting the store](#connecting-the-store)). |
| Enabled | `carriers/skydropx/active` | Shows the rates at checkout. Default *No*. The order push does **not** depend on it. |
| Title | `carriers/skydropx/title` | Header of the rates at checkout. Default: the brand name. |
| Store ID | `carriers/skydropx/shop_id` | Filled in by the one-click connection, or pasted for the manual connection. Must be a UUID. |
| Signing Key | `carriers/skydropx/signing_secret` | Stored encrypted and never shown again. Marked as a sensitive setting. |
| Send Orders Automatically | `carriers/skydropx/order_push` | Default *Yes*. Requires cron. |
| Length / Width / Height Attribute | `carriers/skydropx/{length,width,height}_attribute` | Magento has no native dimensions. Pick the product attributes that hold them (numeric or Text Field attributes; non-numeric values are sent as no dimension), or leave them empty. |
| Ship to Applicable Countries / Ship to Specific Countries | `sallowspecific` / `specificcountry` | Default: Mexico only. Skydropx only quotes MX. |
| Show Method if Not Applicable / Displayed Error Message | `showmethod` / `specificerrmsg` | Off by default: when there are no rates, the method is hidden. When on, the message is shown instead. |
| Sort Order | `sort_order` | Default 10. |
| Debug Log | `carriers/skydropx/debug` | Writes request and response bodies (rates and order push), with secrets redacted, to `var/log/skydropx.log`. |

The connection is stored in the default scope (one connection per installation). *Enabled*, *Title* and the country settings follow the usual carrier scopes.

## Connecting the store

### One click

1. Open **Stores > Configuration > Sales > Shipping Methods > {brand}**.
2. If **Allow OAuth Access Tokens to be used as standalone Bearer tokens** is off, the panel shows the checkbox **Allow integration tokens as Bearer (required to connect)**. Check it. The module turns that store-wide setting on only with this consent, and logs it (`BEARER_ENABLED`). Without it no connection works (see [Troubleshooting](#troubleshooting)).
3. Click **Connect with {brand}**. The browser goes to `{app}/merchant_stores/plugin_connect` with the store URL, a one-time `state` and the admin callback URL.
4. Log in to {brand} if needed and confirm. **Finish within 10 minutes**: the connection code expires after 10 minutes, and a long pause can also end the Magento admin session (*Admin Session Lifetime*, 900 s by default). If that happens, click Connect again.
5. {brand} sends the browser back to the admin route `skydropx/connect/callback`. The module then:
   - creates a new Magento integration named `{brand} · <date UTC>` (visible in **System > Extensions > Integrations**) with only the permissions Skydropx needs: orders (view, comment, ship), shipments, stores, and inventory sources when MSI is installed;
   - exchanges the code and the integration token server to server for the store ID and the signing key, and saves them (the key encrypted);
   - deletes the integration of the previous connection, only after the new one succeeded. If the exchange fails, it deletes the new integration and shows the reason;
   - sends the browser to `{app}/merchant_stores/plugin_connect/complete`, where {brand} shows the connected store.

Integrations left behind by an unfinished connection are removed by the hourly cron `skydropx_cleanup_integrations`.

### Manual

Use it when the one-click connection is not possible (the panel then shows the reason and disables the button) or when you rotate the key.

1. In {brand}, go to **My stores › your store › Settings › Extension**. Copy the store ID and generate the signing key. The key is shown once; after a rotation the previous key keeps working for 24 hours.
2. Paste them in **Store ID** and **Signing Key** and save. The panel shows *Connected manually*.
3. Click **Test connection**.

In manual mode the module creates no integration: Skydropx keeps using the access token configured in {brand} for the store. An integration left from an earlier one-click connection stays until you click **Disconnect** or uninstall the module.

### Panel actions

- **Test connection** sends a signed sample rates request (origin = the store's shipping origin, one 1 kg item worth 100) and reports *Signature accepted: N rates*, *The signing key does not match (401)* (with a clock warning when it applies) or *No response from {brand}*. It does not use the rates cache.
- **Disconnect** deletes the integration the module created (in either mode, if there is one) and the connection settings. It does **not** unlink the store in your {brand} account: remove it there in **My stores** too.
- The line **Package: `<version>` · `<environment>` · `<ecommerce URL>`** lets support spot a sandbox package in a production store.

## Checkout rates

When the carrier is enabled and the store is connected, `collectRates` sends the cart (origin, destination, package and items) signed to `{ecommerce}/api/v3/rates/magento/MX/checkout` and shows each rate as `skydropx_<code>`, titled `<carrier> <service>` plus the delivery days (`(1 day)`, `(N days)` or `(N-M days)`).

- Only destinations in Mexico with a postcode are quoted.
- The timeout is 4 s. On no rates, an error or a timeout the carrier is hidden and checkout continues with the other methods.
- Results are cached in the `skydropx_rates` cache type: 300 s for rates, 60 s for an empty list, errors are not cached. If the cache type is disabled, nothing is cached and rates still work.
- After 3 failures within 60 s, the module stops calling for 60 s (circuit breaker).
- Prices are in the store's base currency. A rate in another currency is dropped and logged.

## Order push

1. Every order save (checkout, admin, REST or cron) goes through the observer on `sales_order_save_after`. A new order queues `order.created`; a change of **state** queues `order.updated`, or `order.canceled` when the state is `canceled`. Status-only changes and virtual orders queue nothing. The event is written to the outbox table `skydropx_order_event` in the same transaction as the order, so checkout never waits for Skydropx.
2. The cron job `skydropx_push_orders` (every minute) sends up to 100 pending events, signed, to `{ecommerce}/api/v3/order/webhook/MX/MAGENTO/NEW_ORDER`. The event only carries ids, state and dates; Skydropx then fetches the order through the REST API. Its own polling keeps running, and the same order never appears twice.
3. Results:
   - `2xx` → `sent`.
   - No answer, `429` or `5xx` → retried after 1 min, 5 min, 15 min, 1 h, 6 h and 6 h. The seventh failed attempt marks it `failed`.
   - Any other `4xx` → `failed` at once, with the status in `last_error`.
   - Three transport errors in a row end the run, so a Skydropx outage does not hold the cron.
4. The daily job `skydropx_purge_outbox` (`15 3 * * *`) deletes `sent` events after 7 days and `failed` events after 30.

The push needs the store to be connected and **Send Orders Automatically** set to *Yes*. It does not depend on **Enabled**.

To send now, or to retry the failed events:

```bash
bin/magento skydropx:orders:push                      # pending events that are due, up to 100
bin/magento skydropx:orders:push --retry-failed       # put failed events back in the queue first
bin/magento skydropx:orders:push --limit=500          # another batch size (positive integer)
```

It prints `Sent: N. Rescheduled: N. Failed: N.` and never prints bodies. Only one run (cron or command) works at a time.

## Uninstalling

For a **Composer** installation:

```bash
bin/magento module:uninstall Skydropx_Shipping --remove-data
```

(Without `--remove-data`, Magento asks whether to remove the data.) Magento only finds the module's uninstall when the module has a row in `setup_module`, which `setup:upgrade` writes (`etc/module.xml` declares `setup_version="1.0.0"`). If the module was enabled before that version, run `bin/magento setup:upgrade` once before uninstalling. The module's `Setup/Uninstall` then:

1. deletes the integration the module created, whatever the connection mode, and any integration still pending;
2. deletes the settings `carriers/skydropx/*` from `core_config_data`;
3. deletes the module's flags (`skydropx_*` in the `flag` table);
4. drops the table `skydropx_order_event`.

It does **not** unlink the store in your {brand} account (remove it in **My stores**), and it leaves **Allow OAuth Access Tokens to be used as standalone Bearer tokens** as it is, because it is a store-wide setting.

For an **`app/code`** installation, `module:uninstall` does not apply. Click **Disconnect** in the panel first (it deletes the integration), then delete `app/code/Skydropx/Shipping` and run `bin/magento setup:upgrade`. The table `skydropx_order_event`, the `carriers/skydropx/*` settings and the `skydropx_*` flags stay in the database until you remove them by hand.

## Troubleshooting

All module events go to **`var/log/skydropx.log`** with fixed types: `RATES_UNAUTHORIZED`, `RATES_FAILED`, `CONNECT_SUCCESS`, `CONNECT_FAILED`, `PUSH_FAILED`, `BEARER_ENABLED` and `INTEGRATION_CLEANUP`. The signing key, tokens, connection codes, `state` and signatures are never logged. With **Debug Log** = *Yes*, the rates and push bodies are logged too, with secrets redacted. Turn it off when you are done.

- **Server clock.** Signatures are valid for 5 minutes. If the server clock is off by more than that, rates answer 401 and pushed events are dropped by Skydropx without any error on the Magento side. **Test connection** reports *The signing key does not match (401)* plus a warning with the difference in seconds when the clock is the cause. Fix the server time (NTP).
- **Bearer tokens.** Magento 2.4.4+ only accepts an integration token as a Bearer token when **Stores > Configuration > Services > OAuth > Consumer Settings > Allow OAuth Access Tokens to be used as standalone Bearer tokens** is *Yes*. If it is off, the connection fails with *Magento rejected the integration token…*. The same setting is needed when the store is connected by token. Turn it on with the panel checkbox or in that screen, then connect again.
- **Admin on another domain or port.** {brand} requires the callback to have the same origin (scheme, host and port) as the store URL. If the admin uses another host or port (**Stores > Configuration > Advanced > Admin > Admin Base URL > Use Custom Admin URL**), the one-click button is disabled and the panel explains the manual connection. The store URL must also be https with a public domain name (no IP address, `localhost` or internal domain).
- **Cron.** If orders stay pending for more than 15 minutes, the panel shows *Is the Magento cron running?*. Make sure the Magento cron runs every minute (`bin/magento cron:run`), or send them with `bin/magento skydropx:orders:push`.
- **Link expired.** *The link expired; click Connect again* or *The connection code expired or was already used* means more than 10 minutes passed, or the admin session ended, between Connect and the return. Click Connect again.
- **No rates at checkout.** Check that **Enabled** is *Yes* (the panel warns when it is not), that the panel says connected, that the address is in Mexico with a postcode, and the last rates result in the panel.
- **`module:uninstall --remove-data` leaves the data.** On a store where the module was already enabled before `setup_version` existed, `setup_module` has no row for `Skydropx_Shipping`, so Magento does not find `Setup/Uninstall`. Run `bin/magento setup:upgrade` first.
- **"This module is not built".** `plugin-source/` was installed directly. Install a zip generated by `build.sh`.

## Build

```bash
./build.sh
```

Requires `jq`, `rsync`, `zip` and `php` with the `dom` extension.

The prompts are, in order: brand (number), version (semver; `v1.2.3` is accepted), environment (`1` local, `2` sandbox, `3` production), the local ecommerce URL (only for `local`) and, at the end, "Open builds directory? (y/n)". The local ecommerce URL is `https://<name>.ngrok-free.app` (or `.ngrok.app`, `.ngrok.io`), `http://host.docker.internal:<port>` or `http://localhost:<port>`, without path or query.

The prompts read stdin, so the build can run without interaction:

```bash
printf '1\n1.0.0\n2\nn\n' | ./build.sh                                     # brand 1, 1.0.0, sandbox
printf '1\n1.0.0\n1\nhttp://host.docker.internal:8000\nn\n' | ./build.sh   # brand 1, 1.0.0, local, eco on the host
```

Then it:

1. validates `branding/<brand>/config.json` before copying anything;
2. copies `plugin-source/` to `.tmp-build/` without `.git`, `*.md`, `.DS_Store`, `Test/` and `node_modules`;
3. replaces the placeholders with `sed` in `*.php`, `*.phtml`, `*.xml`, `*.csv`, `*.js`, `*.html`, `*.less` and `*.json` except `composer.json`, which is rewritten with `jq` (`version`, `description`, `homepage`, `authors`);
4. copies `branding/<brand>/assets/images/logo.png`, if it exists, to `view/adminhtml/web/images/logo.png` (the panel inlines it, so no static content deploy is needed);
5. validates the result: no placeholder left, `php -l` on every `.php` and `.phtml`, every `.xml` well formed, `composer.json` with the fixed name and the version entered, `registration.php` registering `Skydropx_Shipping`, `Model/Build.php` with the URLs of the environment, the required files present and no `Test/`;
6. removes the previous zip and writes **`builds/<slug>-v<version>-<environment>.zip`** with `zip -qrX`, then prints a summary (brand, version, environment, URLs, path, size) and the installation commands.

If the config is invalid, a validation fails or stdin runs out before the package is written, the build exits with a non-zero status and writes no zip. Running out of input at "Open builds directory? (y/n)" counts as `n`.

### Placeholders

The module identity does not change per brand: package, module, namespace, carrier code, config paths, cache type, table, admin route, ACL, log file and `X-Skydropx-*` headers are fixed. Only these strings change:

| Placeholder | Value | Used in |
|---|---|---|
| `PLUGIN_NAME` | `plugin_name` | `Model/Build.php` (`NAME`), `etc/config.xml` (default title), `etc/adminhtml/system.xml` (group label) |
| `PLUGIN_SLUG` | `plugin_slug` | `Build::SLUG` (User-Agent `<slug>-magento/<version>`) and the zip name |
| `PLUGIN_DESCRIPTION` | `plugin_description` | `composer.json` `description` (with `jq`) |
| `PLUGIN_AUTHOR` | `plugin_author` | `composer.json` `authors[0].name` (with `jq`) |
| `PLUGIN_AUTHOR_URI` | `plugin_author_uri` | `composer.json` `homepage` and `authors[0].homepage` (with `jq`) |
| `PLUGIN_VERSION` | version prompt | `composer.json` `version` (with `jq`) and `Build::VERSION` (sent to Skydropx as `module_version`) |
| `PLUGIN_ENVIRONMENT` | environment prompt | `Build::ENVIRONMENT` |
| `ECOMMERCE_URL` | `urls.<env>.ecommerce`, or the local URL entered | `Build::ECOMMERCE_BASE` |
| `APP_URL` | `urls.<env>.app` | `Build::APP_BASE` |

Rules:

- Replacement goes from the longest key to the shortest (`PLUGIN_AUTHOR_URI` before `PLUGIN_AUTHOR`), escaping `\`, `&` and `#` in the values.
- Placeholders only appear inside strings, never in PHP identifiers, config keys, file names or comments: that is why the constants are `ECOMMERCE_BASE` and `APP_BASE`. `plugin-source/` is a valid, unbuilt Magento module.
- Translatable texts never contain the brand: they use `%1` (`__('Connect with %1', Build::NAME)`), so `i18n/es_MX.csv` is the same for every brand. The only exceptions are `config.xml` and `system.xml`.
- An unbuilt module (`ECOMMERCE_BASE`/`APP_BASE` not starting with `http://` or `https://`) refuses to connect or call out, with the message "This module is not built".

### Adding a brand

Create `branding/<brand>/config.json` (and, optionally, `branding/<brand>/assets/images/logo.png`). The build lists every folder of `branding/` that has a `config.json`, in folder order; no code changes are needed.

```json
{
  "plugin_name": "Skydropx",
  "plugin_slug": "skydropx",
  "plugin_description": "…",
  "plugin_author": "Skydropx",
  "plugin_author_uri": "https://pro.skydropx.com",
  "urls": {
    "local":      { "ecommerce": "NGROK_URL", "app": "http://skydropx.localhost.skydropx:3000" },
    "sandbox":    { "ecommerce": "https://sb-ecommerce-pro.skydropx.com", "app": "https://sb-pro.skydropx.com" },
    "production": { "ecommerce": "https://ecommerce.pro.skydropx.com", "app": "https://pro.skydropx.com" }
  }
}
```

The build checks that:

- every key is a non-empty string without line breaks;
- `plugin_slug` matches `^[a-z0-9]+(-[a-z0-9]+)*$`;
- `plugin_name` has no `'`, `\`, `<` or `&` (it goes into single-quoted PHP strings and XML text);
- the `sandbox` and `production` URLs start with `https://`, and `urls.local.app` with `http://` or `https://`;
- `urls.local.ecommerce` is exactly `NGROK_URL` (the URL is asked at build time).

The ecommerce host decides the brand on the Skydropx side, so each brand must point to its own services.

## Development

### Layout

```
plugin-magento/
├── build.sh                          # interactive multi-brand builder
├── branding/<brand>/config.json      # brand values and URLs per environment
├── branding/<brand>/assets/images/logo.png   # optional
├── plugin-source/                    # the module, with placeholders only in strings
├── builds/                           # output: <slug>-v<version>-<environment>.zip
├── tests/build/{matrix.sh,negative.sh}
├── docker/                           # local Magento 2.4.8
└── composer.json, phpcs.xml.dist     # lint tooling
```

### Lint

```bash
composer install
composer lint          # phpcs: Magento2 standard + PHPCompatibility 8.1-8.4 on plugin-source/ (Test/ excluded)
composer lint:fix      # phpcbf
```

### Build tests

```bash
bash tests/build/matrix.sh [version]   # every brand x environment through stdin; checks each zip
bash tests/build/negative.sh           # invalid configs and EOF on each prompt stop the build without a zip
```

`matrix.sh` writes the real zips to `builds/`. `negative.sh` works on a temporary copy. Both write their logs to the evidence folder set at the top of each script.

### Local Magento (Docker)

`docker/docker-compose.yml` runs Magento 2.4.8 (PHP 8.3, Apache) with MySQL 8.0 and OpenSearch 2.19. The compose project is `magento-e2e`. Always use the `default` Docker context and exactly **one** profile at a time: both publish `127.0.0.1:8092`.

- Store `http://localhost:8092/`, admin `http://localhost:8092/admin` (`admin` / `Admin123!`, local only).
- MySQL `127.0.0.1:3391` (database `magento`).
- `--profile dev` (service `magento`) mounts `plugin-source/` at `app/code/Skydropx/Shipping`: for unit tests and `setup:di:compile`. The unbuilt module installs but does not connect.
- `--profile e2e` (service `magento-e2e`) mounts `builds/` read-only at `/artifacts` and installs the zip as a Composer artifact.
- Magento reaches services on the host through `host.docker.internal` (build with `http://host.docker.internal:8000` for a local backend).
- There is no cron container: run `bin/magento cron:run` or `bin/magento skydropx:orders:push` by hand.

```bash
C="docker --context default compose -f docker/docker-compose.yml"

# dev
$C --profile dev up -d --build --wait --wait-timeout 3600     # first boot: ~20-40 min
$C --profile dev exec -T -u www-data magento setup-store       # MX shipping origin, payments, a 1 kg product
$C --profile dev exec -T -u www-data magento bin/magento setup:upgrade
$C --profile dev exec -T -u www-data magento bin/magento setup:di:compile
$C --profile dev down                                         # keeps volumes; never add -v

# e2e (after `--profile dev down`)
$C --profile e2e up -d --build --wait --wait-timeout 3600
$C --profile e2e exec -T -u www-data magento-e2e setup-store
$C --profile e2e exec -T -u www-data magento-e2e composer config repositories.skydropx artifact /artifacts
$C --profile e2e exec -T -u www-data magento-e2e composer require skydropx/module-shipping:<version>
$C --profile e2e exec -T -u www-data magento-e2e bin/magento setup:upgrade
$C --profile e2e exec -T -u www-data magento-e2e bin/magento setup:di:compile
$C --profile e2e exec -T -u www-data magento-e2e bin/magento cache:enable skydropx_rates
$C --profile e2e exec -T -u www-data magento-e2e bin/magento cache:flush
```

In `e2e`, keep only the zip under test in `builds/` (Composer may pick any zip with the same version). To reinstall a zip with the same version: `composer remove skydropx/module-shipping`, `composer clear-cache`, then `composer require` again. Switching profiles never reinstalls Magento; the header of `docker/docker-compose.yml` explains what each switch does to the module registration.

### Unit tests

The tests live in `plugin-source/Test/Unit/` (same path as the class they test) and run in the `dev` profile, from Magento's `dev/tests/unit`:

```bash
$C --profile dev exec -T -u www-data -w /var/www/html/dev/tests/unit magento \
    ../../../vendor/bin/phpunit -c phpunit.xml.dist ../../../app/code/Skydropx/Shipping/Test/Unit/<path>
# e.g. <path> = Model/SignerTest.php, or a folder such as Model/Rates
```

They target PHPUnit 10.5 (Magento 2.4.8) and stay valid on 9.5 (2.4.4). Run one PHPUnit at a time per container.

## Known limits

- **Not on Packagist or the Adobe Marketplace yet.** The installation text shown by Skydropx says `composer require skydropx/module-shipping` and its docs link points to a repository that is not published yet. Until then, install from the zip as above.
- **White labels.** A Solo Envíos build compiles and installs, but its platform keeps the one-click connection and the extension key card off until they are enabled there. The platform copy also says "Skydropx" for every brand, while the button says "Connect with Solo Envíos".
- **One brand and one connection per installation.** Every brand is the same module, so two brands cannot live in one Magento. The connection is global: rates and orders of every website and store view go to the same account, because Skydropx reads `/rest/all`. Per-website connections are not supported.
- **Mexico only**, in MXN as the base currency. No currency conversion, no pt_BR.
- **No free shipping from cart rules, handling fees or per-website rates.**
- **The chosen rate is not linked to the order** in Skydropx yet. Magento already stores it as `skydropx_<code>` in the order's shipping method.
- **Order push events**: only `order.created`, `order.updated` and `order.canceled`. Shipment or address changes send nothing.
- **A rejected push is invisible to Magento**: Skydropx answers 200 before checking the signature. Use **Test connection** to check the key; Skydropx's polling still picks the orders up.
- **No label printing or package configuration from the Magento admin.**
- **Adobe Commerce as a Cloud Service (SaaS)** is not supported. Hyvä or PWA checkouts work as long as they use Magento's `collectRates`.
- **Magento 2.4.4–2.4.6** are supported by the code (PHP 8.1 syntax) but only 2.4.8 is tested end to end.

## License

Proprietary, © Skydropx.
