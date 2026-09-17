import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import {
  assertReleaseClassification,
  classifyTitle,
  productionRequirementsChanged,
  releaseSignificantChange,
  runCli,
  visibleReleaseTypes,
} from "../scripts/release-classification.mjs";

const releaseConfig = {
  packages: {
    ".": {
      "changelog-sections": [
        { type: "feat", section: "Features" },
        { type: "fix", section: "Bug Fixes" },
        { type: "perf", section: "Performance" },
        { type: "revert", section: "Reverts" },
        { type: "refactor", section: "Code Refactoring", hidden: true },
        { type: "chore", section: "Miscellaneous Chores", hidden: true },
      ],
    },
  },
};

const baseComposer = {
  require: {
    php: "^8.2",
    "ran/updater-support": "0.1.0-beta.3",
  },
  "require-dev": { "phpstan/phpstan": "^2.1" },
};

function git(root, args) {
  return execFileSync("git", args, {
    cwd: root,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  }).trim();
}

function writeJson(path, value) {
  writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`, "utf8");
}

test("derives provider-visible release-driving types", () => {
  assert.deepEqual([...visibleReleaseTypes(releaseConfig)], ["feat", "fix", "perf", "revert"]);
});

test("parses scoped and breaking Conventional Commit titles", () => {
  assert.deepEqual(classifyTitle("fix(release): tighten publisher"), { type: "fix", breaking: false });
  assert.deepEqual(classifyTitle("refactor!: replace provider contract"), { type: "refactor", breaking: true });
});

test("production requirement comparison ignores key order and require-dev", () => {
  assert.equal(productionRequirementsChanged(baseComposer, {
    ...baseComposer,
    require: { "ran/updater-support": "0.1.0-beta.3", php: "^8.2" },
    "require-dev": { "phpstan/phpstan": "^3.0" },
  }), false);
  assert.equal(productionRequirementsChanged(baseComposer, {
    ...baseComposer,
    require: { ...baseComposer.require, "ran/wp-release-updater": "0.1.0-beta.4" },
  }), true);
});

test("provider source and production requirements are release-significant", () => {
  assert.equal(releaseSignificantChange({ baseComposer, headComposer: baseComposer, paths: ["src/GitHubProvider.php"] }), true);
  assert.equal(releaseSignificantChange({
    baseComposer,
    headComposer: { ...baseComposer, require: { ...baseComposer.require, php: "^8.3" } },
    paths: ["composer.json"],
  }), true);
  assert.equal(releaseSignificantChange({ baseComposer, headComposer: baseComposer, paths: ["README.md"] }), false);
});

test("release-significant changes reject hidden squash classifications", () => {
  assert.throws(() => assertReleaseClassification({
    baseComposer,
    headComposer: baseComposer,
    releaseConfig,
    paths: ["src/GitHubProvider.php"],
    title: "refactor: adjust provider runtime",
  }), /release-significant provider changes require/);
});

test("visible or explicit breaking classifications admit release-significant changes", () => {
  assert.equal(assertReleaseClassification({
    baseComposer,
    headComposer: baseComposer,
    releaseConfig,
    paths: ["src/GitHubProvider.php"],
    title: "fix(provider): preserve runtime behavior",
  }).classification.type, "fix");
  assert.equal(assertReleaseClassification({
    baseComposer,
    headComposer: baseComposer,
    releaseConfig,
    paths: ["src/GitHubProvider.php"],
    title: "refactor!: replace provider contract",
  }).classification.breaking, true);
});

test("documentation-only changes do not require a release-driving title", () => {
  assert.deepEqual(assertReleaseClassification({
    baseComposer,
    headComposer: baseComposer,
    releaseConfig,
    paths: ["README.md"],
    title: "Update docs",
  }), { required: false, classification: null });
});

test("CLI verifies exact base/head and changed source paths", () => {
  const root = mkdtempSync(join(tmpdir(), "provider-release-classification-"));
  try {
    git(root, ["init", "--initial-branch=main"]);
    git(root, ["config", "user.name", "Release Test"]);
    git(root, ["config", "user.email", "release@example.invalid"]);
    writeJson(join(root, "composer.json"), baseComposer);
    writeJson(join(root, "release-please-config.json"), releaseConfig);
    writeFileSync(join(root, "README.md"), "base\n");
    git(root, ["add", "."]);
    git(root, ["commit", "-m", "chore: base"]);
    const baseSha = git(root, ["rev-parse", "HEAD"]);

    execFileSync("mkdir", ["-p", join(root, "src")]);
    writeFileSync(join(root, "src", "GitHubProvider.php"), "<?php\n");
    git(root, ["add", "src/GitHubProvider.php"]);
    git(root, ["commit", "-m", "fix: provider"]);
    const headSha = git(root, ["rev-parse", "HEAD"]);

    const result = runCli(root, {
      RAN_RELEASE_BASE_SHA: baseSha,
      RAN_RELEASE_HEAD_SHA: headSha,
      RAN_RELEASE_PR_TITLE: "fix(provider): add provider source",
    });
    assert.equal(result.required, true);

    assert.throws(() => runCli(root, {
      RAN_RELEASE_BASE_SHA: baseSha,
      RAN_RELEASE_HEAD_SHA: baseSha,
      RAN_RELEASE_PR_TITLE: "fix(provider): add provider source",
    }), /does not match pull request head/);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
