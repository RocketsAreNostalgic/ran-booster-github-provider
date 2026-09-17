import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const workflow = readFileSync(
  new URL("../.github/workflows/release-please.yml", import.meta.url),
  "utf8",
);
const ciWorkflow = readFileSync(
  new URL("../.github/workflows/ci.yml", import.meta.url),
  "utf8",
);

test("release job authenticates canonical CI before mutation", () => {
  const jobStart = workflow.indexOf("jobs:\n  release:");
  const ifMarker = "    if: >-\n";
  const ifStart = workflow.indexOf(ifMarker, jobStart);
  const runsOn = workflow.indexOf("\n    runs-on:", ifStart);
  assert.ok(jobStart >= 0 && ifStart > jobStart && runsOn > ifStart);

  const condition = workflow
    .slice(ifStart + ifMarker.length, runsOn)
    .trimEnd()
    .split("\n")
    .map((line) => line.trim())
    .join(" ");
  const terms = condition
    .replace("${{", "")
    .replace("}}", "")
    .split("&&")
    .map((term) => term.trim());

  for (const expected of [
    "github.event.workflow_run.event == 'push'",
    "github.event.workflow_run.conclusion == 'success'",
    "github.event.workflow_run.path == '.github/workflows/ci.yml'",
    "github.event.workflow_run.head_branch == 'main'",
    "github.event.workflow_run.head_repository.id == github.repository_id",
    "github.event.workflow_run.head_repository.full_name == github.repository",
  ]) {
    assert.ok(terms.includes(expected), `missing admission term: ${expected}`);
  }
});

test("publisher checkout stays bound to the exact workflow_run SHA", () => {
  assert.match(workflow, /ref: \$\{\{ github\.event\.workflow_run\.head_sha \}\}/);
  assert.match(workflow, /persist-credentials: false/);
  assert.match(workflow, /test "\$\(git rev-parse HEAD\)" = "\$RAN_RELEASE_SHA"/);
});

test("required quality cannot be manufactured by workflow dispatch without PR classification", () => {
  assert.doesNotMatch(ciWorkflow, /^\s*workflow_dispatch:/m);
  assert.match(ciWorkflow, /pull_request:\n\s+types: \[opened, synchronize, reopened, edited\]/);
  assert.match(ciWorkflow, /RAN_RELEASE_PR_TITLE: \$\{\{ github\.event\.pull_request\.title \}\}/);
  assert.match(ciWorkflow, /quality:\n\s+name: quality[\s\S]*needs:[\s\S]*- release-classification/);
});
