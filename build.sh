#!/bin/bash

# Magento Module Builder
# Asks for brand, version and environment, fills the plugin-source/ placeholders and writes
# builds/<plugin_slug>-v<version>-<environment>.zip: the Skydropx_Shipping module with
# composer.json at the root, usable as a Composer artifact or unzipped into
# app/code/Skydropx/Shipping/. The module identity (skydropx/module-shipping, Skydropx_Shipping)
# is the same for every brand (HU D1); only the strings in HU §5.3 change.
#
# Answers can be piped:
#   printf '1\n1.0.0\n2\nn\n' | ./build.sh                                     # brand 1, sandbox
#   printf '1\n1.0.0\n1\nhttp://host.docker.internal:8000\nn\n' | ./build.sh   # brand 1, local
# EOF on any prompt before the package is written aborts with exit 1 and no zip. EOF on the
# last prompt ("Open builds directory?") means "n": the package is already built (exit 0).

set -euo pipefail

readonly SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
readonly SOURCE_DIR="$SCRIPT_DIR/plugin-source"
readonly BRANDING_DIR="$SCRIPT_DIR/branding"
readonly BUILD_DIR="$SCRIPT_DIR/builds"
readonly TEMP_DIR="$SCRIPT_DIR/.tmp-build"

readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly PURPLE='\033[0;35m'
readonly NC='\033[0m'

# php is required: the build lints every PHP file and parses every XML file with DOM.
readonly DEPENDENCIES=("jq" "rsync" "zip" "php")
readonly SUPPORTED_ENVIRONMENTS=("local" "sandbox" "production")

readonly COMPOSER_PACKAGE="skydropx/module-shipping"
readonly MODULE_NAME="Skydropx_Shipping"

readonly SLUG_PATTERN='^[a-z0-9]+(-[a-z0-9]+)*$'
readonly URL_PATTERN='^https?://'
readonly HTTPS_URL_PATTERN='^https://'
# Local ecommerce URL (HU D11): an ngrok tunnel, or eco on the host as seen from Docker or not.
readonly NGROK_URL_PATTERN='^https://[a-zA-Z0-9-]+\.(ngrok-free\.app|ngrok\.app|ngrok\.io)$'
readonly HOST_URL_PATTERN='^http://(host\.docker\.internal|localhost):([0-9]{1,5})$'
# HU §6.10 validate_build (1): every placeholder of §5.3.
readonly PLACEHOLDER_PATTERN='PLUGIN_(NAME|SLUG|DESCRIPTION|AUTHOR|VERSION|ENVIRONMENT)|ECOMMERCE_URL|APP_URL'

# Loads every file given as argument with DOMDocument (xmllint is not installed everywhere).
# Prints file:line: message for each error and exits 1 if any file is not well formed.
readonly XML_CHECK='
libxml_use_internal_errors(true);
$failed = 0;
foreach (array_slice($argv, 1) as $file) {
    $doc = new DOMDocument();
    if (!$doc->load($file, LIBXML_NONET)) {
        $failed = 1;
        $errors = libxml_get_errors();
        if ($errors === []) {
            fwrite(STDERR, $file . ": could not be loaded\n");
        }
        foreach ($errors as $error) {
            fwrite(STDERR, sprintf("%s:%d: %s\n", $file, $error->line, trim($error->message)));
        }
    }
    libxml_clear_errors();
}
exit($failed);
'

# === UTILITY FUNCTIONS ===

log() {
    local type=$1
    local message=$2
    case $type in
        "error") echo -e "${RED}✗ $message${NC}" >&2 ;;
        "warning") echo -e "${YELLOW}⚠ $message${NC}" >&2 ;;
        "success") echo -e "${GREEN}✓ $message${NC}" ;;
        "info") echo -e "${BLUE}ℹ $message${NC}" ;;
        *) echo "$message" ;;
    esac
}

cleanup() {
    if [[ -d "$TEMP_DIR" ]]; then
        rm -rf "$TEMP_DIR"
    fi
}
trap cleanup EXIT

check_dependencies() {
    local missing=()
    local dep
    for dep in "${DEPENDENCIES[@]}"; do
        if ! command -v "$dep" >/dev/null 2>&1; then
            missing+=("$dep")
        fi
    done

    if [[ ${#missing[@]} -ne 0 ]]; then
        log "error" "Missing dependencies: ${missing[*]}"
        return 1
    fi

    if ! php -r 'exit(class_exists("DOMDocument") ? 0 : 1);' >/dev/null 2>&1; then
        log "error" "Missing dependencies: the php dom extension (needed to validate the XML files)"
        return 1
    fi
}

# === CONFIGURATION MANAGEMENT ===

# Prints a string value of config.json by dotted key (e.g. urls.sandbox.app).
config_value() {
    local config_file=$1
    local key=$2
    jq -r --arg key "$key" 'getpath($key | split("."))' "$config_file"
}

# Rejects a config.json that would produce a broken package. Runs before anything is copied.
validate_config() {
    local config_file=$1
    local environment=$2
    local config_label="${config_file#"$SCRIPT_DIR"/}"
    local failed=0
    local key status value

    if ! jq -e 'type == "object"' "$config_file" >/dev/null 2>&1; then
        log "error" "$config_label is not a valid JSON object"
        exit 1
    fi

    # jq -r prints "null" for a missing key, so types are checked inside jq.
    for key in plugin_name plugin_slug plugin_description plugin_author plugin_author_uri \
               "urls.$environment.ecommerce" "urls.$environment.app"; do
        status=$(jq -r --arg key "$key" '
            (try getpath($key | split(".")) catch null) as $value
            | if ($value | type) != "string" then "is missing or is not a string"
              elif $value == "" then "is empty"
              elif ($value | test("[\r\n]")) then "contains a line break"
              else "ok" end' "$config_file")
        if [[ "$status" != "ok" ]]; then
            log "error" "$key $status in $config_label"
            failed=1
        fi
    done

    if [[ $failed -ne 0 ]]; then
        exit 1
    fi

    value=$(config_value "$config_file" plugin_slug)
    if [[ ! "$value" =~ $SLUG_PATTERN ]]; then
        log "error" "plugin_slug must be lowercase letters and digits separated by single hyphens in $config_label"
        failed=1
    fi

    # plugin_name goes inside single-quoted PHP strings (Build::NAME) and XML text
    # (config.xml, system.xml), which are not escaped by the build (HU §5.3).
    value=$(config_value "$config_file" plugin_name)
    if [[ "$value" == *"'"* || "$value" == *"\\"* || "$value" == *"<"* || "$value" == *"&"* ]]; then
        log "error" "plugin_name must not contain a single quote, a backslash, '<' or '&' in $config_label"
        failed=1
    fi

    for key in "urls.$environment.ecommerce" "urls.$environment.app"; do
        value=$(config_value "$config_file" "$key")
        if [[ "$key" == "urls.local.ecommerce" ]]; then
            # The local ecommerce URL is asked at build time (HU D11).
            if [[ "$value" != "NGROK_URL" ]]; then
                log "error" "urls.local.ecommerce must be NGROK_URL (the URL is asked at build time) in $config_label"
                failed=1
            fi
            continue
        fi
        if [[ "$environment" == "local" ]]; then
            if [[ ! "$value" =~ $URL_PATTERN ]]; then
                log "error" "$key must start with http:// or https:// in $config_label"
                failed=1
            fi
        elif [[ ! "$value" =~ $HTTPS_URL_PATTERN ]]; then
            log "error" "$key must start with https:// in $config_label"
            failed=1
        fi
    done

    if [[ $failed -ne 0 ]]; then
        exit 1
    fi

    log "success" "Configuration validated: $config_label"
}

# Placeholder -> value map (HU §5.3, the plugin-source/ placeholder contract, nothing else).
create_replacement_map() {
    local config_file="$1"
    local environment="$2"
    local version="$3"
    local local_ecommerce_url="${4:-}"

    jq -c \
        --arg environment "$environment" \
        --arg version "$version" \
        --arg local_ecommerce_url "$local_ecommerce_url" \
        '{
            "PLUGIN_NAME": .plugin_name,
            "PLUGIN_SLUG": .plugin_slug,
            "PLUGIN_DESCRIPTION": .plugin_description,
            "PLUGIN_AUTHOR": .plugin_author,
            "PLUGIN_AUTHOR_URI": .plugin_author_uri,
            "PLUGIN_VERSION": $version,
            "PLUGIN_ENVIRONMENT": $environment,
            "ECOMMERCE_URL": (.urls[$environment].ecommerce
                | if . == "NGROK_URL" then $local_ecommerce_url else . end),
            "APP_URL": .urls[$environment].app
        }' "$config_file"
}

# === TEMPLATE PROCESSING ===

apply_replacements() {
    local replacement_map="$1"
    # Inside .tmp-build (not zipped) so the EXIT trap removes it on failure.
    local sed_script="$TEMP_DIR/.replacements.sed"

    # Longest key first so a key never matches inside a longer one (PLUGIN_AUTHOR_URI before
    # PLUGIN_AUTHOR). Keys are escaped as regex; values escape \, & and the # delimiter.
    jq -r '
        to_entries
        | sort_by(-(.key | length), .key)
        | .[]
        | "s#" + (.key | gsub("(?<c>[\\[\\]\\\\.*^$#])"; "\\\(.c)"))
          + "#" + (.value | gsub("(?<c>[\\\\&#])"; "\\\(.c)")) + "#g"
    ' <<<"$replacement_map" > "$sed_script"

    # composer.json is written with jq (update_composer_json), never with sed.
    local file
    while IFS= read -r -d '' file; do
        sed -i.bak -f "$sed_script" "$file"
        rm -f "${file}.bak"
    done < <(find "$TEMP_DIR" -type f \
        \( -name '*.php' -o -name '*.phtml' -o -name '*.xml' -o -name '*.csv' \
           -o -name '*.js' -o -name '*.html' -o -name '*.less' -o -name '*.json' \) \
        ! -name 'composer.json' -print0)

    rm -f "$sed_script"
}

# composer.json is rewritten with jq so quotes or accents in the values keep it valid JSON.
update_composer_json() {
    local replacement_map="$1"
    local composer_json="$TEMP_DIR/composer.json"

    if [[ ! -f "$composer_json" ]]; then
        log "error" "plugin-source/composer.json not found"
        exit 1
    fi

    if ! jq --indent 4 --argjson map "$replacement_map" '
        .version = $map.PLUGIN_VERSION
        | .description = $map.PLUGIN_DESCRIPTION
        | .homepage = $map.PLUGIN_AUTHOR_URI
        | .authors = [{"name": $map.PLUGIN_AUTHOR, "homepage": $map.PLUGIN_AUTHOR_URI}]
    ' "$composer_json" > "$composer_json.tmp"; then
        log "error" "plugin-source/composer.json is not valid JSON"
        exit 1
    fi
    mv "$composer_json.tmp" "$composer_json"
}

# === FILE OPERATIONS ===

copy_source_files() {
    log "info" "Copying source files..."

    # Test/ holds the unit tests: they are not part of the module (HU §5.4).
    rsync -a \
        --exclude='.git' \
        --exclude='*.md' \
        --exclude='.DS_Store' \
        --exclude='Test/' \
        --exclude='node_modules' \
        "$SOURCE_DIR/" "$TEMP_DIR/"
}

copy_brand_assets() {
    local brand="$1"
    local logo="$BRANDING_DIR/$brand/assets/images/logo.png"

    # A brand without a logo (solo-envios) ships none; the admin panel then shows no image.
    if [[ ! -f "$logo" ]]; then
        return 0
    fi

    log "info" "Copying brand assets..."

    # The connection panel reads it from the module and inlines it as a data URI (HU §6.4.6).
    mkdir -p "$TEMP_DIR/view/adminhtml/web/images"
    cp "$logo" "$TEMP_DIR/view/adminhtml/web/images/logo.png"
}

# === MAIN PROCESSING ===

process_brand() {
    local brand="$1"
    local replacement_map="$2"

    log "info" "Processing brand: $brand"

    log "info" "Processing template files..."
    apply_replacements "$replacement_map"
    update_composer_json "$replacement_map"

    copy_brand_assets "$brand"
}

# Checks the processed tree, which is what gets installed (HU §6.10, points 1-7).
# Any failure stops before zipping; the EXIT trap removes .tmp-build.
validate_build() {
    local replacement_map="$1"
    local failed=0
    local version ecommerce_url app_url leftovers file output required

    log "info" "Validating build..."

    version=$(jq -r '.PLUGIN_VERSION' <<<"$replacement_map")
    ecommerce_url=$(jq -r '.ECOMMERCE_URL' <<<"$replacement_map")
    app_url=$(jq -r '.APP_URL' <<<"$replacement_map")

    # (1) No placeholder left. grep -I skips binary files such as the logo.
    leftovers=$(cd "$TEMP_DIR" && grep -rIonE -- "$PLACEHOLDER_PATTERN" . || true)
    if [[ -n "$leftovers" ]]; then
        log "error" "Unreplaced placeholders (file:line:placeholder):"
        echo "$leftovers" >&2
        failed=1
    fi

    leftovers=$(cd "$TEMP_DIR" && find . -print | grep -E -- "$PLACEHOLDER_PATTERN" || true)
    if [[ -n "$leftovers" ]]; then
        log "error" "Unreplaced placeholders in file names:"
        echo "$leftovers" >&2
        failed=1
    fi

    # (2) php -l on every .php and .phtml.
    local lint_failed=0
    while IFS= read -r -d '' file; do
        if ! output=$(php -l "$file" 2>&1); then
            log "error" "PHP syntax error in ${file#"$TEMP_DIR"/}"
            echo "$output" >&2
            lint_failed=1
        fi
    done < <(find "$TEMP_DIR" -type f \( -name '*.php' -o -name '*.phtml' \) -print0)

    if [[ $lint_failed -ne 0 ]]; then
        failed=1
    else
        log "success" "PHP lint passed"
    fi

    # (3) Every .xml loads with DOMDocument.
    if ! output=$(cd "$TEMP_DIR" && find . -type f -name '*.xml' -print0 \
            | xargs -0 php -r "$XML_CHECK" -- 2>&1); then
        log "error" "XML not well formed:"
        echo "$output" >&2
        failed=1
    else
        log "success" "XML validated"
    fi

    # (4) composer.json with the fixed package name and the version of the prompt.
    if ! jq -e --arg name "$COMPOSER_PACKAGE" --arg v "$version" \
            '.name == $name and .version == $v' "$TEMP_DIR/composer.json" >/dev/null 2>&1; then
        log "error" "composer.json must have name '$COMPOSER_PACKAGE' and version '$version'"
        failed=1
    fi

    # (5) registration.php registers Skydropx_Shipping.
    if ! grep -q 'ComponentRegistrar::register(' "$TEMP_DIR/registration.php" 2>/dev/null ||
       ! grep -Eq "['\"]${MODULE_NAME}['\"]" "$TEMP_DIR/registration.php" 2>/dev/null; then
        log "error" "registration.php does not register $MODULE_NAME"
        failed=1
    fi

    # (6) Model/Build.php carries the URLs of the selected environment.
    if ! grep -qF -- "public const ECOMMERCE_BASE = '$ecommerce_url';" "$TEMP_DIR/Model/Build.php" 2>/dev/null; then
        log "error" "Model/Build.php does not have ECOMMERCE_BASE = '$ecommerce_url'"
        failed=1
    fi
    if ! grep -qF -- "public const APP_BASE = '$app_url';" "$TEMP_DIR/Model/Build.php" 2>/dev/null; then
        log "error" "Model/Build.php does not have APP_BASE = '$app_url'"
        failed=1
    fi

    # (7) Expected structure, without the unit tests.
    for required in registration.php composer.json etc/module.xml Model/Carrier/Skydropx.php; do
        if [[ ! -f "$TEMP_DIR/$required" ]]; then
            log "error" "$required is missing from the build"
            failed=1
        fi
    done
    if [[ -e "$TEMP_DIR/Test" ]]; then
        log "error" "Test/ must not be part of the build"
        failed=1
    fi

    if [[ $failed -ne 0 ]]; then
        log "error" "Build validation failed, no package created"
        exit 1
    fi

    log "success" "Build validated"
}

create_package() {
    local replacement_map="$1"
    local plugin_name plugin_slug version environment ecommerce_url app_url
    plugin_name=$(jq -r '.PLUGIN_NAME' <<<"$replacement_map")
    plugin_slug=$(jq -r '.PLUGIN_SLUG' <<<"$replacement_map")
    version=$(jq -r '.PLUGIN_VERSION' <<<"$replacement_map")
    environment=$(jq -r '.PLUGIN_ENVIRONMENT' <<<"$replacement_map")
    ecommerce_url=$(jq -r '.ECOMMERCE_URL' <<<"$replacement_map")
    app_url=$(jq -r '.APP_URL' <<<"$replacement_map")

    local archive="$BUILD_DIR/${plugin_slug}-v${version}-${environment}.zip"

    log "info" "Creating package: ${archive#"$SCRIPT_DIR"/}"

    mkdir -p "$BUILD_DIR"
    # zip updates an existing archive in place, so start from scratch.
    rm -f "$archive"
    # Zip the contents (composer.json at the root), not the .tmp-build folder itself.
    if ! (cd "$TEMP_DIR" && zip -qrX "$archive" .); then
        rm -f "$archive"
        log "error" "Could not create $archive"
        exit 1
    fi

    echo
    log "success" "Build completed successfully!"
    echo
    echo "  Brand: $plugin_name"
    echo "  Version: $version"
    echo "  Environment: $environment"
    echo "  Ecommerce URL: $ecommerce_url"
    echo "  App URL: $app_url"
    echo "  Output: $archive"
    echo "  Size: $(du -h "$archive" | cut -f1)"
    echo
    echo "  Install as a Composer artifact (from the Magento root):"
    echo "    mkdir -p /path/to/artifacts && cp $archive /path/to/artifacts/"
    echo "    composer config repositories.skydropx artifact /path/to/artifacts"
    echo "    composer require $COMPOSER_PACKAGE:$version"
    echo
    echo "  Or install into app/code (from the Magento root):"
    echo "    mkdir -p app/code/Skydropx/Shipping && unzip $archive -d app/code/Skydropx/Shipping"
    echo
    echo "  Then enable it:"
    echo "    bin/magento module:enable $MODULE_NAME && bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:enable skydropx_rates && bin/magento cache:flush"
    echo
    log "warning" "Every build is $COMPOSER_PACKAGE: keep only one zip per version in the artifact directory"
    echo
}

# === USER INTERFACE ===
# Each prompt aborts on EOF: command substitution does not inherit set -e, so the status of
# read is checked explicitly. A last line without a newline still counts as input.

select_brand() {
    local -a brands=("$@")
    local selection i

    if [[ ${#brands[@]} -eq 0 ]]; then
        log "error" "No brands available"
        return 1
    fi

    log "info" "Available brands:" >&2
    for i in "${!brands[@]}"; do
        echo "  $((i+1)). ${brands[i]}" >&2
    done
    echo >&2

    while true; do
        if ! read -r -p "Select brand (1-${#brands[@]}): " selection && [[ -z "$selection" ]]; then
            log "error" "No input left while selecting the brand"
            return 1
        fi
        if [[ "$selection" =~ ^[0-9]+$ ]] &&
           [[ "$selection" -ge 1 ]] &&
           [[ "$selection" -le ${#brands[@]} ]]; then
            echo "${brands[$((selection-1))]}"
            return 0
        fi
        log "error" "Invalid selection. Please enter a number between 1 and ${#brands[@]}"
    done
}

select_environment() {
    local -a environments=("${SUPPORTED_ENVIRONMENTS[@]}")
    local selection i

    log "info" "Available environments:" >&2
    for i in "${!environments[@]}"; do
        echo "  $((i+1)). ${environments[i]}" >&2
    done
    echo >&2

    while true; do
        if ! read -r -p "Select environment (1-${#environments[@]}): " selection && [[ -z "$selection" ]]; then
            log "error" "No input left while selecting the environment"
            return 1
        fi
        if [[ "$selection" =~ ^[0-9]+$ ]] &&
           [[ "$selection" -ge 1 ]] &&
           [[ "$selection" -le ${#environments[@]} ]]; then
            echo "${environments[$((selection-1))]}"
            return 0
        fi
        log "error" "Invalid selection. Please enter a number between 1 and ${#environments[@]}"
    done
}

get_version() {
    local version
    while true; do
        if ! read -r -p "Enter version (e.g. 1.2.3): " version && [[ -z "$version" ]]; then
            log "error" "No input left while reading the version"
            return 1
        fi
        version="${version#v}"
        if [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            echo "$version"
            return 0
        fi
        log "error" "Invalid version format. Use semantic versioning (e.g. 1.2.3)"
    done
}

# Replaces get_ngrok_url (HU D11): Magento in Docker reaches eco on the host without a tunnel.
# A trailing "/" is dropped; a path or a query is rejected.
get_local_ecommerce_url() {
    local url port
    while true; do
        if ! read -r -p "Enter local ecommerce URL (e.g. https://swine-subtle-largely.ngrok-free.app or http://host.docker.internal:8000): " url && [[ -z "$url" ]]; then
            log "error" "No input left while reading the local ecommerce URL"
            return 1
        fi
        url="${url%/}"
        if [[ "$url" =~ $NGROK_URL_PATTERN ]]; then
            echo "$url"
            return 0
        fi
        if [[ "$url" =~ $HOST_URL_PATTERN ]]; then
            port="${BASH_REMATCH[2]}"
            if (( 10#$port >= 1 && 10#$port <= 65535 )); then
                echo "$url"
                return 0
            fi
        fi
        log "error" "Invalid local ecommerce URL. Use https://<name>.ngrok-free.app (or .ngrok.app, .ngrok.io), http://host.docker.internal:<port> or http://localhost:<port>, without path or query"
    done
}

# === MAIN FUNCTION ===

main() {
    echo -e "${PURPLE}"
    echo "╔════════════════════════════════════════════════╗"
    echo "║            Magento Module Builder              ║"
    echo "║                                                ║"
    echo "╚════════════════════════════════════════════════╝"
    echo -e "${NC}"

    if ! check_dependencies; then
        exit 1
    fi

    if [[ ! -d "$SOURCE_DIR" || ! -d "$BRANDING_DIR" ]]; then
        log "error" "Required directories not found (plugin-source/, branding/)"
        exit 1
    fi

    mkdir -p "$BUILD_DIR"

    # Brands are the branding/ folders with a config.json, in glob order. A new folder shows
    # up here without touching this script.
    local brands=()
    local dir
    for dir in "$BRANDING_DIR"/*; do
        if [[ -d "$dir" && -f "$dir/config.json" ]]; then
            brands+=("$(basename "$dir")")
        fi
    done

    if [[ ${#brands[@]} -eq 0 ]]; then
        log "error" "No brands found in $BRANDING_DIR"
        exit 1
    fi

    # User selections
    local selected_brand
    selected_brand=$(select_brand "${brands[@]}") || exit 1
    log "success" "Brand selected: $selected_brand"
    echo

    local config_file="$BRANDING_DIR/$selected_brand/config.json"
    if [[ ! -f "$config_file" ]]; then
        log "error" "Configuration file not found: $config_file"
        exit 1
    fi

    local version
    version=$(get_version) || exit 1
    echo

    local environment
    environment=$(select_environment) || exit 1
    echo

    validate_config "$config_file" "$environment"
    echo

    local local_ecommerce_url=""
    if [[ "$environment" == "local" ]]; then
        local_ecommerce_url=$(get_local_ecommerce_url) || exit 1
        echo
    fi

    log "success" "Building for environment: $environment"

    local replacement_map
    replacement_map=$(create_replacement_map "$config_file" "$environment" "$version" "$local_ecommerce_url")

    # Build process
    log "info" "Preparing build..."
    cleanup
    mkdir -p "$TEMP_DIR"

    copy_source_files
    process_brand "$selected_brand" "$replacement_map"
    validate_build "$replacement_map"
    create_package "$replacement_map"

    # Ask to open builds directory. EOF here means "n": the package is already built.
    local open_builds=""
    while true; do
        if ! read -r -p "Open builds directory? (y/n): " open_builds && [[ -z "$open_builds" ]]; then
            open_builds="n"
            echo
            break
        fi
        if [[ "$open_builds" =~ ^[YyNn]$ ]]; then
            break
        fi
        log "error" "Answer with 'y' (yes) or 'n' (no)"
    done

    if [[ "$open_builds" =~ ^[Yy]$ ]]; then
        case "$OSTYPE" in
            darwin*) open "$BUILD_DIR" ;;
            linux*) xdg-open "$BUILD_DIR" 2>/dev/null || log "info" "Open manually: $BUILD_DIR" ;;
            *) log "info" "Open manually: $BUILD_DIR" ;;
        esac
    fi
}

main "$@"
