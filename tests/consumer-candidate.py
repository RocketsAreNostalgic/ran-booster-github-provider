#!/usr/bin/env python3
"""Exercise the assembly adapter and its lock boundary with real Composer resolution."""
import copy
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from contextlib import redirect_stdout

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parent.parent
SPEC = importlib.util.spec_from_file_location('consumer_candidate', ROOT / 'scripts/prepare-consumer-candidate.py')
ADAPTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ADAPTER)
PROVIDER = ADAPTER.PROVIDER
SUPPORT = 'ran/updater-support'
UPDATER = 'ran/wp-release-updater'
MANIFEST = {'name': PROVIDER, 'require': {'php': '^8.2', SUPPORT: '^1.0.0-beta.9', UPDATER: '^1.0.0-beta.21'}}


def package(name, version, require=None):
    return {'name': name, 'version': version, 'type': 'metapackage', 'require': require or {}}


class ConsumerCandidateTest(unittest.TestCase):
    def test_changed_preparer_is_rejected_before_execution(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            preparer = root / 'preparer.py'
            preparer.write_text("raise RuntimeError('must not execute')")
            result = subprocess.run([sys.executable, ROOT / 'scripts/prepare-consumer-candidate.py',
                                     '--preparer', preparer, '--provider-root', root,
                                     '--provider-sha', 'a' * 40, '--host-root', root,
                                     '--host-sha', 'b' * 40, '--output', root / 'output'],
                                    text=True, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn('Example preparer changed', result.stderr)
            self.assertNotIn('RuntimeError', result.stderr)
            self.assertFalse((root / '__pycache__').exists())

    def test_only_direct_runtime_packages_are_allowed(self):
        manifest = {**MANIFEST, 'require-dev': {'test/development': '*'}}
        self.assertEqual({PROVIDER, SUPPORT, UPDATER}, ADAPTER.candidate_packages(manifest))

    def test_full_lock_entries_and_both_sections_are_checked(self):
        before = {'packages': [package(PROVIDER, '1'), package(SUPPORT, '1')],
                  'packages-dev': [package('test/development', '1')]}
        after = copy.deepcopy(before)
        after['packages'][0]['version'] = 'dev-candidate'
        self.assertEqual(1, len(ADAPTER.changed_packages(before, after, {PROVIDER, SUPPORT})))
        after['packages'][1]['version'] = '2'
        self.assertEqual(2, len(ADAPTER.changed_packages(before, after, {PROVIDER, SUPPORT})))
        for section, mutation in [
            ('packages-dev', lambda entries: entries[0].update(source={'reference': 'changed'})),
            ('packages-dev', lambda entries: entries.clear()),
            ('packages', lambda entries: entries.append(package('test/unrelated', '1'))),
        ]:
            invalid = copy.deepcopy(after)
            mutation(invalid[section])
            with self.assertRaisesRegex(ValueError, 'unrelated locked package'):
                ADAPTER.changed_packages(before, invalid, {PROVIDER, SUPPORT})
        with self.assertRaisesRegex(ValueError, 'did not replace'):
            ADAPTER.changed_packages(before, before, {PROVIDER, SUPPORT})

    def test_scoped_flags_and_unchanged_install(self):
        calls = []
        preparer = SimpleNamespace(composer=lambda root, *args: calls.append((root, list(args))))
        ADAPTER.configure(preparer, MANIFEST)
        root = Path('consumer')
        preparer.composer(root, 'install', '--no-dev')
        self.assertEqual(['install', '--no-dev'], calls[-1][1])
        for suffix in ([], ['--no-dev']):
            with redirect_stdout(io.StringIO()):
                preparer.composer(root, *ADAPTER.UPDATE, *suffix)
            self.assertEqual([*ADAPTER.UPDATE, SUPPORT, UPDATER, '--with-dependencies', *suffix], calls[-1][1])
            self.assertNotIn('--with-all-dependencies', calls[-1][1])
        with self.assertRaisesRegex(ValueError, 'Unexpected candidate Composer'):
            preparer.composer(root, 'update', '*')

    def test_real_composer_partial_update_then_bounded_dependency_update(self):
        # Metapackages exercise the real solver without network, clones or installation hooks.
        packages = [package(PROVIDER, '1.0.0-beta.20', {SUPPORT: '^1.0.0-beta.4', UPDATER: '^1.0.0-beta.13'}),
                    package(PROVIDER, 'dev-candidate', MANIFEST['require']),
                    package(SUPPORT, '1.0.0-beta.4'), package(SUPPORT, '1.0.0-beta.9'),
                    package(UPDATER, '1.0.0-beta.13'), package(UPDATER, '1.0.0-beta.21'),
                    package('test/unrelated', '1.0.0'), package('test/unrelated', '1.1.0')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            document = {'name': 'test/consumer', 'repositories': [{'type': 'package', 'package': packages}, {'packagist.org': False}],
                        'require': {PROVIDER: '1.0.0-beta.20', SUPPORT: '1.0.0-beta.4', UPDATER: '1.0.0-beta.13', 'test/unrelated': '1.0.0'}}
            (root / 'composer.json').write_text(json.dumps(document))
            def composer(root, *arguments):
                result = subprocess.run(['composer', *arguments], cwd=root, text=True, capture_output=True)
                if result.returncode:
                    raise RuntimeError(result.stderr)
            composer(root, 'update', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress')
            before = json.loads((root / 'composer.lock').read_text())
            document['require'].update({PROVIDER: 'dev-candidate', SUPPORT: '~1.0.0-beta.4', UPDATER: '~1.0.0-beta.13', 'test/unrelated': '^1.0'})
            (root / 'composer.json').write_text(json.dumps(document))
            for flags in ([], ['--with-dependencies']):
                with self.assertRaisesRegex(RuntimeError, 'fixed to.*partial update'):
                    composer(root, *ADAPTER.UPDATE, *flags)
            preparer = SimpleNamespace(composer=composer)
            ADAPTER.configure(preparer, MANIFEST)
            with redirect_stdout(io.StringIO()) as output:
                preparer.composer(root, *ADAPTER.UPDATE)
                after = json.loads((root / 'composer.lock').read_text())
                preparer.frozen(before, after)
            versions = {item['name']: item['version'] for item in after['packages']}
            self.assertEqual('1.0.0-beta.9', versions[SUPPORT])
            self.assertEqual('1.0.0-beta.21', versions[UPDATER])
            self.assertEqual('1.0.0', versions['test/unrelated'])
            self.assertIn('1.0.0-beta.4 -> 1.0.0-beta.9', output.getvalue())
            self.assertNotIn('test/unrelated', output.getvalue())
            self.assertEqual({PROVIDER, SUPPORT, UPDATER}, {change[1] for change in ADAPTER.changed_packages(before, after, ADAPTER.candidate_packages(MANIFEST))})


if __name__ == '__main__':
    unittest.main()
