#!/usr/bin/env bash
# Provider-only baseline/host boundary. No cache or installed-host authority.
set -euo pipefail

fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ ${RAN_EXPECTED_SHA:-} =~ ^[0-9a-f]{40}$ ]] || fail 'Expected source SHA is missing or malformed.'
[[ ${RAN_CI_PHASE_STATE:-} = /* ]] || fail 'Phase state must be outside the source checkout.'
root=$(git rev-parse --show-toplevel)
test "$PWD" = "$root" || fail 'Run the phase from the package root.'
state=$(realpath -m "$RAN_CI_PHASE_STATE")
[[ $state != "$root" && $state != "$root/"* ]] || fail 'Phase state must be outside the source checkout.'

verify_source() {
  test "$(git rev-parse HEAD)" = "$RAN_EXPECTED_SHA" || fail 'Wrong source revision.'
  # Compare actual tracked bytes rather than relying on stat/index flags.
  local entry metadata path mode type expected actual tree_fd tree_pid untracked
  exec {tree_fd}< <(git ls-tree -rz HEAD)
  tree_pid=$!
  while IFS= read -r -d '' entry; do
    metadata=${entry%%$'\t'*}
    path=${entry#*$'\t'}
    read -r mode type expected <<< "$metadata"
    test "$type" = blob || fail 'Unexpected source submodule.'
    if test "$mode" = 120000; then
      actual=$(readlink -n -- "$path" | git hash-object --stdin)
    else
      test -f "$path" || fail "Missing tracked source: $path"
      test ! -L "$path" || fail "Unexpected source symlink: $path"
      actual=$(git hash-object --no-filters -- "$path")
    fi
    test "$actual" = "$expected" || fail "Changed tracked source: $path"
  done <&"$tree_fd"
  exec {tree_fd}<&-
  wait "$tree_pid" || fail 'Unable to enumerate tracked source.'
  untracked=$(git ls-files --others --exclude-standard) || fail 'Unable to enumerate untracked source.'
  test -z "$untracked" || fail 'Unexpected untracked source.'
  test -f composer.json && test -f composer.lock
}

snapshot_dependencies() {
  test -d vendor || fail 'Locked dependencies must already be installed.'
  # Include file names, bytes and symlink targets; caches remain outside vendor.
  find vendor -type f -print0 | sort -z | xargs -0 -r sha256sum
  find vendor -type l -printf '%p -> %l\n' | sort
}

verify_source
case ${1:-} in
  baseline)
    test "${RAN_BOOSTER_CORE_PATH+x}" != x || fail 'Core environment is forbidden during baseline.'
    test ! -e ../booster && test ! -L ../booster || fail 'Core checkout is forbidden during baseline.'
    test ! -e "$state" || fail 'Phase state already exists.'
    mkdir -p "$state"
    trap 'rm -rf -- "$state"' EXIT
    snapshot_dependencies > "$state/dependencies"
    # Detect accidental cross-step environment changes. These files and the
    # snapshots share the runner UID; this is not hostile-code authentication.
    for command_file in GITHUB_ENV GITHUB_PATH; do
      if test -n "${!command_file:-}"; then
        cp -- "${!command_file}" "$state/$command_file"
      fi
    done
    composer check
    # Retain the shared provider sweep beyond composer lint:syntax's src/tests.
    find . \( -path './vendor' -o -path './node_modules' \) -prune -o -type f -name '*.php' -print0 > "$state/php-files"
    while IFS= read -r -d '' file; do php -l "$file"; done < "$state/php-files"
    verify_source
    snapshot_dependencies > "$state/dependencies-after"
    cmp "$state/dependencies" "$state/dependencies-after" || fail 'Baseline altered locked dependencies.'
    for command_file in GITHUB_ENV GITHUB_PATH; do
      if test -n "${!command_file:-}"; then
        cmp "$state/$command_file" "${!command_file}" || fail "Baseline altered $command_file."
      fi
    done
    printf '%s\n' "$RAN_EXPECTED_SHA" > "$state/baseline-passed"
    trap - EXIT
    ;;
  host)
    [[ ${RAN_BOOSTER_HOST_SHA:-} =~ ^[0-9a-f]{40}$ ]] || fail 'Expected host SHA is missing or malformed.'
    test "${RAN_BOOSTER_CORE_PATH:-}" = "$(realpath ../booster)" || fail 'Unexpected Core checkout path.'
    test "$(git -C "$RAN_BOOSTER_CORE_PATH" rev-parse HEAD)" = "$RAN_BOOSTER_HOST_SHA" || fail 'Wrong host revision.'
    test -f "$state/baseline-passed" || fail 'Baseline has not passed.'
    test "$(cat "$state/baseline-passed")" = "$RAN_EXPECTED_SHA" || fail 'Baseline belongs to another source.'
    trap 'rm -rf -- "$state"' EXIT
    snapshot_dependencies > "$state/dependencies-before-host"
    cmp "$state/dependencies" "$state/dependencies-before-host" || fail 'Dependencies changed between phases.'
    composer check:host
    verify_source
    snapshot_dependencies > "$state/dependencies-after-host"
    cmp "$state/dependencies" "$state/dependencies-after-host" || fail 'Host checks altered locked dependencies.'
    ;;
  *) fail 'Expected baseline or host phase.' ;;
esac
