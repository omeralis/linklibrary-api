#!/usr/bin/env python3
"""Deterministic production archive builder; no development environment or writable caches."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import re
import stat
import tarfile


def package(component, source, output, commit):
    source, output = Path(source).resolve(), Path(output).resolve()
    if not re.fullmatch('[0-9a-f]{40}', commit):
        raise ValueError('full commit SHA required')
    required = ('artisan', 'bootstrap/app.php', 'vendor/autoload.php', 'docs/openapi.yaml') if component == 'api' else (
        'package.json', 'node_modules/next/dist/bin/next', '.next/BUILD_ID', '.next/static')
    if not all((source / p).exists() for p in required):
        raise ValueError('required runtime files are missing')
    blocked = {'.git', '.github', 'tests', 'e2e', 'deploy', 'scripts', 'coverage', 'build'}
    def include(rel):
        p = Path(rel)
        if any(part.startswith('.env') for part in p.parts): return False
        if p.parts[0] in blocked: return False
        if rel in ('release.tgz', 'release.sha256', '.ll-build.json'): return False
        if p.suffix in ('.tgz', '.tar', '.zip'): return False
        if rel == 'bootstrap/cache' or rel.startswith('bootstrap/cache/'): return False
        if rel == '.next/cache' or rel.startswith('.next/cache/'): return False
        if component == 'api' and p.parts[0] == 'docs': return rel in ('docs', 'docs/openapi.yaml')
        if component == 'api' and p.parts[0] == 'storage': return False
        if component == 'web' and p.parts[0] == 'docs': return False
        return True
    paths = []
    for base, dirs, files in os.walk(source, followlinks=False):
        dirs[:] = sorted(d for d in dirs if include((Path(base) / d).relative_to(source).as_posix()))
        for name in sorted(dirs + files):
            p = Path(base) / name
            rel = p.relative_to(source).as_posix()
            if include(rel) and p.resolve() != output:
                paths.append((rel,p))
    with tarfile.open(output, 'w:gz', format=tarfile.PAX_FORMAT) as archive:
        for rel,p in sorted(paths):
            s=p.lstat(); info=tarfile.TarInfo(rel);info.uid=info.gid=0;info.mtime=0
            if stat.S_ISLNK(s.st_mode):
                target=os.readlink(p)
                if os.path.isabs(target) or not p.resolve(strict=True).is_relative_to(source):
                    raise ValueError('unsafe build symlink')
                info.type=tarfile.SYMTYPE;info.linkname=target;info.mode=0o777;archive.addfile(info)
            elif stat.S_ISDIR(s.st_mode):
                info.type=tarfile.DIRTYPE;info.mode=0o755;archive.addfile(info)
            elif stat.S_ISREG(s.st_mode):
                info.mode=0o755 if s.st_mode & 0o111 else 0o644;info.size=s.st_size
                with p.open('rb') as f:archive.addfile(info,f)
            else:raise ValueError('special file in build')
        meta=json.dumps({'version':1,'component':component,'commit':commit,'platform':platform.system(),
                         'libc':platform.libc_ver(),'public_origin':os.environ.get('NEXT_PUBLIC_API_URL')},sort_keys=True).encode()
        info=tarfile.TarInfo('.ll-build.json');info.size=len(meta);info.mode=0o644;archive.addfile(info,io.BytesIO(meta))
    if output.stat().st_size>400*1024**2:raise ValueError('compressed archive exceeds upload cap')
    output.with_suffix('.sha256').write_text(hashlib.sha256(output.read_bytes()).hexdigest()+'\n')
    with tarfile.open(output,'r:gz') as archive:
        names=set(archive.getnames())
        if any(p not in names for p in required):raise ValueError('final archive missing required runtime path')
        if component=='api' and any(n.startswith('docs/') and n!='docs/openapi.yaml' for n in names):
            raise ValueError('internal documentation leaked into artifact')
    print(f'{component} runtime archive validated ({output.stat().st_size} bytes)')


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('component',choices=['api','web']);parser.add_argument('source');parser.add_argument('output');parser.add_argument('--commit',required=True)
    args=parser.parse_args();package(args.component,args.source,args.output,args.commit)
