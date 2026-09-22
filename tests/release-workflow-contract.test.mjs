import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const workflow = readFileSync(new URL("../.github/workflows/release-please.yml", import.meta.url), "utf8");
const ciWorkflow = readFileSync(new URL("../.github/workflows/ci.yml", import.meta.url), "utf8");

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
});

test("canonical CI supports shared exact-head candidate dispatch", () => {
  assert.match(ciWorkflow, /^\s*workflow_dispatch:/m);
  assert.match(ciWorkflow, /pull_request:\n\s+types: \[opened, synchronize, reopened, edited\]/);
  assert.match(ciWorkflow, /RAN_RELEASE_PR_TITLE: \$\{\{ github\.event\.pull_request\.title \}\}/);
  assert.match(ciWorkflow, /quality:\n\s+name: quality[\s\S]*needs:[\s\S]*- release-classification/);
});
