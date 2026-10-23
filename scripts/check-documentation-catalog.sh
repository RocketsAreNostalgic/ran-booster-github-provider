#!/usr/bin/env bash
set -euo pipefail

domain='ran-booster-github-provider'
root=$(git rev-parse --show-toplevel)
temp=$(mktemp -d)
trap 'rm -rf -- "$temp"' EXIT

cd "$root"
command -v wp >/dev/null || { printf '%s\n' 'WP-CLI is required to check the Provider documentation catalog.' >&2; exit 1; }
command -v msgfmt >/dev/null || { printf '%s\n' 'gettext msgfmt is required to check the Provider documentation catalog.' >&2; exit 1; }
command -v msgunfmt >/dev/null || { printf '%s\n' 'gettext msgunfmt is required to check the Provider documentation catalog.' >&2; exit 1; }

wp i18n make-pot . "$temp/$domain.pot" --domain="$domain" --allow-root --exclude=vendor,node_modules --skip-audit >/dev/null
sed '/^"POT-Creation-Date:/d' "$temp/$domain.pot" > "$temp/generated.pot"
sed '/^"POT-Creation-Date:/d' "languages/$domain.pot" > "$temp/committed.pot"
cmp "$temp/generated.pot" "$temp/committed.pot"

for locale in fr_FR es_ES; do
	fixture="tests/fixtures/documentation-i18n/$domain-$locale.po"
	msgfmt -o "$temp/$domain-$locale.mo" "$fixture"
	msgunfmt "$temp/$domain-$locale.mo" | rg -F 'GitHub releases and webhooks' >/dev/null
done

expected=$'languages/ran-booster-github-provider.pot\nsrc/GitHubDocumentation.php\nsrc/GitHubTranslations.php'
actual=$(git archive --format=tar HEAD | tar -tf - | rg '^(src/GitHubDocumentation\.php|src/GitHubTranslations\.php|languages/ran-booster-github-provider\.pot)$' | sort)
test "$actual" = "$expected"
