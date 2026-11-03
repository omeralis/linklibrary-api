#!/usr/bin/env python3
"""Read-only artifact check in the secret-bearing job. Never imports or extracts artifact code."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import tarfile

root=Path(os.environ['ARTIFACT_DIR'])
assert root.is_dir() and not root.is_symlink()
files={}
for name in ('release.tgz','release.sha256'):
 fd=os.open(root/name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 with os.fdopen(fd,'rb') as f:
  s=os.fstat(f.fileno());assert stat.S_ISREG(s.st_mode) and s.st_nlink==1
  files[name]=f.read(400*1024**2+1)
assert len(files['release.tgz'])<=400*1024**2
expected=files['release.sha256'].decode().strip();assert re.fullmatch('[0-9a-f]{64}',expected)
assert hashlib.sha256(files['release.tgz']).hexdigest()==expected
with tarfile.open(root/'release.tgz','r:gz') as archive:
 member=archive.getmember('.ll-build.json');assert member.isfile() and member.size<=16384
 metadata=json.load(archive.extractfile(member))
assert metadata['commit']==os.environ['SHA'] and metadata['component']==os.environ['COMPONENT']
assert metadata['platform']=='Linux' and metadata['libc'][0]=='glibc' and tuple(map(int,metadata['libc'][1].split('.')))<=(2,35)
if metadata['component']=='web':assert metadata['public_origin']==os.environ['PUBLIC_ORIGIN'] and os.environ['PUBLIC_ORIGIN'].startswith('https://')
print('Artifact digest, approved commit and target metadata verified without executing package content')
