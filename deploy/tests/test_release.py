"""Run on Linux as root, ONLY in disposable /tmp fixtures. Never invokes a production host."""
import hashlib
import importlib.util
import io
import json
import multiprocessing
import os
from pathlib import Path
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('release', Path(__file__).parents[1] / 'server/ll_release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
UID = GID = 10001
SHA = 'a' * 40


class FakeHost:
    def __init__(self, root, fail=None):
        self.root, self.fail = root, fail
        self.resets = self.restarts = 0
        self.password_active = False

    def event(self, name):
        with (self.root / 'events').open('a') as f:
            f.write(name + '\n')

    def backup(self):
        self.event('backup')
        if self.fail == 'backup':
            raise release.Refused('backup failed')

    def set_password(self, password):
        self.password_active = True
        self.event('credential-set')
        if self.fail == 'set-password':
            raise release.Refused('set-password uncertain')

    def reset_password(self):
        self.resets += 1
        self.password_active = False
        self.event('credential-reset')

    def migrate(self, api, password):
        self.event('migrating')
        if self.fail == 'signal-migration':
            time.sleep(30)
        if self.fail == 'migration':
            raise release.Refused('migration failed')

    def caches(self, api):
        if self.fail == 'cache':
            raise release.Refused('cache failed')
        p = api / 'bootstrap/cache/config.php'
        p.write_text('fixture-cache')
        p.chmod(0o640)
        os.chown(p, UID, GID)

    def restart(self):
        self.restarts += 1
        self.event('restart')
        if self.fail == 'restart' and self.restarts == 1:
            raise release.Refused('restart failed')
        if self.fail == 'signal-activated' and self.restarts == 1:
            time.sleep(30)

    def readiness(self, path):
        if self.fail == 'recovery' or (self.fail in ('database', 'api', 'frontend', 'security', 'service') and path.name != '20261102-01'):
            raise release.Refused('readiness failed')
        self.event('ready')

    def warnings(self):
        self.event('warning-check')


class SecurityHarness(unittest.TestCase):
    def setUp(self):
        if sys.platform != 'linux' or os.geteuid() != 0:
            self.skipTest('requires root in a disposable Linux environment')
        self.temp = tempfile.TemporaryDirectory(prefix='ll-security-')
        os.chmod(self.temp.name, 0o755)
        self.root = Path(self.temp.name) / 'app'
        assert not str(self.root).startswith('/srv/linklibrary')
        self.root.mkdir(mode=0o755)
        for name in ('releases', 'tools', 'shared', 'incoming'):
            (self.root / name).mkdir(mode=0o755)
        for helper in ('php','ll-readiness.php'):
            (self.root/'tools'/helper).write_text('fixture helper')
        self.source = self.root / 'releases/20261102-01'
        self.source.mkdir(mode=0o755)
        self.root.joinpath('current').symlink_to(self.source)
        for rel, text in {'api/artisan': 'fixture', 'api/app/Model.php': 'immutable',
                          'api/docs/openapi.yaml': 'openapi: 3.1.0', 'web/package.json': '{}',
                          'web/.next/BUILD_ID': 'fixture', 'web/node_modules/next/bin.js': 'node'}.items():
            p = self.source / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(text)
            p.chmod(0o644)
        self.root.joinpath('shared/storage/app/public').mkdir(parents=True)
        self.root.joinpath('shared/api.env').write_text('PRIVATE_FIXTURE=not-production')
        self.root.joinpath('shared/web.env').write_text('FIXTURE=true')
        for name in ('api.env','web.env'):
            self.root.joinpath('shared',name).chmod(0o640)
            os.chown(self.root/'shared'/name,0,GID)
        self.trees = release.Trees(self.root, UID, GID)
        for rel, target in self.trees.links.items():
            p = self.source / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            p.symlink_to(target)
        for rel in release.CACHES:
            p = self.source / rel
            p.mkdir(parents=True)
            p.chmod(0o2770)
            os.chown(p, UID, GID)
        self.trees.seal(self.source, {'api': '1'*40, 'web': '2'*40})
        self.sentinel = Path(self.temp.name) / 'outside-sentinel'
        self.sentinel.write_text('MUST NOT CHANGE')

    def tearDown(self):
        if hasattr(self, 'temp'):
            self.assertEqual(self.sentinel.read_text(), 'MUST NOT CHANGE')
            self.temp.cleanup()

    def package(self, component='api', members=None):
        path = self.root / 'incoming' / f'{component}-{SHA}.tgz'
        if members is None:
            members = [('artisan', b'new-api', None), ('docs/openapi.yaml', b'openapi: 3.1.0', None)] if component == 'api' else [
                ('package.json', b'{}', None), ('.next/BUILD_ID', b'new-web', None)]
        with tarfile.open(path, 'w:gz') as t:
            for name, body, link in members:
                info = tarfile.TarInfo(name)
                info.mode = 0o644
                if link:
                    info.type, info.linkname = tarfile.SYMTYPE, link
                    t.addfile(info)
                else:
                    info.size = len(body)
                    t.addfile(info, io.BytesIO(body))
            metadata=json.dumps({'version':1,'component':component,'commit':SHA,'platform':'Linux','libc':['glibc','2.35']}).encode()
            info=tarfile.TarInfo('.ll-build.json');info.mode=0o644;info.size=len(metadata)
            t.addfile(info,io.BytesIO(metadata))
        return path, hashlib.sha256(path.read_bytes()).hexdigest()

    def deploy(self, fail=None, component='api', engine_type=release.Engine):
        package, digest = self.package(component)
        host = FakeHost(self.root, fail)
        engine = engine_type(self.root, host, UID, GID)
        return engine, host, lambda: engine.run('deploy', component=component, commit=SHA, digest=digest)

    def unchanged(self):
        self.assertEqual(self.root.joinpath('current').resolve(), self.source)

    def test_success_api_and_root_immutability(self):
        engine, host, run = self.deploy()
        run()
        self.assertTrue(engine.completed)
        state=json.loads(self.root.joinpath('.ll-transaction.json').read_text())
        self.assertTrue(state['completed']);self.assertFalse(state['recovery_required'])
        self.assertFalse(host.password_active)
        candidate = self.root.joinpath('current').resolve()
        self.trees.inventory(candidate)
        self.assertEqual(candidate.stat().st_uid, 0)
        self.assertFalse(candidate.joinpath('api/artisan').stat().st_mode & 0o022)
        self.assertEqual(candidate.joinpath('api/bootstrap/cache').stat().st_uid, UID)

    def test_success_web_preserves_api_and_skips_migration(self):
        engine, host, run = self.deploy(component='web')
        run()
        self.assertEqual(self.root.joinpath('current/api/artisan').read_text(), 'fixture')
        self.assertNotIn('migrating', self.root.joinpath('events').read_text())
        self.assertTrue(engine.completed)

    def test_writable_code_rejected_before_backup(self):
        self.source.joinpath('api/artisan').chmod(0o664)
        engine, host, run = self.deploy()
        with self.assertRaises(release.Refused): run()
        self.unchanged()
        self.assertFalse(self.root.joinpath('events').exists())

    def test_runtime_owned_release_rejected(self):
        os.chown(self.source, UID, GID)
        _, _, run = self.deploy()
        with self.assertRaises(release.Refused): run()
        self.unchanged()

    def test_group_writable_parent_rejected(self):
        self.source.joinpath('api').chmod(0o775)
        _, _, run = self.deploy()
        with self.assertRaises(release.Refused): run()

    def test_unexpected_owner_rejected(self):
        os.chown(self.source/'api/artisan', UID, GID)
        with self.assertRaises(release.Refused): self.trees.inventory(self.source)

    def test_missing_provenance_rejected(self):
        self.source.joinpath(release.MANIFEST).unlink()
        with self.assertRaises(FileNotFoundError): self.trees.inventory(self.source)

    def test_integrity_mismatch_rejected(self):
        self.source.joinpath('api/artisan').write_text('changed root code')
        with self.assertRaises(release.Refused): self.trees.inventory(self.source)

    def test_source_symlink_escape_rejected(self):
        self.source.joinpath('api/escape').symlink_to(self.sentinel)
        with self.assertRaises(release.Refused): self.trees.inventory(self.source)

    def test_source_symlink_chain_rejected(self):
        self.source.joinpath('api/one').symlink_to('artisan')
        self.source.joinpath('api/two').symlink_to('one')
        with self.assertRaises(release.Refused): self.trees.inventory(self.source)

    def test_source_fifo_rejected(self):
        os.mkfifo(self.source/'api/fifo')
        with self.assertRaises(release.Refused): self.trees.inventory(self.source)

    def test_source_hardlink_rejected(self):
        os.link(self.source/'api/artisan', self.source/'api/duplicate')
        with self.assertRaises(release.Refused): self.trees.inventory(self.source)

    def test_mount_point_rejected(self):
        with mock.patch.object(release, 'mount_points', return_value={self.source/'api'}):
            with self.assertRaises(release.Refused): self.trees.inventory(self.source)

    def test_runtime_cache_not_followed(self):
        self.source.joinpath('api/bootstrap/cache/evil').symlink_to(self.sentinel)
        self.trees.inventory(self.source)
        _, _, run = self.deploy()
        run()
        self.assertFalse(self.root.joinpath('current/api/bootstrap/cache/evil').exists())

    def test_malicious_archive_paths(self):
        for name in ('../outside', '/etc/evil', 'dir/../evil', 'a\\b', '.env'):
            with self.subTest(name=name):
                p, _ = self.package(members=[(name,b'x',None)])
                with self.assertRaises(release.Refused): release.archive_members(p)

    def test_duplicate_archive_member(self):
        p,_=self.package(members=[('one',b'x',None),('one',b'y',None)])
        with self.assertRaises(release.Refused): release.archive_members(p)

    def test_archive_symlink_escape_and_chain_and_reverse_order(self):
        fixtures = [[('link',b'',str(self.sentinel))],
                    [('real',b'x',None),('first',b'','real'),('second',b'','first')],
                    [('link/child',b'x',None),('link',b'','target'),('target',b'x',None)]]
        for members in fixtures:
            with self.subTest(members=members):
                p,_=self.package(members=members)
                with self.assertRaises(release.Refused): release.archive_members(p)

    def test_special_archive_member(self):
        p,_=self.package()
        with tarfile.open(p,'w:gz') as t:
            m=tarfile.TarInfo('fifo');m.type=tarfile.FIFOTYPE;t.addfile(m)
        with self.assertRaises(release.Refused): release.archive_members(p)

    def test_invalid_sha(self):
        self.package()
        engine=release.Engine(self.root,FakeHost(self.root),UID,GID)
        with self.assertRaises(release.Refused): engine.run('deploy',component='api',commit=SHA,digest='0'*64)
        self.unchanged()

    def test_incoming_symlink_rejected(self):
        p,digest=self.package();p.unlink();p.symlink_to(self.sentinel)
        engine=release.Engine(self.root,FakeHost(self.root),UID,GID)
        with self.assertRaises(OSError):engine.run('deploy',component='api',commit=SHA,digest=digest)
        self.unchanged()

    def test_artifact_replacement_after_stage_cannot_change_release(self):
        parent=self
        class ReplaceAfterStage(release.Engine):
            def stage(self,*args):
                result=super().stage(*args)
                replacement=parent.root/'incoming/replacement'
                replacement.write_bytes(b'changed after verified snapshot')
                os.replace(replacement,parent.root/'incoming'/f'api-{SHA}.tgz')
                return result
        _,_,run=self.deploy(engine_type=ReplaceAfterStage);run()
        self.assertEqual(self.root.joinpath('current/api/artisan').read_text(),'new-api')

    def test_artifact_replaced_at_open_fails_closed(self):
        _,_,run=self.deploy()
        real_open=os.open
        replaced=False
        def racing_open(path,flags,*args,**kwargs):
            nonlocal replaced
            fd=real_open(path,flags,*args,**kwargs)
            if path==f'api-{SHA}.tgz' and not replaced:
                replaced=True
                replacement=self.root/'incoming/replacement'
                replacement.write_bytes(b'raced artifact')
                os.replace(replacement,self.root/'incoming'/f'api-{SHA}.tgz')
            return fd
        with mock.patch.object(release.os,'open',side_effect=racing_open):
            with self.assertRaises(release.Refused):run()
        self.unchanged()

    def test_disk_headroom_failure_precedes_migration(self):
        _,_,run=self.deploy()
        with mock.patch.object(release.shutil,'disk_usage',return_value=type('Disk',(),{'free':1})()):
            with self.assertRaises(release.Refused):run()
        self.unchanged();self.assertFalse(self.root.joinpath('events').exists())

    def test_pre_activation_failures_and_credential_cleanup(self):
        for failure in ('backup','set-password','migration','cache'):
            with self.subTest(failure=failure):
                engine,host,run=self.deploy(failure)
                with self.assertRaises(release.Refused):run()
                self.unchanged();self.assertFalse(host.password_active)
                # Distinct candidates per run (real IDs intentionally collide within a second).
                if engine.candidate:
                    p=self.root/'releases'/engine.candidate
                    if p.exists():shutil_safe_remove(p)

    def test_post_activation_failures_recover(self):
        for failure in ('restart','database','api','frontend','security','service'):
            with self.subTest(failure=failure):
                engine,host,run=self.deploy(failure)
                with self.assertRaises(release.Refused):run()
                self.unchanged();self.assertTrue(engine.recovery_attempted);self.assertFalse(engine.completed)
                shutil_safe_remove(self.root/'releases'/engine.candidate)

    def test_failed_recovery_is_never_success(self):
        engine,host,run=self.deploy('recovery')
        with self.assertRaises(release.Refused):run()
        self.assertFalse(engine.completed)
        self.assertEqual(json.loads(self.root.joinpath('.ll-transaction.json').read_text())['phase'],'recovery-failed')

    def test_finalization_failure_recovers(self):
        class FailingFinalization(release.Engine):
            def retain(self):raise release.Refused('finalization failed')
        engine,_,run=self.deploy(engine_type=FailingFinalization)
        with self.assertRaises(release.Refused):run()
        self.unchanged();self.assertTrue(engine.recovery_attempted)

    def test_sigterm_migration_and_after_activation(self):
        for phase in ('signal-migration','signal-activated'):
            with self.subTest(phase=phase):
                self.root.joinpath('events').unlink(missing_ok=True)
                package,digest=self.package()
                def child():
                    try:release.Engine(self.root,FakeHost(self.root,phase),UID,GID).run('deploy',component='api',commit=SHA,digest=digest)
                    except BaseException:sys.exit(1)
                p=multiprocessing.get_context('fork').Process(target=child);p.start()
                marker='migrating' if phase=='signal-migration' else 'restart'
                deadline=time.time()+10
                while time.time()<deadline:
                    if self.root.joinpath('events').exists() and marker in self.root.joinpath('events').read_text():break
                    time.sleep(.02)
                else:p.kill();self.fail('signal test did not reach phase')
                os.kill(p.pid,signal.SIGTERM);p.join(10)
                if p.is_alive():p.kill();self.fail('signal recovery hung')
                self.assertNotEqual(p.exitcode,0);self.unchanged()
                self.assertIn('credential-reset',self.root.joinpath('events').read_text())
                state=json.loads(self.root.joinpath('.ll-transaction.json').read_text())
                shutil_safe_remove(self.root/'releases'/state['candidate'])

    def test_retention_does_not_follow_links_or_delete_original(self):
        for i in range(5):
            target=self.root/'releases'/f'2026100{i+1}-000000-api-aaaaaaa'
            target.mkdir();self.trees.clone(self.source,target,self.trees.inventory(self.source));self.trees.seal(target,{})
        self.root.joinpath('releases/not-a-release').symlink_to(self.sentinel)
        _,_,run=self.deploy();run()
        self.assertTrue(self.source.exists());self.assertTrue(self.sentinel.exists())
        self.assertTrue(self.root.joinpath('releases/not-a-release').is_symlink())

    def test_rollback_failure_restores_starting_release(self):
        engine,_,run=self.deploy();run();starting=self.root.joinpath('current').resolve()
        host=FakeHost(self.root,'restart')
        engine=release.Engine(self.root,host,UID,GID)
        with self.assertRaises(release.Refused):engine.run('rollback',target=self.source.name)
        self.assertEqual(self.root.joinpath('current').resolve(),starting)

    def test_invalid_arguments_create_no_transaction(self):
        engine=release.Engine(self.root,FakeHost(self.root),UID,GID)
        for kwargs in [dict(command='deploy',component='other',commit=SHA,digest='0'*64),dict(command='rollback',target='../escape')]:
            with self.assertRaises(release.Refused):engine.run(**kwargs)
        self.assertFalse(self.root.joinpath('.ll-transaction.json').exists())

    def test_unfinished_transaction_blocks_new_deployment(self):
        p=self.root/'.ll-transaction.json';p.write_text(json.dumps({'phase':'activated','migrator_credential_may_be_set':False}));p.chmod(0o600)
        _,_,run=self.deploy()
        with self.assertRaises(release.Refused):run()
        self.unchanged();self.assertEqual(json.loads(p.read_text())['phase'],'activated')

    def test_no_runtime_write_to_code(self):
        # Real UID drop verifies OS permissions, not a mocked permission predicate.
        p=os.fork()
        if p==0:
            os.setgroups([]);os.setgid(GID);os.setuid(UID)
            try:self.source.joinpath('api/artisan').write_text('attack')
            except PermissionError:os._exit(0)
            os._exit(1)
        _,status=os.waitpid(p,0);self.assertEqual(os.waitstatus_to_exitcode(status),0)

    def test_child_commands_use_secret_safe_umask(self):
        path=self.root/'private-cache-fixture'
        release.ProductionHost(self.root).command(['/usr/bin/python3','-c','from pathlib import Path; import sys; Path(sys.argv[1]).write_text("fixture")',str(path)])
        self.assertEqual(path.stat().st_mode&0o777,0o640)


    def test_manual_permission_correction_requires_trusted_bytes_and_preserves_runtime_state(self):
        spec=importlib.util.spec_from_file_location('repair',Path(__file__).parents[1]/'admin/repair_permissions.py')
        repair=importlib.util.module_from_spec(spec);spec.loader.exec_module(repair)
        reference=self.root/'shared/reference.json';reference.write_bytes((self.source/release.MANIFEST).read_bytes());reference.chmod(0o600)
        digest=hashlib.sha256(reference.read_bytes()).hexdigest()
        self.source.joinpath('api/artisan').chmod(0o664)
        self.source.joinpath('api/bootstrap').chmod(0o775)
        os.chown(self.source,UID,GID)
        cache=self.source/'api/bootstrap/cache';before=cache.stat()
        snapshot=self.root/'shared/before-correction.json'
        repair.correction(self.root,self.source.name,reference,digest,snapshot,UID,GID,apply=True)
        self.trees.inventory(self.source)
        self.assertEqual(self.source.stat().st_uid,0)
        self.assertEqual(self.source.joinpath('api/artisan').stat().st_mode&0o777,0o644)
        after=cache.stat();self.assertEqual((before.st_uid,before.st_mode),(after.st_uid,after.st_mode))
        repair.correction(self.root,self.source.name,snapshot,hashlib.sha256(snapshot.read_bytes()).hexdigest(),snapshot,UID,GID,apply=True,restore=True)
        self.assertEqual(self.source.stat().st_uid,UID)
        self.assertEqual(self.source.joinpath('api/artisan').stat().st_mode&0o777,0o664)

    def test_manual_permission_correction_refuses_content_mismatch_without_changes(self):
        spec=importlib.util.spec_from_file_location('repair',Path(__file__).parents[1]/'admin/repair_permissions.py')
        repair=importlib.util.module_from_spec(spec);spec.loader.exec_module(repair)
        reference=self.root/'shared/reference.json';reference.write_bytes((self.source/release.MANIFEST).read_bytes());reference.chmod(0o600)
        self.source.joinpath('api/artisan').write_text('untrusted changed code');self.source.joinpath('api/artisan').chmod(0o664)
        with self.assertRaises(repair.ll.Refused):
            repair.correction(self.root,self.source.name,reference,hashlib.sha256(reference.read_bytes()).hexdigest(),self.root/'shared/snapshot.json',UID,GID,apply=True)
        self.assertEqual(self.source.joinpath('api/artisan').stat().st_mode&0o777,0o664)
        self.assertFalse(self.root.joinpath('shared/snapshot.json').exists())


    def test_manual_recovery_requires_approved_journal_and_recovers_immutable_original(self):
        spec=importlib.util.spec_from_file_location('recover',Path(__file__).parents[1]/'admin/recover_release.py')
        recovery=importlib.util.module_from_spec(spec);spec.loader.exec_module(recovery)
        engine,_,run=self.deploy('recovery')
        with self.assertRaises(release.Refused):run()
        path=self.root/'.ll-transaction.json';digest=hashlib.sha256(path.read_bytes()).hexdigest()
        with self.assertRaises(recovery.ll.Refused):recovery.recover(self.root,self.source.name,'0'*64,FakeHost(self.root),UID,GID)
        recovery.recover(self.root,self.source.name,digest,FakeHost(self.root),UID,GID)
        self.unchanged();self.assertEqual(json.loads(path.read_text())['phase'],'recovered')


def shutil_safe_remove(path):
    # Test fixture cleanup only: prove containment before destructive test operations.
    assert str(path).startswith('/tmp/ll-security-')
    import shutil
    shutil.rmtree(path)



class MandatoryGateTests(unittest.TestCase):
    def host(self, failure=None):
        class Host(release.ProductionHost):
            def command(self,args,**kwargs):
                if args[0]=='/usr/bin/systemctl':
                    if failure==args[-1]:raise release.Refused('service gate')
                    return b''
                if 'll-readiness.php' in ' '.join(args):
                    return json.dumps({'ready':failure!='private-readiness'}).encode()
                if args[0]=='/usr/bin/curl':
                    path=args[-1].split(':8080')[-1]
                    if failure==path:raise release.Refused('HTTP gate')
                    if path.startswith('/api/'):
                        return json.dumps({'success':failure!='api-json'}).encode()
                    return ('<html lang="'+path[1:]+'">').encode()
                if failure=='warning':raise release.Refused('operational warning')
                return b''
        return Host(Path('/unused-test-root'))

    def test_mandatory_gates_positive(self):
        self.host().readiness(Path('/unused-test-release'))

    def test_each_mandatory_service_and_http_gate_negative(self):
        for gate in ['linklibrary-api','linklibrary-web','linklibrary-queue','linklibrary-scheduler.timer',
                     'private-readiness','/up','/api/v1/public/categories','api-json','/en','/ar']:
            with self.subTest(gate=gate):
                with self.assertRaises(release.Refused):self.host(gate).readiness(Path('/unused-test-release'))

    def test_operational_warning_is_nonblocking(self):
        self.host('warning').warnings()


class RestrictedCommandTests(unittest.TestCase):
    def parse(self,command):
        script=Path(__file__).parents[1]/'server/ll-ingest'
        return subprocess.run(['/bin/bash','-c','source "$1"; ll_parse "$2"','parse-test',str(script),command],capture_output=True).returncode

    def test_valid_restricted_commands(self):
        for command in ['status','upload api '+'a'*40+' '+'b'*64,'deploy web '+'a'*40+' '+'b'*64,
                        'rollback 20261102-01','rollback 20261103-120000-api-abcdef0']:
            with self.subTest(command=command.split()[0]):self.assertEqual(self.parse(command),0)

    def test_invalid_restricted_commands(self):
        for command in ['', 'id', 'bash', 'status extra','status\nid','status; id','rollback','rollback ../escape',
                        'deploy api '+'a'*40+' '+'b'*64+' extra','upload api bad sha','deploy other '+'a'*40+' '+'b'*64]:
            with self.subTest(command=command.split()[0] if command else ''):self.assertNotEqual(self.parse(command),0)

    def test_sudoers_syntax_and_helper_compatibility(self):
        sudoers=Path(__file__).parents[1]/'server/sudoers-linklibrary-deploy'
        import shutil
        visudo=shutil.which('visudo')
        if not visudo:self.skipTest('visudo unavailable')
        subprocess.run([visudo,'-cf',str(sudoers)],check=True,capture_output=True)
        text=sudoers.read_text()
        self.assertNotIn('NOPASSWD: ALL',text)
        self.assertIn('/srv/linklibrary/tools/ll-release deploy api *',text)
        self.assertIn('/srv/linklibrary/tools/ll-release deploy web *',text)
        self.assertIn('/srv/linklibrary/tools/ll-release rollback *',text)

    def test_ssh_cleanup_on_failed_connection(self):
        with tempfile.TemporaryDirectory(prefix='ll-ssh-test-') as d:
            root=Path(d);bin=root/'bin';bin.mkdir();ssh=bin/'ssh';ssh.write_text('#!/bin/sh\nexit 7\n');ssh.chmod(0o755)
            env=dict(os.environ,PATH=str(bin)+':/usr/bin:/bin',RUNNER_TEMP=str(root),KEY='fixture-not-a-private-key',KNOWN='fixture-host-key',HOST='example.invalid',PORT='22',SSH_USER='llci',ACTION='rollback',RID='20261102-01')
            script=Path(__file__).parents[1]/'ssh-operation.sh'
            result=subprocess.run(['/bin/bash',str(script)],env=env,capture_output=True)
            self.assertNotEqual(result.returncode,0)
            self.assertFalse((root/'ll-ssh-dir').exists())
            self.assertEqual(list(root.glob('ll-ssh.*')),[])

if __name__=='__main__':unittest.main(verbosity=2)
