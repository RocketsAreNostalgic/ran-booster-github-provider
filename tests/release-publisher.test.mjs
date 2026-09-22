import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import { runPublisher, verifyReleaseDelta } from "../scripts/release-publisher.mjs";
import { manifestVersion } from "../scripts/release-publisher-content.mjs";

const REPOSITORY = "RocketsAreNostalgic/ran-booster-github-provider";
const ID = 1370171375;
const VERSION = "0.1.0-beta.1";
const RELEASE_BRANCH = "release-please--branches--main--components--ran/booster-github-provider";

function git(root, args) {
  return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim();
}

function fixture(rootOnly = false) {
  const root = mkdtempSync(join(tmpdir(), "provider-release-publisher-"));
  git(root, ["init", "--initial-branch=main"]);
  git(root, ["config", "user.name", "Release Test"]);
  git(root, ["config", "user.email", "release@example.invalid"]);

  const write = (version, changelog) => {
    writeFileSync(join(root, ".release-please-manifest.json"), JSON.stringify({ ".": version }));
    writeFileSync(join(root, "composer.json"), JSON.stringify({ name: "ran/booster-github-provider", type: "library" }));
    writeFileSync(join(root, "CHANGELOG.md"), changelog);
  };

  write("0.0.0", "# Changelog\n\n## [Unreleased]\n\nBootstrap\n");
  git(root, ["add", "."]);
  git(root, ["commit", "-m", "chore: bootstrap"]);
  const base = git(root, ["rev-parse", "HEAD"]);
  let head = base;

  if (!rootOnly) {
    git(root, ["checkout", "-b", RELEASE_BRANCH]);
    write(
      VERSION,
      `# Changelog\n\n## ${VERSION} (2026-09-17)\n\n### Features\n\n* first provider release\n\n## [Unreleased]\n\nBootstrap\n`,
    );
    git(root, ["add", "."]);
    git(root, ["commit", "-m", `chore(main): release ${VERSION}`]);
    head = git(root, ["rev-parse", "HEAD"]);
    git(root, ["checkout", "main"]);
    git(root, ["merge", "--no-ff", "--no-edit", head]);
  }

  const candidate = git(root, ["rev-parse", "HEAD"]);
  const tree = git(root, ["show", "-s", "--format=%T", candidate]);
  const eventPath = join(root, "event.json");
  const value = { root, base, head, candidate, tree, eventPath };
  writeEvent(value);
  return value;
}

function writeEvent(value, changes = {}) {
  writeFileSync(value.eventPath, JSON.stringify({
    repository: { id: ID },
    workflow_run: {
      event: "push",
      conclusion: "success",
      head_branch: "main",
      head_sha: value.candidate,
      head_repository: { id: ID, full_name: REPOSITORY },
      ...changes,
    },
  }));
}

function transport(value, options = {}) {
  const calls = [];
  const state = { tag: null, release: null, labels: ["autorelease: pending"] };
  const response = (data, status = 200) => new Response(
    data === null ? null : JSON.stringify(data),
    { status, headers: { link: "" } },
  );

  const pull = () => ({
    state: "closed",
    merged_at: "2026-09-17T10:00:00Z",
    draft: false,
    merge_commit_sha: value.candidate,
    base: { ref: "main", sha: value.base, repo: { id: ID, full_name: REPOSITORY } },
    head: { ref: RELEASE_BRANCH, sha: value.head, repo: { id: ID, full_name: REPOSITORY } },
    user: { login: "github-actions[bot]" },
    title: `chore(main): release ${VERSION}`,
    number: 7,
    labels: state.labels.map((name) => ({ name })),
  });

  const fetch = async (url, init = {}) => {
    const parsed = new URL(url);
    const method = init.method ?? "GET";
    const headers = new Headers(init.headers ?? {});
    calls.push({
      path: parsed.pathname + parsed.search,
      method,
      contentType: headers.get("content-type"),
    });

    if (parsed.pathname.endsWith(`/commits/${value.candidate}/pulls`)) {
      return response(options.ordinary ? [] : [pull()]);
    }
    if (parsed.pathname.endsWith(`/git/commits/${value.head}`)) {
      return response({ sha: value.head, tree: { sha: options.badTree ? "bad" : value.tree } });
    }
    if (parsed.pathname.endsWith("/git/ref/heads/main")) {
      return response({ object: { sha: value.candidate } });
    }
    if (parsed.pathname.includes("/git/ref/tags/")) {
      return state.tag ? response(state.tag) : response(null, 404);
    }
    if (parsed.pathname.includes("/releases/tags/")) {
      return state.release ? response(state.release) : response(null, 404);
    }
    if (parsed.pathname.endsWith("/releases") && method === "POST") {
      const body = JSON.parse(init.body);
      state.tag = { object: { type: "commit", sha: value.candidate } };
      state.release = { ...body, id: 99, immutable: true, assets: [] };
      return response(state.release, 201);
    }
    if (parsed.pathname.endsWith("/issues/7/labels") && method === "POST") {
      if (options.failLabel) {
        options.failLabel = false;
        return response({ message: "interrupted" }, 500);
      }
      state.labels = ["autorelease: tagged"];
      return response(null, 204);
    }
    if (parsed.pathname.includes("/issues/7/labels/autorelease%3A%20pending") && method === "DELETE") {
      state.labels = ["autorelease: tagged"];
      return response(null, 204);
    }
    if (parsed.pathname.endsWith("/pulls/7")) {
      return response(pull());
    }
    throw new Error(`unexpected ${method} ${parsed.pathname}${parsed.search}`);
  };

  return { calls, fetch, state };
}

function environment(value, fetch) {
  const names = [
    "GITHUB_REPOSITORY",
    "GITHUB_EVENT_PATH",
    "GITHUB_TOKEN",
    "RAN_RELEASE_PUBLISHER_MUTATE",
    "RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID",
  ];
  const before = Object.fromEntries(names.map((name) => [name, process.env[name]]));
  const priorFetch = globalThis.fetch;
  process.env.GITHUB_REPOSITORY = REPOSITORY;
  process.env.GITHUB_EVENT_PATH = value.eventPath;
  process.env.GITHUB_TOKEN = "test";
  process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1";
  process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = String(ID);
  globalThis.fetch = fetch;

  return () => {
    for (const name of names) {
      if (before[name] === undefined) delete process.env[name];
      else process.env[name] = before[name];
    }
    globalThis.fetch = priorFetch;
    rmSync(value.root, { recursive: true, force: true });
  };
}

test("publisher creates one immutable release then reads it back idempotently", async (context) => {
  const value = fixture();
  const mocked = transport(value);
  context.after(environment(value, mocked.fetch));

  const result = await runPublisher(value.root);
  assert.equal(result.action, "create_release");
  assert.equal(mocked.state.release.immutable, true);
  assert.equal(mocked.state.release.target_commitish, value.candidate);
  assert.deepEqual(mocked.state.labels, ["autorelease: tagged"]);
  assert.equal((await runPublisher(value.root)).action, "already_published");
  assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1);
});

test("publisher sends JSON content type for JSON mutation bodies", async (context) => {
  const value = fixture();
  const mocked = transport(value);
  context.after(environment(value, mocked.fetch));
  await runPublisher(value.root);
  const jsonPosts = mocked.calls.filter((call) => call.method === "POST");
  assert.ok(jsonPosts.length >= 2);
  for (const call of jsonPosts) {
    assert.equal(call.contentType, "application/json");
  }
});

test("ordinary unreleased main is read-only", async (context) => {
  const value = fixture(true);
  const mocked = transport(value, { ordinary: true });
  context.after(environment(value, mocked.fetch));
  assert.deepEqual(await runPublisher(value.root), { action: "none", reason: "ordinary_main" });
  assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
});

test("malformed release head and disabled mutation make no writes", async () => {
  for (const mode of ["bad-tree", "disabled"]) {
    const value = fixture();
    const mocked = transport(value, { badTree: mode === "bad-tree" });
    const restore = environment(value, mocked.fetch);
    if (mode === "disabled") delete process.env.RAN_RELEASE_PUBLISHER_MUTATE;
    try {
      await assert.rejects(runPublisher(value.root));
      assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
    } finally {
      restore();
    }
  }
});

test("immutable-release acknowledgement is required before write", async () => {
  const value = fixture();
  const mocked = transport(value);
  const restore = environment(value, mocked.fetch);
  delete process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID;
  try {
    await assert.rejects(runPublisher(value.root), (error) => error.code === "immutable_releases_disabled");
    assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
  } finally {
    restore();
  }
});

test("post-create label interruption reconciles without duplicate publication", async (context) => {
  const value = fixture();
  const mocked = transport(value, { failLabel: true });
  context.after(environment(value, mocked.fetch));
  await assert.rejects(runPublisher(value.root));
  assert.equal(mocked.state.labels[0], "autorelease: pending");
  assert.equal((await runPublisher(value.root)).action, "reconcile_labels");
  assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1);
});

test("wrong CI identity refuses without writes", async () => {
  const changes = [
    { event: "workflow_dispatch" },
    { conclusion: "failure" },
    { head_branch: "other" },
    { head_repository: { id: ID + 1, full_name: REPOSITORY } },
  ];
  for (const change of changes) {
    const value = fixture();
    writeEvent(value, change);
    const mocked = transport(value);
    const restore = environment(value, mocked.fetch);
    try {
      await assert.rejects(runPublisher(value.root), (error) => error.code === "quality_identity_invalid");
      assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
    } finally {
      restore();
    }
  }
});


test("publisher content accepts Release Please prerelease major transitions", () => {
  assert.equal(manifestVersion(JSON.stringify({ ".": "1.0.0-beta.5" }), "candidate"), "1.0.0-beta.5");
  const composer = JSON.stringify({ name: "ran/booster-github-provider", type: "library" });
  const parent = {
    manifest: JSON.stringify({ ".": "0.1.0-beta.5" }),
    composer,
    changelog: "# Changelog\n\n## [0.1.0-beta.5](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v0.1.0-beta.4...v0.1.0-beta.5) (2026-09-19)\n\nold\n",
  };
  const candidate = {
    manifest: JSON.stringify({ ".": "1.0.0-beta.5" }),
    composer,
    changelog: "# Changelog\n\n## [1.0.0-beta.5](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v0.1.0-beta.5...v1.0.0-beta.5) (2026-09-21)\n\n### ⚠ BREAKING CHANGES\n\n* provider API 11\n\n" + parent.changelog.slice("# Changelog\n\n".length),
  };
  assert.deepEqual(verifyReleaseDelta(parent, candidate), {
    parentVersion: "0.1.0-beta.5",
    candidateVersion: "1.0.0-beta.5",
  });
});
