import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

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

test("template-pack bridge is proved by the candidate reader against the additional exact host", () => {
  const compatibility = ciWorkflow.split("  template-pack-compatibility:\n")[1]?.split("  quality:\n")[0];
  assert.ok(compatibility, "missing supplemental compatibility job");
  assert.match(compatibility, /ref: 1c8283bc814ac593171d608d532226fcea83c6f4/);
  assert.match(compatibility, /test "\$\(git -C provider rev-parse HEAD\)" = "\$RAN_EXPECTED_SHA"/);
  assert.match(compatibility, /test "\$\(git -C booster rev-parse HEAD\)" = 1c8283bc814ac593171d608d532226fcea83c6f4/);
  assert.match(compatibility, /run: composer check:host/);
  assert.match(compatibility, /sha256sum --check --strict/);
  assert.match(compatibility, /7518b7c30b23fe95fb6c3c5211607657394ffcf440d258323d55c20b15bb5b14/);
  assert.match(compatibility, /2c223e14287a1fab28aa91e92d6a454b27e647cfb33f1bb3df965ed995cd89db/);
  assert.match(compatibility, /vendor\/bin\/phpunit --no-configuration/);
  assert.match(compatibility, /--bootstrap tests\/Booster\/GitHub\/bootstrap.php/);
  assert.match(compatibility, /--group published-template-pack --fail-on-skipped/);
  assert.doesNotMatch(compatibility, /booster\/vendor\/ran\/booster-github-provider/);
  assert.match(ciWorkflow, /quality:\n\s+name: quality[\s\S]*needs:[\s\S]*- template-pack-compatibility/);
  assert.match(ciWorkflow, /test "\$TEMPLATE_PACK_RESULT" = success/);
  assert.equal((ciWorkflow.match(/ref: ffc11fc8e40618624a785b7fca5193029c6d492e/g) || []).length, 2);
});
