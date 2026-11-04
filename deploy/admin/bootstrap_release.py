#!/usr/bin/python3
"""One-time root-only paired bootstrap. Never executed through llci/sudoers."""
import argparse
import contextlib
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import secrets
import signal
import shutil
import socket
import subprocess
import sys
import tarfile
import time

sys.dont_write_bytecode = True
module = Path(__file__).with_name('ll_release.py')
if not module.exists():
    module = Path(__file__).parents[1] / 'server/ll_release.py'
spec = importlib.util.spec_from_file_location('ll_release', module)
ll = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ll)
API = '8f43fe9cc6ef1a2327400ef4726aea7e59254bfb'
WEB = '71994b4f55a0fcc8c3fa92bd083a8eedb33058b3'
ORIGIN = 'https://31.172.87.248:8080'
LEDGER = '.ll-bootstrap.json'


def load_plan(path, digest):
    path = Path(path)
    parent = ll.secure_dir(path.parent)
    try:
        raw, _ = ll.read_file(parent, path.name)
    finally:
        os.close(parent)
    ll.require(ll.DIGEST.fullmatch(digest) and hashlib.sha256(raw).hexdigest() == digest, 'plan digest mismatch')
    plan = json.loads(raw)
    ll.require(plan.get('version') == 1 and plan.get('origin') == ORIGIN, 'invalid bootstrap plan')
    ll.require(plan.get('legacy') == '20261102-01', 'unexpected legacy release')
    builds = set()
    for role in ('fallback', 'candidate'):
        pair = plan[role]
        ll.require(ll.RELEASE.fullmatch(pair['release']) is not None and pair['release'] != plan['legacy'], 'invalid release ID')
        for component, commit in (('api', API), ('web', WEB)):
            item = pair[component]
            ll.require(item['commit'] == commit and ll.DIGEST.fullmatch(item['sha256']), 'unapproved artifact')
            ll.require(Path(item['file']).name == item['file'] and item['file'] not in ('.', '..'), 'artifact must be beside plan')
            ll.require(ll.DIGEST.fullmatch(item['build_record_sha256']) is not None, 'build provenance required')
        builds.add(pair['api']['build_record_sha256'] + pair['web']['build_record_sha256'])
    ll.require(plan['fallback']['release'] != plan['candidate']['release'] and len(builds) == 2,
               'fallback must have independent build evidence')
    for component in ('api', 'web'):
        ll.require(plan['fallback'][component]['sha256'] != plan['candidate'][component]['sha256'] and
                   plan['fallback'][component]['build_record_sha256'] != plan['candidate'][component]['build_record_sha256'],
                   'do not duplicate the candidate as fallback')
    return plan


class Host(ll.ProductionHost):
    def check(self, release):
        ll.require(self.command(['/usr/bin/node', '--version']).startswith(b'v22.'), 'Node 22 required')
        result = json.loads(self.app(None, str(self.root / 'tools/ll-readiness.php'), str(release / 'api')))
        ll.require(result.get('ready') is True, 'private security/database preflight failed')
        result = json.loads(self.app(None, str(self.root / 'tools/ll-bootstrap-check.php'), str(release / 'api')))
        ll.require(result.get('ready') is True and ll.DIGEST.fullmatch(result.get('schema', '')),
                   'pending migrations or incompatible database schema')
        self.preview(release)
        return result['schema']

    def preview(self, release):
        # Sequential preview: no global config, no systemd unit, no environment file edits.
        with socket.socket() as sock:
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            sock.bind(('127.0.0.1', 3197))
        self.api_preview(release)
        command = ['/usr/sbin/runuser', '-u', 'linklibrary', '--', '/usr/bin/node',
                   str(release / 'web/node_modules/next/dist/bin/next'), 'start',
                   '--hostname', '127.0.0.1', '--port', '3197']
        with subprocess.Popen(command, cwd=release / 'web', stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL, start_new_session=True, umask=0o027) as process:
            try:
                for _ in range(30):
                    ll.require(process.poll() is None, 'bootstrap preview exited')
                    try:
                        for locale in ('en', 'ar'):
                            body = self.command(['/usr/bin/curl', '--fail', '--silent', '--max-time', '10',
                                                 f'http://127.0.0.1:3197/{locale}'])
                            ll.require(('lang="' + locale + '"').encode() in body, 'wrong preview locale')
                        return
                    except ll.Refused:
                        time.sleep(1)
                raise ll.Refused('bootstrap preview unhealthy')
            finally:
                with contextlib.suppress(ProcessLookupError):
                    os.killpg(process.pid, signal.SIGTERM)
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    process.wait()

    def api_preview(self, release):
        with socket.socket() as sock:
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            sock.bind(('127.0.0.1', 3196))
        api = release / 'api'
        command = ['/usr/sbin/runuser', '-u', 'linklibrary', '--', str(self.root / 'tools/php'),
                   '-S', '127.0.0.1:3196', '-t', str(api / 'public'),
                   str(api / 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')]
        with subprocess.Popen(command, cwd=api / 'public', stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL, start_new_session=True, umask=0o027) as process:
            try:
                for _ in range(30):
                    ll.require(process.poll() is None, 'API preview exited')
                    try:
                        self.command(['/usr/bin/curl', '--fail', '--silent', '--max-time', '10', 'http://127.0.0.1:3196/up'])
                        raw = self.command(['/usr/bin/curl', '--fail', '--silent', '--max-time', '10',
                                            'http://127.0.0.1:3196/api/v1/public/categories'])
                        ll.require(json.loads(raw).get('success') is True, 'API preview unhealthy')
                        return
                    except ll.Refused:
                        time.sleep(1)
                raise ll.Refused('API preview timeout')
            finally:
                with contextlib.suppress(ProcessLookupError):
                    os.killpg(process.pid, signal.SIGTERM)
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    process.wait()


class Bootstrap:
    def __init__(self, root, host, uid, gid):
        self.root, self.host = Path(root), host
        self.engine = ll.Engine(root, host, uid, gid)
        self.trees = self.engine.trees

    @contextlib.contextmanager
    def locked(self):
        root_fd = ll.secure_dir(self.root)
        release_fd = ll.secure_dir(self.root / 'releases')
        lock = os.open('.ll-release.lock', os.O_RDWR | os.O_CREAT | ll.NOFOLLOW, 0o600, dir_fd=root_fd)
        try:
            meta = os.fstat(lock)
            ll.require(__import__('stat').S_ISREG(meta.st_mode) and meta.st_uid == 0 and meta.st_nlink == 1 and not meta.st_mode & 0o022, 'untrusted lock')
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            for directory, names in ((self.root / 'tools', ('php', 'health.sh', 'll-readiness.php', 'll-bootstrap-check.php')),
                                     (self.root / 'shared', ('api.env', 'web.env'))):
                fd = ll.secure_dir(directory)
                try:
                    for name in names:
                        _, meta = ll.read_file(fd, name)
                        if directory.name == 'shared':
                            ll.require(meta.st_gid == self.trees.gid and not meta.st_mode & 0o007, 'unsafe shared environment')
                finally:
                    os.close(fd)
            self.engine.root_fd, self.engine.rel_fd = root_fd, release_fd
            yield root_fd
        finally:
            os.close(lock)
            os.close(release_fd)
            os.close(root_fd)

    def write(self, state):
        ll.atomic_json(self.engine.root_fd, LEDGER, state)

    def prepare(self, plan_path, digest):
        plan = load_plan(plan_path, digest)
        with self.locked() as root_fd:
            ll.require(not os.path.lexists(self.root / LEDGER), 'bootstrap already prepared; preserve evidence')
            ll.require(not os.path.lexists(self.root / '.ll-transaction.json'), 'existing transaction requires review')
            ll.require(self.engine.current() == plan['legacy'], 'current changed')
            state = {'version': 1, 'plan_sha256': digest, 'phase': 'preparing', 'plan': plan}
            self.write(state)
            # Never delete published candidates on failure; no retention or legacy tree traversal.
            for role in ('fallback', 'candidate'):
                pair = plan[role]
                stage = self.root / ('.bootstrap-' + secrets.token_hex(12))
                stage.mkdir(mode=0o700)
                release = stage / 'release'
                release.mkdir(mode=0o755)
                for component in ('api', 'web'):
                    item = pair[component]
                    parent = ll.secure_dir(Path(plan_path).parent)
                    try:
                        raw, _ = ll.read_file(parent, item['file'], limit=ll.LIMIT)
                        record, _ = ll.read_file(parent, item['file'] + '.build.json')
                    finally:
                        os.close(parent)
                    ll.require(hashlib.sha256(record).hexdigest() == item['build_record_sha256'], 'build evidence mismatch')
                    evidence = json.loads(record)
                    ll.require(evidence.get('commit') == item['commit'] and evidence.get('artifact_sha256') == item['sha256']
                               and evidence.get('component') == component and evidence.get('verified') is True,
                               'build evidence does not approve artifact')
                    ll.require(hashlib.sha256(raw).hexdigest() == item['sha256'], 'artifact digest mismatch')
                    package = stage / (component + '.tgz')
                    package.write_bytes(raw)
                    del raw
                    members, expanded = ll.archive_members(package)
                    ll.require(shutil.disk_usage(self.root).free > expanded + 4 * 1024**3, 'insufficient headroom')
                    required = ('artisan', 'bootstrap/app.php', 'vendor/autoload.php') if component == 'api' else (
                        'package.json', 'node_modules/next/dist/bin/next', '.next/BUILD_ID')
                    ll.require(all(name in members and members[name].isfile() for name in required), 'missing runtime files')
                    with tarfile.open(package) as archive:
                        metadata = members.get('.ll-build.json')
                        ll.require(metadata and metadata.isfile() and metadata.size <= 16384, 'invalid build metadata')
                        build = json.load(archive.extractfile(metadata))
                    ll.require(build.get('commit') == item['commit'] and build.get('component') == component and
                               build.get('platform') == 'Linux' and build.get('libc', [None])[0] == 'glibc' and
                               tuple(map(int, build['libc'][1].split('.'))) <= (2, 35), 'incompatible build')
                    if component == 'web':
                        ll.require(build.get('public_origin') == ORIGIN, 'wrong public origin')
                    ll.extract(package, release / component)
                for rel, target in self.trees.links.items():
                    path = release / rel
                    ll.require(not path.exists() and not path.is_symlink(), 'artifact supplies shared state')
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.symlink_to(target)
                (release / 'api/resources/views').mkdir(parents=True, exist_ok=True)
                for cache in ll.CACHES:
                    (release / cache).parent.mkdir(parents=True, exist_ok=True)
                self.trees.seal(release, {'api': API, 'web': WEB})
                target = self.root / 'releases' / pair['release']
                ll.require(not os.path.lexists(target), 'release exists')
                os.rename(release, target)
                self.engine.runtime_dirs(target)
                self.host.caches(target / 'api')
                self.trees.inventory(target)
                schema = self.host.check(target)
                if role == 'fallback':
                    state['schema'] = schema
                ll.require(schema == state['schema'], 'pair database schema mismatch')
                ll.remove_tree(root_fd, stage.name, os.fstat(root_fd).st_dev)
            state['phase'] = 'prepared'
            self.write(state)
            print('BOOTSTRAP PREPARED; current and database schema unchanged')

    def activate(self, ledger_sha):
        with self.locked() as root_fd:
            raw, _ = ll.read_file(root_fd, LEDGER)
            ll.require(hashlib.sha256(raw).hexdigest() == ledger_sha, 'bootstrap ledger changed')
            state = json.loads(raw)
            ll.require(state['phase'] == 'prepared', 'bootstrap is one-time; not prepared')
            plan = state['plan']
            ll.require(self.engine.current() == plan['legacy'], 'current changed')
            ll.require(not os.path.lexists(self.root / '.ll-transaction.json'), 'existing transaction requires recovery')
            for role in ('fallback', 'candidate'):
                release = self.root / 'releases' / plan[role]['release']
                self.trees.inventory(release)
                ll.require(self.host.check(release) == state['schema'], 'schema changed; no migration is permitted')
            self.host.backup()
            e = self.engine
            e.original, e.candidate = plan['fallback']['release'], plan['candidate']['release']
            e.state.update(operation='bootstrap', legacy=plan['legacy'], schema=state['schema'])
            state['phase'] = 'activating'
            self.write(state)
            handlers = {}
            def interrupted(signum, frame):
                raise ll.Refused('bootstrap interrupted')
            for sig in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
                handlers[sig] = signal.signal(sig, interrupted)
            try:
                e.journal('activating')
                e.activated = True
                e.switch(e.candidate)
                e.journal('activated')
                e.host.restart()
                e.host.readiness(self.root / 'releases' / e.candidate)
                ll.atomic_json(root_fd, '.ll-previous.json', {'release': e.original})
                e.completed = True
                e.journal('committed')
                state['phase'] = 'committed'
                self.write(state)
                print('BOOTSTRAP SUCCESS=' + e.candidate + ' FALLBACK=' + e.original)
            except BaseException:
                e.completed = False
                for sig in handlers:
                    signal.signal(sig, signal.SIG_IGN)
                if e.activated:
                    try:
                        e.recover()
                        state['phase'] = 'recovered'
                    except BaseException:
                        state['phase'] = 'recovery-failed'
                        with contextlib.suppress(BaseException):
                            e.journal('recovery-failed')
                        raise ll.Refused('CRITICAL: bootstrap recovery failed; use journal recovery')
                    finally:
                        self.write(state)
                raise
            finally:
                for sig, previous in handlers.items():
                    signal.signal(sig, previous)


def main():
    os.umask(0o022)
    ll.require(os.geteuid() == 0, 'root administrator required')
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='command', required=True)
    prepare = sub.add_parser('prepare')
    prepare.add_argument('--plan', required=True)
    prepare.add_argument('--plan-sha256', required=True)
    activate = sub.add_parser('activate')
    activate.add_argument('--ledger-sha256', required=True)
    activate.add_argument('--confirm-independent-builds-and-no-migrations', action='store_true', required=True)
    args = parser.parse_args()
    user = pwd.getpwnam('linklibrary')
    bootstrap = Bootstrap(ll.ROOT, Host(ll.ROOT), user.pw_uid, user.pw_gid)
    if args.command == 'prepare':
        bootstrap.prepare(args.plan, args.plan_sha256)
    else:
        bootstrap.activate(args.ledger_sha256)


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        detail = str(error) if isinstance(error, ll.Refused) else type(error).__name__
        print('BOOTSTRAP FAILED: ' + detail + '; preserve root-only evidence; no blind retry', file=sys.stderr)
        sys.exit(1)
