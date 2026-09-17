import assert from "node:assert/strict";
import test from "node:test";

import {
  PHASE3_BOOTSTRAP_SHA,
  PublisherRefusal,
  candidateIdentity,
  classifyParentReleaseMetadata,
  decidePublication,
  effectiveParentVersion,
  verifyReleaseDelta,
  verifyPublishedState,
} from "../scripts/release-publisher.mjs";

const SHA = "a".repeat(40);
const REPOSITORY = "RocketsAreNostalgic/ran-booster-github-provider";
const ID = 1370171375;
const VERSION = "0.1.0-beta.1";
const RELEASE_BRANCH = "release-please--branches--main--components--ran/booster-github-provider";

function contents(version = VERSION) {
  return {
    manifest: JSON.stringify({ ".": version }),
    composer: JSON.stringify({ name: "ran/booster-github-provider", type: "library" }),
    changelog: `# Changelog\n\n## ${version} (2026-09-17)\n\n### Features\n\n* first provider release\n\n## [Unreleased]\n\nBootstrap\n`,
  };
}

function refusal(code, callback) {
  assert.throws(callback, (error) => error instanceof PublisherRefusal && error.code === code);
}

function pull(changes = {}) {
  return {
    state: "closed",
    merged_at: "2026-09-17T10:00:00Z",
    draft: false,
    merge_commit_sha: SHA,
    base: { ref: "main", sha: "c".repeat(40), repo: { id: ID, full_name: REPOSITORY } },
    head: { ref: RELEASE_BRANCH, sha: "d".repeat(40), repo: { id: ID, full_name: REPOSITORY } },
    head_tree_sha: "e".repeat(40),
    user: { login: "github-actions[bot]" },
    title: `chore(main): release ${VERSION}`,
    number: 7,
    labels: [{ name: "autorelease: pending" }],
    ...changes,
  };
}

function input() {
  return {
    event: {
      event: "push",
      conclusion: "success",
      head_branch: "main",
      head_sha: SHA,
      head_repository: { id: ID, full_name: REPOSITORY },
    },
    candidateSha: SHA,
    mainSha: SHA,
    identity: candidateIdentity(contents(), SHA),
    pulls: [pull()],
    repository: REPOSITORY,
    repositoryId: ID,
    tagRef: null,
    release: null,
    immutableReleasesEnabled: true,
    commit: {
      sha: SHA,
      parents: [{ sha: "c".repeat(40) }, { sha: "d".repeat(40) }],
      tree: { sha: "e".repeat(40) },
      parentVersion: "0.0.0",
      changedPaths: [".release-please-manifest.json", "CHANGELOG.md"],
    },
  };
}

test("candidate binds provider package identity, version and notes", () => {
  const identity = candidateIdentity(contents(), SHA);
  assert.equal(identity.packageName, "ran/booster-github-provider");
  assert.equal(identity.version, VERSION);
  assert.equal(identity.tag, `v${VERSION}`);
});

test("first release delta is exactly 0.0.0 to beta.1", () => {
  const parent = {
    ...contents("0.0.0"),
    changelog: "# Changelog\n\n## [Unreleased]\n\nBootstrap\n",
  };
  assert.deepEqual(verifyReleaseDelta(parent, contents()), {
    parentVersion: "0.0.0",
    candidateVersion: VERSION,
  });
  for (const version of ["0.1.0-beta.0", "0.1.0-beta.2"]) {
    refusal("release_version_not_advanced", () => verifyReleaseDelta(parent, contents(version)));
  }
});

test("first release accepts the Release Please compare-link heading form", () => {
  const parent = {
    ...contents("0.0.0"),
    changelog: "# Changelog\n\n## [Unreleased]\n\nBootstrap\n",
  };
  const linked = {
    ...contents(),
    changelog: `# Changelog\n\n## [${VERSION}](https://github.com/${REPOSITORY}/compare/v0.0.0...v${VERSION}) (2026-09-17)\n\n### Features\n\n* first provider release\n\n## [Unreleased]\n\nBootstrap\n`,
  };
  assert.deepEqual(verifyReleaseDelta(parent, linked), {
    parentVersion: "0.0.0",
    candidateVersion: VERSION,
  });
});

test("only the exact Phase 3 baseline may reset staged beta metadata to unreleased", () => {
  assert.equal(effectiveParentVersion(PHASE3_BOOTSTRAP_SHA, VERSION, true), "0.0.0");
  assert.equal(effectiveParentVersion("b".repeat(40), "0.0.0", true), "0.0.0");
  assert.equal(effectiveParentVersion("b".repeat(40), VERSION, false), VERSION);
  refusal("release_version_regression", () => effectiveParentVersion("b".repeat(40), VERSION, true));
});

test("exact successful normal Release Please merge may publish", () => {
  assert.deepEqual(decidePublication(input()), { action: "create_release", pullNumber: 7 });
});

test("squash, wrong tree, changed paths and moved main refuse publication", () => {
  const base = input();
  refusal("release_pr_not_normal_merge", () => decidePublication({
    ...base,
    commit: { ...base.commit, parents: [base.commit.parents[0]] },
  }));
  refusal("release_pr_not_normal_merge", () => decidePublication({
    ...base,
    commit: { ...base.commit, tree: { sha: "f".repeat(40) } },
  }));
  refusal("release_paths_invalid", () => decidePublication({
    ...base,
    commit: { ...base.commit, changedPaths: [...base.commit.changedPaths, "src/GitHubProvider.php"] },
  }));
  refusal("main_moved", () => decidePublication({ ...base, mainSha: "f".repeat(40) }));
});

test("wrong event or repository identity fails closed", () => {
  const base = input();
  for (const event of [
    { ...base.event, event: "workflow_dispatch" },
    { ...base.event, conclusion: "failure" },
    { ...base.event, head_branch: "other" },
    { ...base.event, head_repository: { id: ID + 1, full_name: REPOSITORY } },
    { ...base.event, head_repository: { id: ID, full_name: "other/repo" } },
  ]) {
    refusal("quality_identity_invalid", () => decidePublication({ ...base, event }));
  }
});

test("release PR identity is exact and unambiguous", () => {
  const base = input();
  for (const candidate of [
    pull({ user: { login: "someone" } }),
    pull({ title: "chore: release" }),
    pull({ head: { ...pull().head, ref: "other" } }),
    pull({ head: { ...pull().head, repo: { id: ID + 1, full_name: REPOSITORY } } }),
  ]) {
    refusal("release_pr_invalid", () => decidePublication({ ...base, pulls: [candidate] }));
  }
  refusal("release_pr_ambiguous", () => decidePublication({ ...base, pulls: [pull(), pull()] }));
});

test("ordinary unreleased main has no publication side effect", () => {
  assert.deepEqual(decidePublication({
    ...input(),
    identity: { candidateSha: SHA, version: "0.0.0" },
    pulls: [],
    commit: { parentVersion: "0.0.0" },
  }), { action: "none", reason: "ordinary_main" });
});

test("immutable release readback requires exact tag, target and no assets", () => {
  const identity = candidateIdentity(contents(), SHA);
  const tagRef = { object: { type: "commit", sha: SHA } };
  const release = {
    id: 1,
    tag_name: identity.tag,
    target_commitish: SHA,
    name: identity.tag,
    body: identity.notes,
    draft: false,
    prerelease: true,
    immutable: true,
    assets: [],
  };
  assert.equal(verifyPublishedState(tagRef, release, identity), true);
  refusal("release_state_conflict", () => verifyPublishedState(tagRef, { ...release, immutable: false }, identity));
  refusal("release_asset_conflict", () => verifyPublishedState(tagRef, { ...release, assets: [{ id: 1 }] }, identity));
});

test("partial remote publication state fails closed", () => {
  const base = input();
  refusal("partial_publication_state", () => decidePublication({
    ...base,
    tagRef: { object: { type: "commit", sha: SHA } },
  }));
  refusal("release_without_tag", () => decidePublication({ ...base, release: { id: 1 } }));
  refusal("immutable_releases_disabled", () => decidePublication({ ...base, immutableReleasesEnabled: false }));
});

test("parent release metadata must be all present or all absent", () => {
  const blob = { mode: "100644", type: "blob", sha: SHA };
  assert.equal(classifyParentReleaseMetadata({ manifest: null, changelog: null }), "absent");
  assert.equal(classifyParentReleaseMetadata({ manifest: blob, changelog: blob }), "complete");
  refusal("release_content_drift", () => classifyParentReleaseMetadata({ manifest: blob, changelog: null }));
});
