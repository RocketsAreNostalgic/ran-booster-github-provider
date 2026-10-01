import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { spawnSync } from "node:child_process";

const workflow = readFileSync(new URL("../.github/workflows/release-please.yml", import.meta.url), "utf8");
const ciWorkflow = readFileSync(new URL("../.github/workflows/ci.yml", import.meta.url), "utf8");
const releaseConfig = JSON.parse(readFileSync(new URL("../release-please-config.json", import.meta.url), "utf8"));

test("release workflow is a thin pinned Profile A caller", () => {
  assert.match(workflow, /workflow_run:/);
  assert.match(workflow, /workflows: \[CI\]/);
  assert.match(workflow, /permissions: \{\}/);
  assert.match(workflow, /uses: RocketsAreNostalgic\/\.github\/\.github\/workflows\/release-profile-a\.yml@289352e08cdf10b15d07c4e1c890f385afc3d3f5/);
  assert.match(workflow, /expected-workflow-path: \.github\/workflows\/ci\.yml/);
  assert.match(workflow, /release-pr-head: release-please--branches--main--components--ran\/booster-github-provider/);
  assert.match(workflow, /actions: write/);
  assert.doesNotMatch(workflow, /release-publisher/);
  assert.doesNotMatch(workflow, /RAN_RELEASE_PUBLISHER_REPLAY/);
  assert.equal(releaseConfig.packages["."]["skip-github-release"], undefined);
});

test("canonical CI authenticates shared exact-head candidate dispatch", () => {
  assert.match(ciWorkflow, /^\s*workflow_dispatch:/m);
  assert.match(ciWorkflow, /pull-requests: read/);
  assert.match(ciWorkflow, /Resolve exact Release Please PR for dispatch/);
  assert.match(ciWorkflow, /expected_head='release-please--branches--main--components--ran\/booster-github-provider'/);
  assert.match(ciWorkflow, /\.user\.login == \$bot/);
  assert.match(ciWorkflow, /\.head\.sha == \$sha/);
  assert.match(ciWorkflow, /Expected exactly one canonical Release Please pull request for dispatch/);
  assert.match(ciWorkflow, /github\.event_name == 'pull_request' \|\| github\.event_name == 'workflow_dispatch'/);
  assert.match(ciWorkflow, /steps\.dispatch-pr\.outputs\.base_sha/);
  assert.match(ciWorkflow, /steps\.dispatch-pr\.outputs\.head_sha/);
  assert.match(ciWorkflow, /steps\.dispatch-pr\.outputs\.title/);
  assert.match(ciWorkflow, /quality:\n\s+name: quality[\s\S]*needs:[\s\S]*- release-classification/);
});

test("Provider retains its matrix and fail-closed gates around the pinned shared recipe", () => {
  const implementation = ciWorkflow.slice(ciWorkflow.indexOf("  implementation:"), ciWorkflow.indexOf("  quality:"));
  assert.match(implementation, /php: \['8.2', '8.5'\]/);
  assert.match(implementation, /fail-fast: false/);
  assert.match(implementation, /runs-on: blacksmith-2vcpu-ubuntu-2404/);
  assert.match(implementation, /permissions:\n      contents: read/);
  assert.match(implementation, /uses: RocketsAreNostalgic\/\.github\/\.github\/actions\/booster-library-quality@6e81370238e33c5b77641355a772557912f7fee7/);
  assert.match(implementation, /php-version: \$\{\{ matrix.php \}\}/);
  assert.match(implementation, /booster-sha: 18b0ec619174000a9a9dbc27b9d68b44b0265449/);
  assert.doesNotMatch(implementation, /continue-on-error|secrets:|actions\/cache@|contents: write|run:|setup-php@/);
  assert.match(ciWorkflow, /host-contract:[\s\S]*run: php tests\/host-contract.php/);
  const terminal = ciWorkflow.slice(ciWorkflow.indexOf("  quality:"));
  for (const lane of ["release-classification", "host-contract", "implementation"]) assert.match(terminal, new RegExp(`- ${lane}`));
  for (const result of ["CLASSIFICATION_RESULT", "HOST_CONTRACT_RESULT", "IMPLEMENTATION_RESULT"]) assert.ok(terminal.includes(`test "$${result}" = success`));
});

test("actual terminal shell rejects failed, skipped, cancelled and missing prerequisites", () => {
  const terminal = ciWorkflow.slice(ciWorkflow.indexOf("  quality:"));
  const shell = terminal.slice(terminal.indexOf("        run: |\n") + "        run: |\n".length).replace(/^          /gm, "");
  const passed = { CLASSIFICATION_RESULT: "success", HOST_CONTRACT_RESULT: "success", IMPLEMENTATION_RESULT: "success" };
  assert.equal(spawnSync("bash", ["-e", "-c", shell], { env: { ...process.env, ...passed } }).status, 0);
  for (const lane of Object.keys(passed)) {
    for (const result of ["failure", "cancelled", "skipped", ""]) {
      const r = spawnSync("bash", ["-e", "-c", shell], { env: { ...process.env, ...passed, [lane]: result } });
      assert.notEqual(r.status, 0, `${lane}=${result} must block quality`);
    }
  }
});
