#!/usr/bin/python3
"""Root release engine. CLI is fixed to /srv/linklibrary; tests inject disposable roots/hosts.

Never import this module from a release tree. Install alongside ll-release, root-owned.
"""
import contextlib
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import secrets
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import time

ROOT = Path('/srv/linklibrary')
RELEASE = re.compile(r'(?:\d{8}-\d{6}-(?:api|web)-[0-9a-f]{7}|\d{8}-\d{2})\Z')
COMMIT = re.compile(r'[0-9a-f]{40}\Z')
DIGEST = re.compile(r'[0-9a-f]{64}\Z')
CACHES = {'api/bootstrap/cache', 'web/.next/cache'}
MANIFEST = '.ll-manifest.json'
LIMIT = 400 * 1024**2
NOFOLLOW = os.O_NOFOLLOW
DIR = os.O_RDONLY | os.O_DIRECTORY | NOFOLLOW


class Refused(RuntimeError):
    pass


def require(ok, message):
    if not ok:
        raise Refused(message)


def mount_points():
    return {Path(re.sub(r'\\([0-7]{3})', lambda m: chr(int(m[1], 8)), line.split()[4]))
            for line in Path('/proc/self/mountinfo').read_text().splitlines()}


def secure_dir(path):
    """Pin all ancestors without following symlinks; writable sticky /tmp only for isolated fixtures."""
    path = Path(path)
    require(path.is_absolute(), 'absolute directory required')
    fd = os.open('/', DIR)
    try:
        for part in path.parts[1:]:
            new = os.open(part, DIR, dir_fd=fd)
            os.close(fd)
            fd = new
            s = os.fstat(fd)
            require(s.st_uid == 0 and (not s.st_mode & 0o022 or
                    (s.st_mode & stat.S_ISVTX and part == 'tmp')), 'untrusted directory ancestor')
        return fd
    except BaseException:
        os.close(fd)
        raise


def read_file(parent, name, immutable=True, limit=8 * 1024**2):
    fd = os.open(name, os.O_RDONLY | NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
    try:
        s = os.fstat(fd)
        require(stat.S_ISREG(s.st_mode) and s.st_nlink == 1, 'non-regular or hard-linked file')
        if immutable:
            require(s.st_uid == 0 and not s.st_mode & 0o022, 'writable or non-root code')
        require(s.st_size <= limit, 'file limit exceeded')
        chunks = []
        while True:
            b = os.read(fd, 1024 * 1024)
            if not b:
                break
            chunks.append(b)
            require(sum(map(len, chunks)) <= limit, 'file grew past limit')
        after = os.fstat(fd)
        require((s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns) ==
                (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns), 'file changed while reading')
        return b''.join(chunks), s
    finally:
        os.close(fd)


def atomic_json(parent, name, value):
    tmp = '.state-' + secrets.token_hex(12)
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | NOFOLLOW, 0o600, dir_fd=parent)
    try:
        with os.fdopen(fd, 'w') as out:
            json.dump(value, out, sort_keys=True)
            out.flush()
            os.fsync(out.fileno())
        os.replace(tmp, name, src_dir_fd=parent, dst_dir_fd=parent)
        os.fsync(parent)
    finally:
        with contextlib.suppress(FileNotFoundError):
            os.unlink(tmp, dir_fd=parent)


def remove_tree(parent, name, device):
    """Descriptor-relative deletion: never follow links or cross mounts, including cache contents."""
    s = os.stat(name, dir_fd=parent, follow_symlinks=False)
    if not stat.S_ISDIR(s.st_mode):
        os.unlink(name, dir_fd=parent)
        return
    require(s.st_dev == device, 'refusing cross-device deletion')
    fd = os.open(name, DIR, dir_fd=parent)
    try:
        require(os.fstat(fd).st_ino == s.st_ino, 'directory replacement detected')
        for item in os.listdir(fd):
            remove_tree(fd, item, device)
        os.rmdir(name, dir_fd=parent)
    finally:
        os.close(fd)


class Trees:
    def __init__(self, root, runtime_uid, runtime_gid):
        self.root, self.uid, self.gid = Path(root), runtime_uid, runtime_gid
        self.links = {
            'api/.env': str(self.root / 'shared/api.env'),
            'api/storage': str(self.root / 'shared/storage'),
            'api/public/storage': str(self.root / 'shared/storage/app/public'),
            'web/.env.production': str(self.root / 'shared/web.env'),
        }

    def inventory(self, release, verify=True):
        """Check every immutable inode. Runtime subtrees are checked at their boundary, never traversed."""
        fd = secure_dir(release)
        entries, mounts = {}, mount_points()
        device = os.fstat(fd).st_dev
        def visit(dfd, prefix):
            for name in sorted(os.listdir(dfd)):
                rel = (prefix + '/' + name).lstrip('/')
                if rel == MANIFEST:
                    continue
                s = os.stat(name, dir_fd=dfd, follow_symlinks=False)
                require(s.st_dev == device and Path(release, rel) not in mounts, 'unexpected mount point')
                mode = stat.S_IMODE(s.st_mode)
                if rel in CACHES:
                    require(stat.S_ISDIR(s.st_mode) and s.st_uid in (0, self.uid) and s.st_gid == self.gid and
                            not mode & 0o002, 'unsafe runtime cache boundary')
                    continue
                if stat.S_ISLNK(s.st_mode):
                    target = os.readlink(name, dir_fd=dfd)
                    require(s.st_uid == 0, 'non-root symlink')
                    if rel in self.links:
                        require(target == self.links[rel], 'unexpected shared-state link')
                    else:
                        require(not target.startswith('/'), 'absolute code link')
                        resolved = Path(release, rel).parent.joinpath(target).resolve(strict=True)
                        require(resolved.is_relative_to(Path(release, rel.split('/')[0])), 'escaping code link')
                        relative = resolved.relative_to(release).as_posix()
                        require(not any(relative == c or relative.startswith(c + '/') for c in CACHES), 'code link into cache')
                        # Reject chains and symlink ancestors, rather than resolving attacker-controlled chains.
                        lexical = Path(os.path.normpath(str(Path(rel).parent / target)))
                        cur = Path(release)
                        for part in lexical.parts:
                            cur /= part
                            require(not cur.is_symlink(), 'symlink chain')
                    entries[rel] = {'type': 'link', 'target': target}
                elif stat.S_ISDIR(s.st_mode):
                    require(s.st_uid == 0 and not mode & 0o022 and not mode & 0o7000, 'writable/non-root code directory')
                    child = os.open(name, DIR, dir_fd=dfd)
                    try:
                        require(os.fstat(child).st_ino == s.st_ino, 'directory changed')
                        entries[rel] = {'type': 'directory'}
                        visit(child, rel)
                    finally:
                        os.close(child)
                elif stat.S_ISREG(s.st_mode):
                    require(not mode & 0o7000, 'privileged code mode')
                    data, meta = read_file(dfd, name, limit=LIMIT)
                    entries[rel] = {'type': 'file', 'sha256': hashlib.sha256(data).hexdigest(),
                                    'executable': bool(meta.st_mode & 0o111), 'size': meta.st_size}
                else:
                    raise Refused('special file in source release')
        try:
            visit(fd, '')
            if verify:
                data, _ = read_file(fd, MANIFEST, limit=128 * 1024**2)
                manifest = json.loads(data)
                require(manifest.get('version') == 1 and manifest.get('entries') == entries,
                        'release provenance/integrity mismatch (administrator bootstrap required)')
                return manifest
            return {'version': 1, 'entries': entries, 'commits': {}}
        finally:
            os.close(fd)

    def clone(self, source, destination, manifest):
        # Source already immutable and digest-verified. Recheck all bytes while copying; preserve no ownership/modes.
        for rel, meta in manifest['entries'].items():
            target = destination / rel
            if meta['type'] == 'directory':
                target.mkdir(mode=0o755, parents=True, exist_ok=True)
            elif meta['type'] == 'link':
                target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
                target.symlink_to(meta['target'])
            else:
                parent = secure_dir((source / rel).parent)
                try:
                    data, _ = read_file(parent, Path(rel).name, limit=LIMIT)
                finally:
                    os.close(parent)
                require(hashlib.sha256(data).hexdigest() == meta['sha256'], 'source changed after validation')
                target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
                fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | NOFOLLOW,
                             0o755 if meta['executable'] else 0o644)
                with os.fdopen(fd, 'wb') as out:
                    out.write(data)

    def seal(self, release, commits):
        manifest = self.inventory(release, verify=False)
        manifest['commits'] = commits
        fd = secure_dir(release)
        try:
            atomic_json(fd, MANIFEST, manifest)
        finally:
            os.close(fd)
        return self.inventory(release)


def archive_members(package):
    """Whole-archive, order-independent validation; tarfile.extractall is deliberately never used."""
    members, total = {}, 0
    with tarfile.open(package, 'r:gz') as archive:
        for m in archive:
            raw = m.name
            require(not any(ord(c) < 32 for c in raw) and '\\' not in raw and not raw.startswith('/'), 'unsafe archive name')
            parts = raw.split('/')
            require('..' not in parts, 'archive traversal')
            path = PurePosixPath(raw).as_posix()
            if path == '.':
                require(m.isdir(), 'invalid archive root')
                continue
            require(path not in members, 'duplicate archive member')
            require(not m.mode & 0o7000 and m.size >= 0, 'privileged archive mode')
            require(m.isdir() or m.isfile() or m.issym(), 'unsupported archive member')
            require(not any(p.startswith('.env') for p in PurePosixPath(path).parts), 'environment file in artifact')
            require(not any(path == c or path.startswith(c + '/') for c in ('bootstrap/cache', '.next/cache')), 'runtime cache in artifact')
            members[path] = m
            total += m.size
            require(len(members) <= 300000 and total <= 3 * 1024**3, 'expanded artifact too large')
    links = {p for p, m in members.items() if m.issym()}
    for path, m in members.items():
        require(not any(str(p) in links for p in PurePosixPath(path).parents), 'archive member below symlink')
        if m.issym():
            require(m.linkname and not m.linkname.startswith('/') and '\\' not in m.linkname, 'invalid archive link')
            target = os.path.normpath(str(PurePosixPath(path).parent / m.linkname))
            require(target != '..' and not target.startswith('../'), 'archive link escape')
            require(target not in links and not any(str(p) in links for p in PurePosixPath(target).parents), 'archive symlink chain')
            require(target in members or any(p.startswith(target + '/') for p in members), 'dangling archive link')
            require(not any(target == c or target.startswith(c + '/') for c in ('bootstrap/cache', '.next/cache')), 'archive link into cache')
    return members, total


def extract(package, destination):
    members, _ = archive_members(package)
    destination.mkdir(mode=0o755)
    with tarfile.open(package, 'r:gz') as archive:
        for name, m in sorted(members.items(), key=lambda x: (len(PurePosixPath(x[0]).parts), x[0])):
            target = destination / name
            target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            if m.isdir():
                target.mkdir(mode=0o755, exist_ok=True)
            elif m.isfile():
                fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | NOFOLLOW, 0o755 if m.mode & 0o111 else 0o644)
                with archive.extractfile(m) as src, os.fdopen(fd, 'wb') as out:
                    shutil.copyfileobj(src, out, 1024 * 1024)
            else:
                # No member is under a symlink; links are never traversed during extraction.
                target.symlink_to(m.linkname)


class ProductionHost:
    def __init__(self, root):
        self.root = Path(root)

    def command(self, args, *, env=None, data=None):
        # Do not echo stderr, SQL, argv/environment or app exceptions: these can contain secrets.
        with subprocess.Popen(args, env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE, start_new_session=True, umask=0o027) as process:
            try:
                stdout, _ = process.communicate(data, timeout=900)
            except BaseException:
                with contextlib.suppress(ProcessLookupError):
                    os.killpg(process.pid, signal.SIGTERM)
                try:
                    process.communicate(timeout=10)
                except subprocess.TimeoutExpired:
                    with contextlib.suppress(ProcessLookupError):
                        os.killpg(process.pid, signal.SIGKILL)
                    process.communicate()
                raise
            require(process.returncode == 0, 'external command failed: ' + Path(args[0]).name)
            return stdout

    def app(self, api, *args, env=None):
        return self.command(['/usr/sbin/runuser', '-u', 'linklibrary', '--', str(self.root / 'tools/php'),
                             *args], env=env) if api is None else self.command(
            ['/usr/sbin/runuser', '-u', 'linklibrary', '--', str(self.root / 'tools/php'), str(api / 'artisan'), *args], env=env)

    def sql(self, text):
        self.command(['/usr/sbin/runuser', '-u', 'postgres', '--', '/usr/bin/psql', '-X', '-q', '-v',
                      'ON_ERROR_STOP=1', '-d', 'postgres'], data=text.encode())

    def backup(self):
        start = time.time() - 1
        self.command(['/usr/bin/systemctl', 'start', 'linklibrary-backup'])
        directory = Path('/var/backups/linklibrary')
        fd = secure_dir(directory)
        try:
            found = False
            for name in os.listdir(fd):
                if re.fullmatch(r'\d{8}T\d{6}Z.dump', name):
                    s = os.stat(name, dir_fd=fd, follow_symlinks=False)
                    found |= stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_size > 0 and s.st_mtime >= start
            require(found, 'no fresh database backup')
        finally:
            os.close(fd)

    def set_password(self, password):
        until = (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(minutes=30)).isoformat()
        self.sql("SET log_statement='none'; SET log_min_error_statement='panic'; "
                 f"ALTER ROLE linklibrary_migrator PASSWORD '{password}' VALID UNTIL '{until}';")

    def reset_password(self):
        for _ in range(3):
            try:
                self.sql("ALTER ROLE linklibrary_migrator PASSWORD NULL VALID UNTIL 'infinity';")
                return
            except Refused:
                time.sleep(1)
        raise Refused('CRITICAL: migrator credential reset failed; credential expires within 30 minutes')

    def migrate(self, api, password):
        env = dict(os.environ, DB_USERNAME='linklibrary_migrator', DB_PASSWORD=password)
        code = ('require ' + json.dumps(str(api / 'vendor/autoload.php')) + ';$a=require ' +
                json.dumps(str(api / 'bootstrap/app.php')) +
                ';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                '$r=Illuminate\\Support\\Facades\\DB::selectOne("select current_database() db,current_user usr");'
                'if($r->db!=="linklibrary_prod"||$r->usr!=="linklibrary_owner")exit(1);')
        self.app(None, '-r', code, env=env)
        self.app(api, 'migrate', '--force', '--no-interaction', env=env)

    def caches(self, api):
        for name in ('config:cache', 'route:cache', 'view:cache'):
            self.app(api, name, '--no-interaction')

    def restart(self):
        self.command(['/usr/bin/systemctl', 'restart', 'linklibrary-api', 'linklibrary-web', 'linklibrary-queue'])

    def readiness(self, release):
        for unit in ('linklibrary-api', 'linklibrary-web', 'linklibrary-queue', 'linklibrary-scheduler.timer'):
            self.command(['/usr/bin/systemctl', 'is-active', '--quiet', unit])
        raw = self.app(None, str(self.root / 'tools/ll-readiness.php'), str(release / 'api'))
        require(json.loads(raw).get('ready') is True, 'mandatory private database/security readiness failed')
        for path in ('/up', '/api/v1/public/categories', '/en', '/ar'):
            body = self.command(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--retry', '3',
                                 '--retry-connrefused', '--max-time', '15', '--connect-to',
                                 '31.172.87.248:8080:127.0.0.1:8080', 'https://31.172.87.248:8080' + path])
            if path.startswith('/api/'):
                require(json.loads(body).get('success') is True, 'mandatory API JSON readiness failed')
            elif path in ('/en', '/ar'):
                require(re.search(rb'lang=[\"\x27]' + path[1:].encode() + rb'[\"\x27]', body), 'mandatory frontend readiness failed')

    def warnings(self):
        try:
            self.command([str(self.root / 'tools/health.sh')])
        except Refused:
            print('WARNING: operational health conditions need administrator review', flush=True)


class Engine:
    def __init__(self, root, host, runtime_uid, runtime_gid):
        self.root, self.host = Path(root), host
        self.trees = Trees(root, runtime_uid, runtime_gid)
        self.original = self.candidate = None
        self.activated = self.restart_attempted = self.completed = False
        self.recovery_attempted = self.password_set = False
        self.recovered = False
        self.state = {}

    def journal(self, phase):
        self.state.update(phase=phase, original=self.original, candidate=self.candidate,
                          activated=self.activated, restart_attempted=self.restart_attempted,
                          completed=self.completed, recovery_attempted=self.recovery_attempted,
                          recovery_required=self.activated and not (self.completed or self.recovered),
                          migrator_credential_may_be_set=self.password_set,
                          timestamp=datetime.datetime.now(datetime.timezone.utc).isoformat())
        atomic_json(self.root_fd, '.ll-transaction.json', self.state)

    def current(self):
        name = os.readlink('current', dir_fd=self.root_fd)
        absolute = Path(os.path.normpath(str(self.root / name)))
        require(absolute.parent == self.root / 'releases' and RELEASE.fullmatch(absolute.name), 'unexpected current target')
        return absolute.name

    def switch(self, name):
        require(RELEASE.fullmatch(name) is not None, 'invalid switch target')
        self.trees.inventory(self.root / 'releases' / name)
        temp = '.current-' + secrets.token_hex(12)
        os.symlink(str(self.root / 'releases' / name), temp, dir_fd=self.root_fd)
        try:
            os.replace(temp, 'current', src_dir_fd=self.root_fd, dst_dir_fd=self.root_fd)
            os.fsync(self.root_fd)
        finally:
            with contextlib.suppress(FileNotFoundError):
                os.unlink(temp, dir_fd=self.root_fd)

    def stage(self, component, commit, digest):
        incoming = os.open(self.root / 'incoming', DIR)
        # incoming is CI-writable, but root's parent is immutable and no path component is followed.
        try:
            fd = os.open(f'{component}-{commit}.tgz', os.O_RDONLY | NOFOLLOW | os.O_NONBLOCK, dir_fd=incoming)
        finally:
            os.close(incoming)
        package = self.staging / 'package.tgz'
        with os.fdopen(fd, 'rb') as src:
            s = os.fstat(src.fileno())
            require(stat.S_ISREG(s.st_mode) and s.st_size <= LIMIT and s.st_nlink == 1, 'invalid incoming artifact')
            count, h = 0, hashlib.sha256()
            with package.open('xb') as dst:
                os.chmod(package, 0o600)
                while True:
                    chunk = src.read(1024 * 1024)
                    if not chunk:
                        break
                    count += len(chunk)
                    require(count <= LIMIT, 'artifact grew beyond limit')
                    h.update(chunk)
                    dst.write(chunk)
        require(h.hexdigest() == digest, 'artifact SHA256 mismatch')
        archive_members(package)
        with tarfile.open(package, 'r:gz') as archive:
            metadata = archive.getmember('.ll-build.json')
            require(metadata.isfile() and metadata.size <= 16384, 'invalid build metadata')
            build = json.load(archive.extractfile(metadata))
            require(build.get('commit') == commit and build.get('component') == component,
                    'artifact commit/component mismatch')
            require(build.get('platform') == 'Linux' and build.get('libc', [None])[0] == 'glibc' and
                    tuple(map(int, build['libc'][1].split('.'))) <= (2, 35), 'artifact needs Ubuntu 22.04-compatible baseline')
        return package

    def runtime_dirs(self, release):
        for rel in CACHES:
            target = release / rel
            target.mkdir(parents=True, exist_ok=False)
            os.chown(target, self.trees.uid, self.trees.gid)
            os.chmod(target, 0o2770)

    def retain(self):
        previous = self.original
        names = sorted((n for n in os.listdir(self.rel_fd) if re.fullmatch(r'\d{8}-\d{6}-(?:api|web)-[0-9a-f]{7}', n)), reverse=True)
        for name in names[3:]:
            if name not in (self.current(), previous):
                self.trees.inventory(self.root / 'releases' / name)
                require(not any(p.is_relative_to(self.root / 'releases' / name) for p in mount_points()), 'mount inside retained release')
                remove_tree(self.rel_fd, name, os.fstat(self.rel_fd).st_dev)

    def recover(self):
        self.recovery_attempted = True
        self.journal('recovering')
        self.switch(self.original)
        self.host.restart()
        self.host.readiness(self.root / 'releases' / self.original)
        self.recovered = True
        self.journal('recovered')
        print('RECOVERY=healthy; database schema was NOT rolled back', flush=True)

    def run(self, command, component=None, commit=None, digest=None, target=None):
        require((command == 'deploy' and component in ('api', 'web') and COMMIT.fullmatch(commit or '') and
                 DIGEST.fullmatch(digest or '')) or
                (command == 'rollback' and RELEASE.fullmatch(target or '')), 'invalid command arguments')
        self.root_fd = secure_dir(self.root)
        self.rel_fd = secure_dir(self.root / 'releases')
        tools_fd = secure_dir(self.root / 'tools')
        try:
            for helper in ('php', 'll-readiness.php'):
                read_file(tools_fd, helper)
        finally:
            os.close(tools_fd)
        shared_fd = secure_dir(self.root / 'shared')
        try:
            for name in ('api.env', 'web.env'):
                _, metadata = read_file(shared_fd, name)
                require(metadata.st_gid == self.trees.gid and not metadata.st_mode & 0o007,
                        'shared environment permissions expose secrets or use the wrong runtime group')
        finally:
            os.close(shared_fd)
        self.lock_fd = os.open('.ll-release.lock', os.O_CREAT | os.O_RDWR | NOFOLLOW, 0o600, dir_fd=self.root_fd)
        locked = os.fstat(self.lock_fd)
        require(locked.st_uid == 0 and stat.S_ISREG(locked.st_mode) and locked.st_nlink == 1 and
                not locked.st_mode & 0o022, 'untrusted lock')
        fcntl.flock(self.lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.staging = self.root / ('.staging-' + secrets.token_hex(12))
        self.staging.mkdir(mode=0o700)
        handlers = {}
        def interrupted(signum, _frame):
            raise Refused('interrupted by signal ' + str(signum))
        for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
            handlers[sig] = signal.signal(sig, interrupted)
        try:
            if os.path.lexists(self.root / '.ll-transaction.json'):
                previous, _ = read_file(self.root_fd, '.ll-transaction.json')
                recorded = json.loads(previous)
                require(recorded.get('phase') in ('committed', 'recovered', 'aborted-before-activation') and
                        not recorded.get('migrator_credential_may_be_set'),
                        'unfinished transaction requires explicit administrator recovery')
            self.original = self.current()
            self.state.update(operation=command, component=component, commit=commit, digest=digest)
            source = self.root / 'releases' / self.original
            manifest = self.trees.inventory(source)
            if command == 'rollback':
                require(target and RELEASE.fullmatch(target), 'explicit rollback release required')
                require(target != self.original, 'already current')
                self.candidate = target
                self.trees.inventory(self.root / 'releases' / target)
            else:
                require(command == 'deploy' and component in ('api', 'web') and COMMIT.fullmatch(commit or '') and
                        DIGEST.fullmatch(digest or ''), 'invalid deployment arguments')
                package = self.stage(component, commit, digest)
                _, unpacked = archive_members(package)
                clone_bytes = sum(entry.get('size', 0) for entry in manifest['entries'].values())
                require(shutil.disk_usage(self.root).free > unpacked + clone_bytes + 4 * 1024**3,
                        'insufficient clone/expansion/backup headroom')
                self.candidate = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S') + '-' + component + '-' + commit[:7]
                candidate = self.staging / 'release'
                candidate.mkdir(mode=0o755)
                self.trees.clone(source, candidate, manifest)
                fd = secure_dir(candidate)
                try:
                    remove_tree(fd, component, os.fstat(fd).st_dev)
                finally:
                    os.close(fd)
                extract(package, candidate / component)
                for rel, link in self.trees.links.items():
                    p = candidate / rel
                    if rel.startswith(component + '/'):
                        require(not p.exists() and not p.is_symlink(), 'artifact supplies shared state')
                        p.parent.mkdir(parents=True, exist_ok=True)
                        p.symlink_to(link)
                (candidate / 'api/resources/views').mkdir(parents=True, exist_ok=True)
                for cache in CACHES:
                    (candidate / cache).parent.mkdir(parents=True, exist_ok=True)
                # Validate/seal while candidate is private and before the runtime account can write any cache.
                commits = dict(manifest.get('commits', {}), **{component: commit})
                self.trees.seal(candidate, commits)
                require(not (self.root / 'releases' / self.candidate).exists(), 'release collision')
                os.rename(candidate, self.root / 'releases' / self.candidate)
                candidate = self.root / 'releases' / self.candidate
                self.runtime_dirs(candidate)
                self.journal('prepared')
                if component == 'api':
                    self.host.backup()
                    self.password_set = True
                    self.host.set_password(password := secrets.token_hex(32))
                    try:
                        self.journal('migrating')
                        self.host.migrate(candidate / 'api', password)
                    finally:
                        self.host.reset_password()
                        self.password_set = False
                        password = None
                self.host.caches(candidate / 'api')
                # No root traversal of app-owned caches. Every immutable entry must still match its manifest.
                self.trees.inventory(candidate)
            self.journal('activating')
            # Mark BEFORE replacement so a signal in the replacement/fsync window always restores original.
            self.activated = True
            self.switch(self.candidate)
            self.journal('activated')
            self.restart_attempted = True
            self.host.restart()
            self.host.readiness(self.root / 'releases' / self.candidate)
            self.host.warnings()
            self.retain()
            remove_tree(self.root_fd, self.staging.name, os.fstat(self.root_fd).st_dev)
            atomic_json(self.root_fd, '.ll-previous.json', {'release': self.original})
            self.completed = True
            try:
                self.journal('committed')
            except BaseException:
                self.completed = False
                raise
            print('SUCCESS=' + self.candidate, flush=True)
        except BaseException as error:
            self.state['failure_type'] = type(error).__name__
            if self.activated and not self.completed and not self.recovery_attempted:
                # Recovery itself is never recursively retried. SIGKILL/power loss require manual journal recovery.
                for sig in handlers:
                    signal.signal(sig, signal.SIG_IGN)
                try:
                    self.recover()
                except BaseException:
                    print('CRITICAL: RECOVERY FAILED; administrator must use transaction journal', file=sys.stderr, flush=True)
                    with contextlib.suppress(BaseException):
                        self.journal('recovery-failed')
            raise
        finally:
            for sig in handlers:
                signal.signal(sig, signal.SIG_IGN)
            try:
                if self.password_set:
                    self.host.reset_password()
                    self.password_set = False
            finally:
                # Only our private staging directory is deleted. Failed published candidates retain diagnostics.
                if os.path.lexists(self.staging):
                    remove_tree(self.root_fd, self.staging.name, os.fstat(self.root_fd).st_dev)
                if self.original and not self.activated and not self.completed and not self.password_set:
                    self.journal('aborted-before-activation')
                for sig, previous in handlers.items():
                    signal.signal(sig, previous)
                for fd in (self.lock_fd, self.rel_fd, self.root_fd):
                    os.close(fd)


def main():
    os.umask(0o022)
    require(os.geteuid() == 0, 'root required')
    args = sys.argv[1:]
    require((len(args) == 4 and args[0] == 'deploy') or (len(args) == 2 and args[0] == 'rollback'), 'explicit command arguments required')
    user = pwd.getpwnam('linklibrary')
    engine = Engine(ROOT, ProductionHost(ROOT), user.pw_uid, user.pw_gid)
    if args[0] == 'deploy':
        engine.run('deploy', component=args[1], commit=args[2], digest=args[3])
    else:
        engine.run('rollback', target=args[1])


if __name__ == '__main__':
    try:
        main()
    except Refused as error:
        print('RELEASE FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
    except (OSError, ValueError, subprocess.SubprocessError):
        # No traceback or raw child output: secrets must not leak into CI logs.
        print('RELEASE FAILED: consult root-only transaction journal and administrator checks', file=sys.stderr)
        sys.exit(1)
