#!/usr/bin/env python3
"""Bound dependency movement in the pinned Example's candidate assembly step."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys

# Importing the pinned preparer must not dirty its verified input checkout.
sys.dont_write_bytecode = True

PROVIDER = 'ran/booster-github-provider'
PREPARER_BLOB = '5ab8660f0a22d79d8d4df50ebe035d308068c47c'
UPDATE = ['update', PROVIDER, '--minimal-changes', '--prefer-source',
          '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins']


def candidate_packages(manifest):
    """Only the candidate and its direct production Composer package requirements."""
    if manifest['name'] != PROVIDER:
        raise ValueError('Unexpected candidate package')
    return {PROVIDER, *(name for name in manifest['require']
                       if re.fullmatch(r'[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*', name))}


def changed_packages(before, after, allowed):
    """Compare complete entries, including additions/removals and dev packages."""
    changes = []
    for section in ('packages', 'packages-dev'):
        original = {package['name']: package for package in before[section]}
        current = {package['name']: package for package in after[section]}
        for name in sorted(original.keys() | current.keys()):
            old, new = original.get(name), current.get(name)
            if old == new:
                continue
            if name not in allowed:
                raise ValueError('Candidate preparation changed an unrelated locked package: ' + name)
            changes.append((section, name, old, new))
    if not any(section == 'packages' and name == PROVIDER and new is not None
               for section, name, _, new in changes):
        raise ValueError('Candidate preparation did not replace the production Provider')
    return changes


def configure(preparer, manifest):
    """Adapt two pinned seams; leave identity, installation and archive proof intact."""
    allowed = candidate_packages(manifest)
    original_composer = preparer.composer

    def composer(root, *arguments):
        if arguments and arguments[0] == 'update':
            production_only = list(arguments) == [*UPDATE, '--no-dev']
            if list(arguments) != UPDATE and not production_only:
                raise ValueError('Unexpected candidate Composer update command')
            arguments = [*UPDATE, *sorted(allowed - {PROVIDER}), '--with-dependencies']
            if production_only:
                arguments.append('--no-dev')
            print('Candidate dependency update: ' + root.name, flush=True)
        original_composer(root, *arguments)

    def frozen(before, after):
        changes = changed_packages(before, after, allowed)
        for section, name, old, new in changes:
            print(f"  {section}: {name}: {old['version'] if old else '(absent)'}"
                  f" -> {new['version'] if new else '(absent)'}", flush=True)

    preparer.composer = composer
    preparer.frozen = frozen


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--preparer', type=Path, required=True)
    parser.add_argument('--provider-root', type=Path, required=True)
    parser.add_argument('--provider-sha', required=True)
    parser.add_argument('--host-root', type=Path, required=True)
    parser.add_argument('--host-sha', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    source = args.preparer.read_bytes()
    blob = hashlib.sha1(b'blob ' + str(len(source)).encode() + b'\0' + source).hexdigest()
    if blob != PREPARER_BLOB:
        parser.error('Example preparer changed; review the assembly adaptation before repinning')
    spec = importlib.util.spec_from_file_location('example_candidate_preparer', args.preparer)
    preparer = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(preparer)
    manifest = json.loads((args.provider_root / 'composer.json').read_text())
    configure(preparer, manifest)
    try:
        receipt = preparer.prepare(args.provider_root, args.provider_sha,
                                   args.host_root, args.host_sha, args.output.resolve())
    except (ValueError, OSError, KeyError, subprocess.CalledProcessError) as failure:
        parser.exit(1, 'candidate preparation: ' + str(failure) + '\n')
    print(json.dumps(receipt, indent=2))


if __name__ == '__main__':
    main()
