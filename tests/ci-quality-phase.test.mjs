import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import nodeTest from "node:test";

// The helper is specific to the Linux CI runner, not a new portable local gate.
const test = (name, fn) => nodeTest(name, {
  skip: process.platform !== "linux" && "CI phase fixtures require the Linux runner's Bash/GNU utilities",
}, fn);

function fixture(t) {
  const dir = mkdtempSync(join(tmpdir(), "provider-ci-phase-"));
  t.after(() => rmSync(dir, { recursive: true, force: true }));
  const provider = join(dir, "provider");
  const bin = join(dir, "bin");
  mkdirSync(join(provider, "scripts"), { recursive: true });
  mkdirSync(bin);
  cpSync(new URL("../scripts/ci-quality-phase.sh", import.meta.url), join(provider, "scripts/ci-quality-phase.sh"));
  writeFileSync(join(provider, "composer.json"), "{}\n");
  writeFileSync(join(provider, "composer.lock"), "{}\n");
  writeFileSync(join(provider, "outside.php"), "<?php\n");
  writeFileSync(join(provider, ".gitignore"), "/vendor/\n");
  const git = (...args) => {
    const r = spawnSync("git", args, { cwd: provider, encoding: "utf8" });
    assert.equal(r.status, 0, r.stderr);
    return r.stdout.trim();
  };
  git("init", "-q");
  git("add", ".");
  git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", "commit", "-qm", "fixture");
  mkdirSync(join(provider, "vendor"));
  writeFileSync(join(provider, "vendor", "dependency.php"), "locked bytes\n");
  writeFileSync(join(bin, "composer"), `#!/usr/bin/env bash
set -eu
printf 'composer %s core=%s\\n' "$*" "\${RAN_BOOSTER_CORE_PATH:-absent}" >> "$TRACE"
if test "$*" = "check"; then
  test "\${BASELINE_FAIL:-0}" = 0
  if test "\${MUTATE_BASELINE:-0}" = 1; then printf 'changed' >> composer.lock; fi
  if test "\${MUTATE_VENDOR:-0}" = 1; then printf 'changed' >> vendor/dependency.php; fi
  if test "\${MUTATE_COMMAND_FILE:-}" != ''; then printf 'changed' >> "\${!MUTATE_COMMAND_FILE}"; fi
else
  test "$*" = "check:host"
  test "\${HOST_FAIL:-0}" = 0
fi
`, { mode: 0o755 });
  writeFileSync(join(bin, "php"), `#!/usr/bin/env bash
set -eu
printf 'php %s\\n' "$*" >> "$TRACE"
test "\${LINT_FAIL:-0}" = 0
`, { mode: 0o755 });
  const env = { ...process.env, PATH: `${bin}:${process.env.PATH}`, TRACE: join(dir, "trace"), RAN_EXPECTED_SHA: git("rev-parse", "HEAD"), RAN_CI_PHASE_STATE: join(dir, "state") };
  delete env.RAN_BOOSTER_CORE_PATH;
  delete env.RAN_BOOSTER_HOST_SHA;
  const run = (phase, extra = {}) => spawnSync("bash", ["scripts/ci-quality-phase.sh", phase], { cwd: provider, env: { ...env, ...extra }, encoding: "utf8" });
  const host = () => {
    const path = join(dir, "booster");
    mkdirSync(path);
    for (const args of [["init", "-q"], ["-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", "commit", "--allow-empty", "-qm", "host"]]) {
      assert.equal(spawnSync("git", args, { cwd: path }).status, 0);
    }
    return { RAN_BOOSTER_CORE_PATH: path, RAN_BOOSTER_HOST_SHA: spawnSync("git", ["rev-parse", "HEAD"], { cwd: path, encoding: "utf8" }).stdout.trim() };
  };
  return { dir, provider, bin, git, env, run, host, trace: () => readFileSync(env.TRACE, "utf8") };
}

test("baseline is host-independent, lints PHP outside src/tests, then host runs once", (t) => {
  const f = fixture(t);
  const baseline = f.run("baseline");
  assert.equal(baseline.status, 0, baseline.stderr);
  assert.match(f.trace(), /composer check core=absent/);
  assert.match(f.trace(), /php -l \.\/outside.php/);
  const host = f.run("host", f.host());
  assert.equal(host.status, 0, host.stderr);
  assert.equal(f.trace().match(/composer check:host/g)?.length, 1);
});

test("failed Git source enumeration cannot silently admit checks", (t) => {
  const f = fixture(t);
  writeFileSync(join(f.bin, "git"), '#!/usr/bin/env bash\nif test "$1" = ls-tree; then exit 23; fi\nexec /usr/bin/git "$@"\n', { mode: 0o755 });
  const r = f.run("baseline");
  assert.notEqual(r.status, 0);
  assert.match(r.stderr, /Unable to enumerate tracked source/);
  assert.throws(f.trace);
});

for (const [name, setup, extra, message] of [
  ["baseline Core environment", () => {}, { RAN_BOOSTER_CORE_PATH: "" }, /Core environment/],
  ["baseline Core checkout", (f) => f.host(), {}, /Core checkout/],
  ["wrong source", () => {}, { RAN_EXPECTED_SHA: "0".repeat(40) }, /Wrong source/],
  ["malformed source", () => {}, { RAN_EXPECTED_SHA: "main" }, /malformed/],
  ["dirty lock hidden by index flags", (f) => { f.git("update-index", "--assume-unchanged", "composer.lock"); writeFileSync(join(f.provider, "composer.lock"), "changed"); }, {}, /Changed tracked source/],
  ["untracked source", (f) => writeFileSync(join(f.provider, "untracked.php"), "<?php"), {}, /untracked source/],
  ["source-local phase state", (f) => { f.env.RAN_CI_PHASE_STATE = join(f.provider, "state"); }, {}, /outside the source/],
]) {
  test(`reject ${name} before any checks`, (t) => {
    const f = fixture(t);
    setup(f);
    const r = f.run("baseline", extra);
    assert.notEqual(r.status, 0);
    assert.match(r.stderr, message);
    assert.throws(f.trace);
  });
}

for (const failure of ["BASELINE_FAIL", "LINT_FAIL", "MUTATE_BASELINE", "MUTATE_VENDOR"]) {
  test(`${failure} cannot admit host checks`, (t) => {
    const f = fixture(t);
    assert.notEqual(f.run("baseline", { [failure]: "1" }).status, 0);
    const r = f.run("host", f.host());
    assert.notEqual(r.status, 0);
    assert.doesNotMatch(f.trace(), /composer check:host/);
  });
}

for (const commandFile of ["GITHUB_ENV", "GITHUB_PATH"]) {
  test(`baseline cannot accidentally alter ${commandFile} for the host step`, (t) => {
    const f = fixture(t);
    const path = join(f.dir, commandFile);
    writeFileSync(path, "");
    const r = f.run("baseline", { [commandFile]: path, MUTATE_COMMAND_FILE: commandFile });
    assert.notEqual(r.status, 0);
    assert.match(r.stderr, new RegExp(`Baseline altered ${commandFile}`));
    assert.notEqual(f.run("host", f.host()).status, 0);
    assert.doesNotMatch(f.trace(), /composer check:host/);
  });
}

for (const [name, change, extra, message] of [
  ["missing baseline", (f) => rmSync(join(f.dir, "state"), { recursive: true }), {}, /Baseline has not passed/],
  ["altered dependency", (f) => writeFileSync(join(f.provider, "vendor", "dependency.php"), "changed"), {}, /Dependencies changed between phases/],
  ["wrong baseline identity", (f) => writeFileSync(join(f.dir, "state", "baseline-passed"), "0".repeat(40)), {}, /another source/],
  ["wrong host", () => {}, { RAN_BOOSTER_HOST_SHA: "0".repeat(40) }, /Wrong host/],
  ["host failure", () => {}, { HOST_FAIL: "1" }, null],
]) {
  test(`reject ${name}`, (t) => {
    const f = fixture(t);
    assert.equal(f.run("baseline").status, 0);
    const hostEnv = f.host();
    change(f);
    const r = f.run("host", { ...hostEnv, ...extra });
    assert.notEqual(r.status, 0);
    if (message) assert.match(r.stderr, message);
  });
}
