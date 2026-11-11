#!/bin/bash
# HU-7 / HU §9.2: builds every brand x environment through stdin and checks each zip.
# Brands are the branding/ folders with a config.json, in the same glob order as build.sh.
# Evidence: <EVIDENCE>/matrix/<brand>-<environment>/{build.log,module/} and
# <EVIDENCE>/solo-envios-skydropx-grep.txt (for human review: only D1 identifiers, headers
# and comments may mention Skydropx).
# Usage: bash tests/build/matrix.sh [version]   (default 1.0.0; works from any cwd)
# Writes the real builds/<slug>-v<version>-<environment>.zip of the repo.
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERSION="${1:-1.0.0}"
LOCAL_URL="http://host.docker.internal:8000"
EVIDENCE="/home/dell/dev/SKYDROPX/magento-plugin-delivery/evidence/build"
OUT="$EVIDENCE/matrix"
ENVIRONMENTS=(local sandbox production)
PLACEHOLDER_PATTERN='PLUGIN_(NAME|SLUG|DESCRIPTION|AUTHOR|VERSION|ENVIRONMENT)|ECOMMERCE_URL|APP_URL'
XML_CHECK='
libxml_use_internal_errors(true);
$failed = 0;
foreach (array_slice($argv, 1) as $file) {
    $doc = new DOMDocument();
    if (!$doc->load($file, LIBXML_NONET)) {
        $failed = 1;
        foreach (libxml_get_errors() as $error) {
            fwrite(STDERR, sprintf("%s:%d: %s\n", $file, $error->line, trim($error->message)));
        }
    }
    libxml_clear_errors();
}
exit($failed);
'

rm -rf "$OUT"
mkdir -p "$OUT"
fail=0
summary=()

check() { # check <status> <message>
    if [[ $1 -eq 0 ]]; then
        echo "  ok   $2"
    else
        echo "  FAIL $2"
        case_fail=1
    fi
}

brands=()
for dir in "$REPO"/branding/*; do
    if [[ -d "$dir" && -f "$dir/config.json" ]]; then
        brands+=("$(basename "$dir")")
    fi
done

for required in skydropx solo-envios; do
    if [[ ! " ${brands[*]} " == *" $required "* ]]; then
        echo "FAIL branding/$required/config.json not found"
        fail=1
    fi
done

for b in "${!brands[@]}"; do
    brand=${brands[b]}
    config="$REPO/branding/$brand/config.json"
    slug=$(jq -r .plugin_slug "$config")

    for e in "${!ENVIRONMENTS[@]}"; do
        env=${ENVIRONMENTS[e]}
        case_fail=0
        case_dir="$OUT/$brand-$env"
        module="$case_dir/module"
        log="$case_dir/build.log"
        zip="$REPO/builds/$slug-v$VERSION-$env.zip"
        mkdir -p "$module"

        if [[ $env == local ]]; then
            eco="$LOCAL_URL"
            input=$(printf '%s\n' "$((b + 1))" "$VERSION" "$((e + 1))" "$LOCAL_URL" n)
        else
            eco=$(jq -r --arg env "$env" '.urls[$env].ecommerce' "$config")
            input=$(printf '%s\n' "$((b + 1))" "$VERSION" "$((e + 1))" n)
        fi
        app=$(jq -r --arg env "$env" '.urls[$env].app' "$config")

        echo "== $brand / $env"
        rm -f "$zip"
        printf '%s\n' "$input" | timeout 180 bash "$REPO/build.sh" > "$log" 2>&1
        check $? "build.sh exit 0 (log: $log)"
        [[ ! -e "$REPO/.tmp-build" ]]; check $? ".tmp-build removed"
        [[ -f "$zip" ]]; check $? "zip at builds/$slug-v$VERSION-$env.zip"

        if [[ ! -f "$zip" ]]; then
            summary+=("FAIL $brand-$env")
            fail=1
            continue
        fi

        unzip -q "$zip" -d "$module"

        unzip -Z1 "$zip" | grep -qx 'composer.json'; check $? "composer.json at the zip root"

        left=$(cd "$module" && grep -rIonE -- "$PLACEHOLDER_PATTERN" . ; find . -print | grep -E -- "$PLACEHOLDER_PATTERN")
        [[ -z "$left" ]]; check $? "no placeholder left (content and file names)"
        [[ -n "$left" ]] && echo "$left" | head -10 | sed 's/^/       /'

        lint=0
        while IFS= read -r -d '' f; do
            if ! out=$(php -l "$f" 2>&1); then
                echo "       $out"
                lint=1
            fi
        done < <(find "$module" -type f \( -name '*.php' -o -name '*.phtml' \) -print0)
        check $lint "php -l on every .php/.phtml"

        out=$(cd "$module" && find . -type f -name '*.xml' -print0 | xargs -0 php -r "$XML_CHECK" -- 2>&1)
        check $? "every .xml loads with DOMDocument"
        [[ -n "$out" ]] && echo "$out" | head -10 | sed 's/^/       /'

        jq -e --arg v "$VERSION" '.name == "skydropx/module-shipping" and .version == $v' \
            "$module/composer.json" >/dev/null 2>&1
        check $? "composer.json name=skydropx/module-shipping version=$VERSION"

        build_php="$module/Model/Build.php"
        grep -qF "public const ECOMMERCE_BASE = '$eco';" "$build_php" 2>/dev/null
        check $? "Build.php ECOMMERCE_BASE = '$eco'"
        grep -qF "public const APP_BASE = '$app';" "$build_php" 2>/dev/null
        check $? "Build.php APP_BASE = '$app'"
        grep -qF "public const VERSION = '$VERSION';" "$build_php" 2>/dev/null
        check $? "Build.php VERSION = '$VERSION'"
        grep -qF "public const ENVIRONMENT = '$env';" "$build_php" 2>/dev/null
        check $? "Build.php ENVIRONMENT = '$env'"
        grep -qF "public const SLUG = '$slug';" "$build_php" 2>/dev/null
        check $? "Build.php SLUG = '$slug'"

        [[ -z "$(find "$module" -type d -name Test)" ]]; check $? "no Test/ in the zip"

        for f in registration.php etc/module.xml Model/Carrier/Skydropx.php; do
            [[ -f "$module/$f" ]]; check $? "$f present"
        done

        if [[ -f "$REPO/branding/$brand/assets/images/logo.png" ]]; then
            cmp -s "$REPO/branding/$brand/assets/images/logo.png" "$module/view/adminhtml/web/images/logo.png"
            check $? "logo.png = branding/$brand/assets/images/logo.png"
        else
            [[ ! -e "$module/view/adminhtml/web/images/logo.png" ]]; check $? "no logo for $brand"
        fi

        echo "       name: $(grep -o "public const NAME = '[^']*';" "$build_php" 2>/dev/null)"

        if [[ $case_fail -eq 0 ]]; then
            summary+=("PASS $brand-$env")
        else
            summary+=("FAIL $brand-$env")
            fail=1
        fi
    done
done

# HU §9.2: in the solo-envios zip, Skydropx may only appear in D1 identifiers, X-Skydropx-*
# headers and comments. Left for human review.
grep_out="$EVIDENCE/solo-envios-skydropx-grep.txt"
solo_module="$OUT/solo-envios-production/module"
if [[ -d "$solo_module" ]]; then
    (cd "$solo_module" && grep -rn Skydropx .) > "$grep_out"
    echo
    echo "grep -rn Skydropx (solo-envios-production): $(wc -l < "$grep_out") lines in $grep_out"
else
    echo "solo-envios-production was not built, nothing to grep" > "$grep_out"
    fail=1
fi

echo
printf '%s\n' "${summary[@]}"
echo
if [[ $fail -eq 0 ]]; then
    echo "MATRIX PASS"
else
    echo "MATRIX FAIL"
fi
exit $fail
