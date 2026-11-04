"""Root Linux fixtures only; never talks to a production host."""
import hashlib
import importlib.util
import io
import json
import multiprocessing
import os
from pathlib import Path
import signal
import sys
import tarfile
import time
import unittest
from unittest import mock

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

base = Path(__file__).parents[1]
h = load('harness', base / 'tests/test_release.py')
b = load('bootstrap', base / 'admin/bootstrap_release.py')

class Host(h.FakeHost):
    def app(self, api, *args, **kwargs):
        return json.dumps({'ready': True, 'schema': 'a' * 64}).encode()

    def readiness(self, release):
        if self.fail == 'recovery' or (self.fail in ('database', 'api', 'frontend', 'service') and 'web-' in release.name):
            raise b.ll.Refused('readiness failed')
        self.event('ready')

    def check(self, release):
        self.event('private-check')
        if self.fail == 'schema':
            return ('b' if 'web-' in release.name else 'a') * 64
        return 'a' * 64

class BootstrapTests(unittest.TestCase):
    def setUp(self):
        self.fixture = h.SecurityHarness()
        self.fixture.setUp()
        self.root, self.legacy = self.fixture.root, self.fixture.source
        for name in ('health.sh', 'll-bootstrap-check.php'):
            (self.root / 'tools' / name).write_text('fixture')
        (self.legacy / '.ll-manifest.json').unlink()
        os.chown(self.legacy, h.UID, h.GID)
        self.packages = self.root / 'packages'
        self.packages.mkdir(mode=0o700)
        self.plan = {'version': 1, 'origin': b.ORIGIN, 'legacy': self.legacy.name}
        for role, rid in [('fallback', '20261104-010001-api-8f43fe9'), ('candidate', '20261104-010002-web-71994b4')]:
            self.plan[role] = {'release': rid}
            for component, sha in [('api', b.API), ('web', b.WEB)]:
                path = self.packages / (role + '-' + component + '.tgz')
                files = {'artisan': role.encode(), 'bootstrap/app.php': b'fixture', 'vendor/autoload.php': b'fixture'} if component == 'api' else {
                    'package.json': b'{}', '.next/BUILD_ID': role.encode(), 'node_modules/next/dist/bin/next': b'fixture'}
                files['.ll-build.json'] = json.dumps({'commit': sha, 'component': component, 'platform': 'Linux',
                    'libc': ['glibc', '2.35'], 'public_origin': b.ORIGIN}).encode()
                with tarfile.open(path, 'w:gz') as archive:
                    for name, data in files.items():
                        info = tarfile.TarInfo(name)
                        info.mode, info.size = 0o644, len(data)
                        archive.addfile(info, io.BytesIO(data))
                digest = hashlib.sha256(path.read_bytes()).hexdigest()
                record = json.dumps({'commit': sha, 'component': component, 'artifact_sha256': digest,
                                     'verified': True, 'build': role}).encode()
                Path(str(path) + '.build.json').write_bytes(record)
                self.plan[role][component] = {'file': path.name, 'commit': sha, 'sha256': digest,
                                             'build_record_sha256': hashlib.sha256(record).hexdigest()}
        self.plan_path = self.packages / 'plan.json'
        self.host = Host(self.root)
        self.bootstrap = b.Bootstrap(self.root, self.host, h.UID, h.GID)

    def tearDown(self):
        self.assertFalse((self.legacy / '.ll-manifest.json').exists())
        self.assertEqual(self.legacy.stat().st_uid, h.UID)
        self.fixture.tearDown()

    def prepare(self):
        self.plan_path.write_text(json.dumps(self.plan))
        digest = hashlib.sha256(self.plan_path.read_bytes()).hexdigest()
        self.bootstrap.prepare(self.plan_path, digest)
        return hashlib.sha256((self.root / b.LEDGER).read_bytes()).hexdigest()

    def test_pair_success_and_one_time(self):
        digest = self.prepare()
        self.assertEqual((self.root / 'current').resolve(), self.legacy)
        with self.assertRaises(b.ll.Refused):
            self.bootstrap.prepare(self.plan_path, hashlib.sha256(self.plan_path.read_bytes()).hexdigest())
        self.bootstrap.activate(digest)
        self.assertEqual((self.root / 'current').resolve().name, self.plan['candidate']['release'])
        with self.assertRaises(b.ll.Refused):
            self.bootstrap.activate(digest)
        events = (self.root / 'events').read_text()
        self.assertNotIn('migrating', events)
        self.assertNotIn('credential-set', events)

    def test_failed_restart_and_health_recover_to_trusted_fallback(self):
        for fail in ('restart', 'database', 'api', 'frontend', 'service'):
            with self.subTest(fail=fail):
                # Separate fixtures for each complete one-time bootstrap.
                case = BootstrapTests()
                case.setUp()
                try:
                    digest = case.prepare()
                    case.host.fail = fail
                    with self.assertRaises((b.ll.Refused, h.release.Refused)):
                        case.bootstrap.activate(digest)
                    self.assertEqual((case.root / 'current').resolve().name, case.plan['fallback']['release'])
                    self.assertEqual(json.loads((case.root / b.LEDGER).read_text())['phase'], 'recovered')
                finally:
                    case.tearDown()

    def test_failed_recovery_never_reports_success(self):
        digest = self.prepare()
        self.host.fail = 'recovery'
        with self.assertRaisesRegex(b.ll.Refused, 'recovery failed'):
            self.bootstrap.activate(digest)
        self.assertEqual(json.loads((self.root / b.LEDGER).read_text())['phase'], 'recovery-failed')

    def test_schema_change_stops_before_switch(self):
        digest = self.prepare()
        with mock.patch.object(self.host, 'check', return_value='b' * 64):
            with self.assertRaisesRegex(b.ll.Refused, 'schema changed'):
                self.bootstrap.activate(digest)
        self.assertEqual((self.root / 'current').resolve(), self.legacy)

    def test_bad_digest_keeps_legacy_and_shared_data(self):
        self.plan['candidate']['api']['sha256'] = '0' * 64
        with self.assertRaises(b.ll.Refused):
            self.prepare()
        self.assertEqual((self.root / 'current').resolve(), self.legacy)
        self.assertEqual((self.root / 'shared/api.env').read_text(), 'PRIVATE_FIXTURE=not-production')

    def test_duplicate_build_and_wrong_origin_rejected(self):
        self.plan['candidate']['api'] = dict(self.plan['fallback']['api'])
        with self.assertRaisesRegex(b.ll.Refused, 'duplicate'):
            self.prepare()

    def test_wrong_compiled_public_origin_rejected(self):
        item = self.plan['candidate']['web']
        path = self.packages / item['file']
        with tarfile.open(path) as archive:
            files = {m.name: archive.extractfile(m).read() for m in archive}
        metadata = json.loads(files['.ll-build.json'])
        metadata['public_origin'] = 'https://linklibrary.invalid'
        files['.ll-build.json'] = json.dumps(metadata).encode()
        with tarfile.open(path, 'w:gz') as archive:
            for name, data in files.items():
                member = tarfile.TarInfo(name)
                member.mode, member.size = 0o644, len(data)
                archive.addfile(member, io.BytesIO(data))
        item['sha256'] = hashlib.sha256(path.read_bytes()).hexdigest()
        record_path = Path(str(path) + '.build.json')
        record = json.loads(record_path.read_text())
        record['artifact_sha256'] = item['sha256']
        record_path.write_text(json.dumps(record))
        item['build_record_sha256'] = hashlib.sha256(record_path.read_bytes()).hexdigest()
        with self.assertRaisesRegex(b.ll.Refused, 'wrong public origin'):
            self.prepare()
        self.assertEqual((self.root / 'current').resolve(), self.legacy)

    def test_backup_failure_never_switches(self):
        digest = self.prepare()
        self.host.fail = 'backup'
        with self.assertRaises((b.ll.Refused, h.release.Refused)):
            self.bootstrap.activate(digest)
        self.assertEqual((self.root / 'current').resolve(), self.legacy)

    def test_changed_ledger_cannot_activate(self):
        digest = self.prepare()
        with (self.root / b.LEDGER).open('a') as out:
            out.write(' ')
        with self.assertRaisesRegex(b.ll.Refused, 'ledger changed'):
            self.bootstrap.activate(digest)
        self.assertEqual((self.root / 'current').resolve(), self.legacy)

    def test_changed_current_blocks_activation(self):
        digest = self.prepare()
        (self.root / 'current').unlink()
        (self.root / 'current').symlink_to(self.root / 'releases' / self.plan['fallback']['release'])
        with self.assertRaisesRegex(b.ll.Refused, 'current changed'):
            self.bootstrap.activate(digest)

    def test_runtime_write_to_prepared_code_rejected(self):
        digest = self.prepare()
        (self.root / 'releases' / self.plan['fallback']['release'] / 'api/artisan').chmod(0o664)
        with self.assertRaises(b.ll.Refused):
            self.bootstrap.activate(digest)
        self.assertEqual((self.root / 'current').resolve(), self.legacy)

    def test_shared_environment_permissions_fail_closed(self):
        (self.root / 'shared/api.env').chmod(0o644)
        with self.assertRaisesRegex(b.ll.Refused, 'unsafe shared environment'):
            self.prepare()
        self.assertFalse((self.root / b.LEDGER).exists())

    def test_signal_in_symlink_replacement_window_recovers(self):
        digest = self.prepare()
        original = self.bootstrap.engine.switch
        calls = []
        def interrupted(name):
            original(name)
            calls.append(name)
            if len(calls) == 1:
                raise KeyboardInterrupt()
        with mock.patch.object(self.bootstrap.engine, 'switch', side_effect=interrupted):
            with self.assertRaises(KeyboardInterrupt):
                self.bootstrap.activate(digest)
        self.assertEqual((self.root / 'current').resolve().name, self.plan['fallback']['release'])

    def test_failed_commit_journal_recovers(self):
        digest = self.prepare()
        original = self.bootstrap.engine.journal
        def fail(phase):
            if phase == 'committed':
                raise OSError('fixture journal write failure')
            original(phase)
        with mock.patch.object(self.bootstrap.engine, 'journal', side_effect=fail):
            with self.assertRaises(OSError):
                self.bootstrap.activate(digest)
        self.assertEqual((self.root / 'current').resolve().name, self.plan['fallback']['release'])

    def test_existing_target_never_overwritten(self):
        target = self.root / 'releases' / self.plan['fallback']['release']
        target.mkdir()
        (target / 'sentinel').write_text('preserve')
        with self.assertRaisesRegex(b.ll.Refused, 'release exists'):
            self.prepare()
        self.assertEqual((target / 'sentinel').read_text(), 'preserve')

    def test_initial_schema_mismatch_blocks_preparation(self):
        self.host.fail = 'schema'
        with self.assertRaisesRegex(b.ll.Refused, 'schema mismatch'):
            self.prepare()
        self.assertEqual((self.root / 'current').resolve(), self.legacy)

    def test_unfinished_normal_transaction_blocks_bootstrap(self):
        (self.root / '.ll-transaction.json').write_text('{}')
        with self.assertRaisesRegex(b.ll.Refused, 'existing transaction'):
            self.prepare()

    def test_manual_recovery_after_sigkill(self):
        digest = self.prepare()
        self.host.fail = 'signal-activated'
        process = multiprocessing.get_context('fork').Process(target=self.bootstrap.activate, args=(digest,))
        process.start()
        deadline = time.time() + 10
        while time.time() < deadline:
            if (self.root / 'events').exists() and 'restart' in (self.root / 'events').read_text():
                break
            time.sleep(.02)
        else:
            process.kill()
            self.fail('activation not reached')
        process.kill()
        process.join(10)
        recovery = load('bootstrap_recovery', base / 'admin/recover_release.py')
        journal = hashlib.sha256((self.root / '.ll-transaction.json').read_bytes()).hexdigest()
        incompatible = Host(self.root)
        with mock.patch.object(incompatible, 'app', return_value=json.dumps({'ready': True, 'schema': 'b' * 64}).encode()):
            with self.assertRaisesRegex(RuntimeError, 'recovery schema changed'):
                recovery.recover(self.root, self.plan['fallback']['release'], journal, incompatible, h.UID, h.GID)
        self.assertEqual((self.root / 'current').resolve().name, self.plan['candidate']['release'])
        host = Host(self.root)
        recovery.recover(self.root, self.plan['fallback']['release'], journal, host, h.UID, h.GID)
        self.assertEqual(host.resets, 0)
        self.assertEqual((self.root / 'current').resolve().name, self.plan['fallback']['release'])

    def test_signal_after_switch_recovers(self):
        digest = self.prepare()
        self.host.fail = 'signal-activated'
        def child():
            try:
                self.bootstrap.activate(digest)
            except BaseException:
                sys.exit(1)
        process = multiprocessing.get_context('fork').Process(target=child)
        process.start()
        deadline = time.time() + 10
        while time.time() < deadline:
            if (self.root / 'events').exists() and 'restart' in (self.root / 'events').read_text():
                break
            time.sleep(.02)
        else:
            process.kill()
            self.fail('did not reach activation')
        os.kill(process.pid, signal.SIGTERM)
        process.join(10)
        if process.is_alive():
            process.kill()
            self.fail('recovery hung')
        self.assertNotEqual(process.exitcode, 0)
        self.assertEqual((self.root / 'current').resolve().name, self.plan['fallback']['release'])

if __name__ == '__main__':
    unittest.main(verbosity=2)
