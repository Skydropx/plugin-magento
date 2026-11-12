#!/bin/bash
# HU-7: negative cases of build.sh (plus a few positive ones that pin the edges), always on a
# throwaway copy (mktemp -d) of build.sh, branding/ and plugin-source/. Never touches the repo.
# Each negative case checks: exit code != 0 (and not a timeout), the expected message in the
# output, no zip under builds/ and no .tmp-build left behind.
# Logs: <EVIDENCE>/negative/<case>.log
# Usage: bash tests/build/negative.sh   (works from any cwd)
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="/home/dell/dev/SKYDROPX/magento-plugin-delivery/evidence/build/negative"
WORK="$(mktemp -d)"
COPY="$WORK/repo"
trap 'rm -rf "$WORK"' EXIT

rm -rf "$OUT"
mkdir -p "$OUT"
fail=0

for required in build.sh branding plugin-source; do
    if [[ ! -e "$REPO/$required" ]]; then
        echo "FAIL $REPO/$required not found"
        exit 1
    fi
done

fresh() {
    rm -rf "$COPY"
    mkdir -p "$COPY/builds"
    cp -a "$REPO/build.sh" "$REPO/branding" "$REPO/plugin-source" "$COPY/"
}

# Position of a brand in the build.sh menu (same glob order as build.sh).
brand_index() {
    local i=0 dir
    for dir in "$COPY"/branding/*; do
        if [[ -d "$dir" && -f "$dir/config.json" ]]; then
            i=$((i + 1))
            if [[ "${dir##*/}" == "$1" ]]; then
                echo "$i"
                return 0
            fi
        fi
    done
    echo 0
}

set_cfg() { # set_cfg <brand> <jq filter>
    local file="$COPY/branding/$1/config.json"
    jq "$2" "$file" > "$file.tmp" && mv "$file.tmp" "$file"
}

# run_build <log> [stdin lines...]: no lines = empty stdin (EOF on the first prompt).
run_build() {
    local log=$1
    shift
    if [[ $# -eq 0 ]]; then
        timeout 120 bash "$COPY/build.sh" < /dev/null > "$log" 2>&1
    else
        printf '%s\n' "$@" | timeout 120 bash "$COPY/build.sh" > "$log" 2>&1
    fi
}

zip_count() {
    find "$COPY/builds" -name '*.zip' | wc -l
}

# expect_fail <case> <expected message> [stdin lines...]
expect_fail() {
    local name=$1 message=$2 rc zips log="$OUT/$1.log"
    shift 2
    run_build "$log" "$@"
    rc=$?
    zips=$(zip_count)
    if [[ $rc -ne 0 && $rc -ne 124 && $zips -eq 0 && ! -e "$COPY/.tmp-build" ]] &&
       grep -qF -- "$message" "$log"; then
        echo "  ok   $name: exit $rc, no zip | $message"
    else
        echo "  FAIL $name: exit $rc (124 = hung), zips=$zips, .tmp-build left=$([[ -e "$COPY/.tmp-build" ]] && echo yes || echo no), expected message: $message"
        fail=1
    fi
}

# expect_pass <case> <zip relative to builds/> [stdin lines...]; then the caller checks the zip.
expect_pass() {
    local name=$1 zip=$2 rc log="$OUT/$1.log"
    shift 2
    run_build "$log" "$@"
    rc=$?
    if [[ $rc -eq 0 && -f "$COPY/builds/$zip" && ! -e "$COPY/.tmp-build" ]]; then
        return 0
    fi
    echo "  FAIL $name: exit $rc, zip builds/$zip $([[ -f "$COPY/builds/$zip" ]] && echo present || echo missing)"
    fail=1
    return 1
}

build_php() { # build_php <zip relative to builds/>
    unzip -p "$COPY/builds/$1" Model/Build.php 2>/dev/null
}

echo "== config.json (HU-7)"
fresh; sky=$(brand_index skydropx)
set_cfg skydropx '.plugin_slug = "Sky Dropx"'
expect_fail bad-slug "plugin_slug must be lowercase letters and digits separated by single hyphens" "$sky" 1.0.0 2 n

fresh; set_cfg skydropx ".plugin_name = \"Sky'dropx\""
expect_fail name-quote "plugin_name must not contain a single quote, a backslash, '<' or '&'" "$sky" 1.0.0 2 n

fresh; set_cfg skydropx '.urls.production.ecommerce = "http://ecommerce.pro.skydropx.com"'
expect_fail http-production "urls.production.ecommerce must start with https://" "$sky" 1.0.0 3 n

fresh; set_cfg skydropx '.plugin_author = ""'
expect_fail empty-author "plugin_author is empty" "$sky" 1.0.0 2 n

echo "== config.json (extra)"
fresh; set_cfg skydropx '.plugin_name = "Sky<dropx"'
expect_fail name-lt "plugin_name must not contain a single quote, a backslash, '<' or '&'" "$sky" 1.0.0 2 n

fresh; set_cfg skydropx '.plugin_name = "Sky & dropx"'
expect_fail name-amp "plugin_name must not contain a single quote, a backslash, '<' or '&'" "$sky" 1.0.0 2 n

fresh; set_cfg skydropx '.plugin_name = "Sky\\dropx"'
expect_fail name-backslash "plugin_name must not contain a single quote, a backslash, '<' or '&'" "$sky" 1.0.0 2 n

fresh; set_cfg skydropx '.urls.production.app = "http://pro.skydropx.com"'
expect_fail http-production-app "urls.production.app must start with https://" "$sky" 1.0.0 3 n

fresh; set_cfg skydropx '.urls.local.ecommerce = "https://ecommerce.pro.skydropx.com"'
expect_fail local-ecommerce-not-ngrok "urls.local.ecommerce must be NGROK_URL" "$sky" 1.0.0 1 http://host.docker.internal:8000 n

echo "== EOF on each prompt (HU-7)"
fresh; expect_fail eof-brand "No input left while selecting the brand"
fresh; expect_fail eof-version "No input left while reading the version" "$sky"
fresh; expect_fail eof-environment "No input left while selecting the environment" "$sky" 1.0.0
fresh; expect_fail eof-local-url "No input left while reading the local ecommerce URL" "$sky" 1.0.0 1

echo "== prompts (extra)"
fresh; expect_fail bad-version "Invalid version format" "$sky" 1.0 2 n
fresh; expect_fail local-url-other-host "Invalid local ecommerce URL" "$sky" 1.0.0 1 http://example.com:8000 n
fresh; expect_fail local-url-path "Invalid local ecommerce URL" "$sky" 1.0.0 1 https://x.ngrok-free.app/path n
fresh; expect_fail local-url-query "Invalid local ecommerce URL" "$sky" 1.0.0 1 'http://localhost:8000/?a=1' n

echo "== validate_build (extra)"
fresh; echo 'Made by PLUGIN_NAME' > "$COPY/plugin-source/notes.txt"
expect_fail leftover-placeholder "Unreplaced placeholders" "$sky" 1.0.0 2 n

fresh; mkdir -p "$COPY/plugin-source/Model"; echo '<?php function (' > "$COPY/plugin-source/Model/BrokenLint.php"
expect_fail php-lint "PHP syntax error in Model/BrokenLint.php" "$sky" 1.0.0 2 n

fresh; mkdir -p "$COPY/plugin-source/etc"; echo '<config><broken></config>' > "$COPY/plugin-source/etc/broken.xml"
expect_fail broken-xml "XML not well formed" "$sky" 1.0.0 2 n

echo "== positive edges"
# HU-7: a new branding/<brand>/config.json shows up in the menu without touching code.
fresh
mkdir -p "$COPY/branding/acme"
jq '.plugin_name = "Acme Envíos" | .plugin_slug = "acme"' "$COPY/branding/skydropx/config.json" \
    > "$COPY/branding/acme/config.json"
acme=$(brand_index acme)
if expect_pass new-brand acme-v1.0.0-sandbox.zip "$acme" 1.0.0 2 n; then
    if grep -qE "^ *$acme\. acme$" "$OUT/new-brand.log" &&
       build_php acme-v1.0.0-sandbox.zip | grep -qF "public const NAME = 'Acme Envíos';" &&
       ! unzip -Z1 "$COPY/builds/acme-v1.0.0-sandbox.zip" | grep -q 'logo.png'; then
        echo "  ok   new-brand: 'acme' listed as option $acme, builds/acme-v1.0.0-sandbox.zip with NAME 'Acme Envíos', no logo"
    else
        echo "  FAIL new-brand: menu entry, Build::NAME or logo not as expected"
        fail=1
    fi
fi

# EOF on the last prompt ("Open builds directory?") means "n" (same as plugin-opencart).
fresh
if expect_pass eof-open-builds skydropx-v1.0.0-sandbox.zip "$sky" 1.0.0 2; then
    echo "  ok   eof-open-builds: exit 0 and zip kept (EOF = n)"
fi

# D11: a trailing "/" is dropped from the local ecommerce URL.
fresh
if expect_pass local-url-trailing-slash skydropx-v1.0.0-local.zip "$sky" 1.0.0 1 http://host.docker.internal:8000/ n; then
    if build_php skydropx-v1.0.0-local.zip | grep -qF "public const ECOMMERCE_BASE = 'http://host.docker.internal:8000';"; then
        echo "  ok   local-url-trailing-slash: ECOMMERCE_BASE = 'http://host.docker.internal:8000'"
    else
        echo "  FAIL local-url-trailing-slash: ECOMMERCE_BASE not as expected"
        fail=1
    fi
fi

# Escaping: # and double quotes in the name go through sed; quotes, \, & and # in the
# description go through jq into composer.json.
fresh
set_cfg skydropx '.plugin_name = "Envíos \"Más\" #1" | .plugin_description = "Dice \"hola\" & usa \\ y #tag"'
if expect_pass escape skydropx-v2.3.4-production.zip "$sky" v2.3.4 3 n; then
    name_line=$(build_php skydropx-v2.3.4-production.zip | grep -F 'public const NAME =')
    desc=$(unzip -p "$COPY/builds/skydropx-v2.3.4-production.zip" composer.json | jq -r .description)
    if [[ "$name_line" == *"public const NAME = 'Envíos \"Más\" #1';"* && "$desc" == 'Dice "hola" & usa \ y #tag' ]]; then
        echo "  ok   escape: $(echo "$name_line" | sed 's/^ *//') | composer.json description exact | v2.3.4 -> 2.3.4"
    else
        echo "  FAIL escape: name=[$name_line] description=[$desc]"
        fail=1
    fi
fi

echo
if [[ $fail -eq 0 ]]; then
    echo "NEGATIVE PASS"
else
    echo "NEGATIVE FAIL"
fi
exit $fail
