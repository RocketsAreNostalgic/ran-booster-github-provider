#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php "$root/tests/analysis-coverage.php"
php "$root/tests/analysis-coverage.php" "$root" --development
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
cp "$root/composer.json" "$root/composer.lock" "$root/phpstan.neon" "$root/phpstan-development.neon" "$fixture/"
cp -R "$root/src" "$fixture/src"
cp -R "$root/tests" "$fixture/tests"
cp -R "$root/scripts" "$fixture/scripts"
ln -s "$root/vendor" "$fixture/vendor"
analyze() { composer --no-plugins --no-interaction --working-dir="$fixture" "${analysis_command:-analyze:production}" -- --error-format=json; }
analyze > "$fixture/clean.json"
mkdir -p "$fixture/new-product/contracts" "$fixture/src/tests" "$fixture/tests"
for path in root-contract.php new-product/contracts/split.php src/tests/runtime-contract.php; do
    printf '<?php\nran_provider_missing_contract();\n' > "$fixture/$path"
    php "$root/tests/analysis-coverage.php" "$fixture"
    if analyze > "$fixture/negative.json" 2> "$fixture/negative.log"; then exit 1; fi
    php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="function.notFound"&&str_contains($m["message"],"ran_provider_missing_contract")){exit(0);}}exit(1);' "$fixture/negative.json" "$fixture/$path"
    rm "$fixture/$path"
done
# Moving an existing maintained source beyond its old src root retains coverage.
source=$(find "$fixture/src" -type f -name '*.php' | head -1)
mv "$source" "$fixture/moved-contract.php"
php "$root/tests/analysis-coverage.php" "$fixture"
printf '<?php\n' > "$fixture/tests/Support/development.php"
php "$root/tests/analysis-coverage.php" "$fixture"
for path in NewContract.PHP contract-tool; do
    printf '#!/usr/bin/env php\n<?php\n' > "$fixture/$path"
    if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -Eq 'Unsupported PHP extension|Nonstandard-extension PHP' "$fixture/guard.log"
    rm "$fixture/$path"
done
for header in '<?PHP' '<?='; do
    for path in contract-tool contract.inc; do
        printf '%s\n' "$header" > "$fixture/$path"
        if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
        grep -q 'Nonstandard-extension PHP' "$fixture/guard.log"
        rm "$fixture/$path"
    done
done
# CLI removes production registered as a stub after FileFinder selection.
printf '\tstubFiles:\n\t\t- moved-contract.php\n' >> "$fixture/phpstan.neon"
if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Effective PHPStan selection differs' "$fixture/guard.log"
cp "$root/phpstan.neon" "$fixture/phpstan.neon"
# Excluded fixture declarations must not pollute production symbol discovery.
printf '<?php\nconst RAN_PROVIDER_FIXTURE_ONLY = 1;\n' > "$fixture/tests/Support/development.php"
printf '<?php\necho RAN_PROVIDER_FIXTURE_ONLY;\n' > "$fixture/scan-isolation.php"
if analyze > "$fixture/isolation.json" 2> "$fixture/isolation.log"; then exit 1; fi
grep -q 'RAN_PROVIDER_FIXTURE_ONLY' "$fixture/isolation.json"
sed -i 's/analyseAndScan:/analyse:/' "$fixture/phpstan.neon"
analyze > "$fixture/leaked.json" 2> "$fixture/leaked.log" || true
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(!isset($r["files"]))exit(1);foreach($r["files"] as $f)foreach($f["messages"] as $m)if(str_contains($m["message"],"RAN_PROVIDER_FIXTURE_ONLY"))exit(1);' "$fixture/leaked.json"
rm "$fixture/scan-isolation.php"
cp "$root/phpstan.neon" "$fixture/phpstan.neon"
sed -i 's/- \.$/- src/' "$fixture/phpstan.neon"
if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
echo 'PASS inclusive analysis: root, nested, split/moved, role collision, exclusions and unsupported extensions.'

# The complementary profile covers every development/root file without fixture
# declarations changing the independent production profile's inference.
cp "$root/phpstan.neon" "$fixture/phpstan.neon"
mv "$fixture/moved-contract.php" "$source"
rm "$fixture/tests/Support/development.php"
analysis_command=analyze:development
php "$root/tests/analysis-coverage.php" "$fixture" --development
analyze > "$fixture/development-clean.json" 2> "$fixture/development-clean.log" || { cat "$fixture/development-clean.log" "$fixture/development-clean.json" >&2; exit 1; }
for path in root-contract.php tests/Support/new-fixture.php scripts/new-tool.php; do
    printf '<?php\nran_provider_development_missing();\n' > "$fixture/$path"
    php "$root/tests/analysis-coverage.php" "$fixture" --development
    status=0
    analyze > "$fixture/development-invalid.json" 2> "$fixture/development-invalid.log" || status=$?
    test "$status" -eq 1
    php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="function.notFound"&&str_contains($m["message"],"ran_provider_development_missing")){exit(0);}}exit(1);' "$fixture/development-invalid.json" "$fixture/$path"
    rm "$fixture/$path"
done
for change in level exclusion ignore; do
    cp "$root/phpstan-development.neon" "$fixture/phpstan-development.neon"
    case "$change" in
        level) sed -i 's/level: 5/level: 4/' "$fixture/phpstan-development.neon" ;;
        exclusion) printf '\t\t\t- tests/*\n' >> "$fixture/phpstan-development.neon" ;;
        ignore) printf '\tignoreErrors: []\n' >> "$fixture/phpstan-development.neon" ;;
    esac
    if php "$root/tests/analysis-coverage.php" "$fixture" --development > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
done
cp "$root/phpstan-development.neon" "$fixture/phpstan-development.neon"
for directive in '@phpstan-ignore-next-line' '@PHPSTAN-IGNORE phpstanApi.constructor'; do
    printf '<?php\n// %s\n' "$directive" > "$fixture/tests/unreviewed.php"
    if php "$root/tests/analysis-coverage.php" "$fixture" --development > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Review new or changed analysis exemptions' "$fixture/guard.log"
    rm "$fixture/tests/unreviewed.php"
done
printf '<?php\n(new PHPStan\\DependencyInjection\\NeonAdapter(array()))->load("missing");\n' > "$fixture/tests/outside-exception.php"
status=0
analyze > "$fixture/outside.json" 2> "$fixture/outside.log" || status=$?
test "$status" -eq 1
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="phpstanApi.constructor"){exit(0);}}exit(1);' "$fixture/outside.json" "$fixture/tests/outside-exception.php" || { cat "$fixture/outside.json" >&2; exit 1; }
rm "$fixture/tests/outside-exception.php"
coverage_original="$(cat "$fixture/tests/analysis-coverage.php")"
php -r '$p=$argv[1];$s=file_get_contents($p);$s=str_replace("// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)\n", "// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)\n\t(new PHPStan\\DependencyInjection\\NeonAdapter(array()))->load(null);\n",$s);file_put_contents($p,$s);' "$fixture/tests/analysis-coverage.php"
if php "$root/tests/analysis-coverage.php" "$fixture" --development > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Review new or changed analysis exemptions' "$fixture/guard.log"
printf '%s\n' "$coverage_original" > "$fixture/tests/analysis-coverage.php"
# Keep the foreign signature exception local to its one success-only fixture.
printf '<?php\nfunction ran_provider_unreviewed_union(string $url): string|false { return $url; }\n' > "$fixture/tests/outside-foreign.php"
status=0
analyze > "$fixture/foreign-outside.json" 2> "$fixture/foreign-outside.log" || status=$?
test "$status" -eq 1
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="return.unusedType"){exit(0);}}exit(1);' "$fixture/foreign-outside.json" "$fixture/tests/outside-foreign.php" || { cat "$fixture/foreign-outside.json" >&2; exit 1; }
rm "$fixture/tests/outside-foreign.php"
echo 'PASS development analysis: future root/test/script files, minimum level, omissions and occurrence-local tool exceptions.'
