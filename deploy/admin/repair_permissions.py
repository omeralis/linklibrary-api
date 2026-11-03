#!/usr/bin/python3
"""MANUAL ONLY. Requires a separately trusted reference; never blesses current bytes as provenance.
Install beside ll_release.py, root-owned. No deployment or service operation is performed here.
"""
import argparse
import contextlib
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import grp
import stat
import sys

module=Path(__file__).with_name('ll_release.py')
if not module.exists():module=Path(__file__).parents[1]/'server/ll_release.py'
spec=importlib.util.spec_from_file_location('ll_release',module)
ll=importlib.util.module_from_spec(spec);spec.loader.exec_module(ll)


def relative_fd(root_fd, relative, directory=False):
    parts=Path(relative).parts
    ll.require(parts and all(p not in ('.','..','/') for p in parts),'invalid relative repair path')
    fd=os.dup(root_fd)
    try:
        for part in parts[:-1]:
            child=os.open(part,ll.DIR,dir_fd=fd);os.close(fd);fd=child
        flags=ll.DIR if directory else os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK
        target=os.open(parts[-1],flags,dir_fd=fd)
        return target
    finally:os.close(fd)


def inspect(root, name, uid, gid):
    ll.require(ll.RELEASE.fullmatch(name),'invalid release name')
    parent=ll.secure_dir(root/'releases')
    fd=os.open(name,ll.DIR,dir_fd=parent);os.close(parent)
    policy=ll.Trees(root,uid,gid);entries={};metadata={};device=os.fstat(fd).st_dev
    mounts=ll.mount_points()
    def record(s,rel):
        ll.require(s.st_uid in (0,uid) and s.st_gid in (0,gid) and not s.st_mode&0o002,'unexpected owner/group/world-writable entry')
        metadata[rel]={'uid':s.st_uid,'gid':s.st_gid,'mode':stat.S_IMODE(s.st_mode),'inode':s.st_ino,'device':s.st_dev}
    record(os.fstat(fd),'')
    def walk(current,prefix):
        for item in sorted(os.listdir(current)):
            rel=(prefix+'/'+item).lstrip('/')
            if rel==ll.MANIFEST:continue
            s=os.stat(item,dir_fd=current,follow_symlinks=False)
            ll.require(s.st_dev==device and root/'releases'/name/rel not in mounts,'unexpected mount')
            if rel in ll.CACHES:
                ll.require(stat.S_ISDIR(s.st_mode) and s.st_uid in (0,uid) and not s.st_mode&0o002,'cache is not a safe real directory')
                continue
            if stat.S_ISLNK(s.st_mode):
                target=os.readlink(item,dir_fd=current)
                if rel in policy.links:ll.require(target==policy.links[rel],'unexpected shared link')
                else:
                    ll.require(not target.startswith('/'),'absolute code link')
                    lexical=os.path.normpath(str(Path(rel).parent/target))
                    ll.require(lexical.startswith(rel.split('/')[0]+'/') and not any(lexical==c or lexical.startswith(c+'/') for c in ll.CACHES),'escaping code link')
                    linked=relative_fd(fd,lexical,directory=(root/'releases'/name/lexical).is_dir());os.close(linked)
                # symlink modes are always 0777; their target is never chmod/chowned.
                ll.require(s.st_uid in (0,uid),'unexpected symlink owner')
                metadata[rel]={'uid':s.st_uid,'gid':s.st_gid,'mode':0o777,'inode':s.st_ino,'device':s.st_dev,'link':True}
                entries[rel]={'type':'link','target':target}
            elif stat.S_ISDIR(s.st_mode):
                record(s,rel);entries[rel]={'type':'directory'}
                child=os.open(item,ll.DIR,dir_fd=current)
                try:walk(child,rel)
                finally:os.close(child)
            elif stat.S_ISREG(s.st_mode):
                record(s,rel);ll.require(not s.st_mode&0o7000,'privileged file bits')
                data,_=ll.read_file(current,item,immutable=False,limit=ll.LIMIT)
                entries[rel]={'type':'file','sha256':hashlib.sha256(data).hexdigest(),'executable':bool(s.st_mode&0o111),'size':s.st_size}
            else:raise ll.Refused('special file in repair tree')
    try:walk(fd,'')
    finally:os.close(fd)
    return entries,metadata


def writers_stopped(uids):
    for p in Path('/proc').iterdir():
        if p.name.isdigit():
            with contextlib.suppress(FileNotFoundError,ProcessLookupError):
                ll.require(p.stat().st_uid not in uids,'runtime/group-member processes still active')


def correction(root,name,reference,expected_sha,snapshot,uid,gid,apply=False,restore=False):
    root=Path(root);checked=ll.secure_dir(root);os.close(checked)
    ll.require(ll.RELEASE.fullmatch(name),'invalid release name')
    writers_stopped({uid})
    ll.require(os.path.realpath(root/'current')==str(root/'releases'/name),'current release changed')
    reference=Path(reference);parent=ll.secure_dir(reference.parent)
    try:data,_=ll.read_file(parent,reference.name,limit=128*1024**2)
    finally:os.close(parent)
    ll.require(hashlib.sha256(data).hexdigest()==expected_sha,'reference/snapshot digest mismatch')
    trusted=json.loads(data)
    entries,metadata=inspect(root,name,uid,gid)
    expected=trusted['entries']
    ll.require(entries==expected,'current bytes/links differ from trusted reference; reconstruct reviewed bootstrap instead')
    snapshot=Path(snapshot)
    if not restore:
        ll.require(not snapshot.exists(),'snapshot already exists')
        parent=ll.secure_dir(snapshot.parent)
        try:ll.atomic_json(parent,snapshot.name,{'release':name,'entries':entries,'metadata':metadata,'commits':trusted.get('commits',{})})
        finally:os.close(parent)
    if not apply:return
    parent=ll.secure_dir(root/'releases');rootfd=os.open(name,ll.DIR,dir_fd=parent);os.close(parent)
    try:
        for rel,old in metadata.items():
            sfd=os.dup(rootfd) if not rel else None
            if old.get('link'):
                p=Path(rel);dfd=os.dup(rootfd) if str(p.parent)=='.' else relative_fd(rootfd,str(p.parent),True)
                try:
                    current=os.stat(p.name,dir_fd=dfd,follow_symlinks=False)
                    ll.require(current.st_ino==old['inode'],'link replaced after validation')
                    desired=trusted['metadata'][rel] if restore else {'uid':0,'gid':gid}
                    os.chown(p.name,desired['uid'],desired['gid'],dir_fd=dfd,follow_symlinks=False)
                finally:os.close(dfd)
                continue
            if sfd is None:sfd=relative_fd(rootfd,rel,entries[rel]['type']=='directory')
            try:
                current=os.fstat(sfd);ll.require((current.st_ino,current.st_dev)==(old['inode'],old['device']),'inode replaced after validation')
                desired=trusted['metadata'][rel] if restore else {'uid':0,'gid':gid,'mode':0o755 if not rel or entries[rel]['type']=='directory' or entries[rel].get('executable') else 0o644}
                os.fchown(sfd,desired['uid'],desired['gid']);os.fchmod(sfd,desired['mode'])
            finally:os.close(sfd)
    finally:os.close(rootfd)
    if not restore:
        policy=ll.Trees(root,uid,gid)
        manifest=policy.seal(root/'releases'/name,trusted.get('commits',{}))
        ll.require(manifest['entries']==expected,'post-correction integrity mismatch')
    print('Metadata correction verified; caches/shared storage excluded. Services remain stopped.')


def main():
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['plan','apply','restore']);parser.add_argument('--release-id',required=True);parser.add_argument('--reference',required=True);parser.add_argument('--reference-sha256',required=True);parser.add_argument('--snapshot',required=True);parser.add_argument('--confirm-writers-stopped',action='store_true')
    args=parser.parse_args();ll.require(os.geteuid()==0 and args.confirm_writers_stopped,'explicit stopped-writers confirmation and root required')
    account=pwd.getpwnam('linklibrary');members={account.pw_uid}|{p.pw_uid for p in pwd.getpwall() if p.pw_gid==account.pw_gid}|{pwd.getpwnam(n).pw_uid for n in grp.getgrgid(account.pw_gid).gr_mem}
    writers_stopped(members-{0})
    correction(ll.ROOT,args.release_id,args.reference,args.reference_sha256,args.snapshot,account.pw_uid,account.pw_gid,apply=args.mode!='plan',restore=args.mode=='restore')


if __name__=='__main__':
    try:main()
    except (ll.Refused,OSError,ValueError,KeyError):
        print('REFUSED: verify stopped writers, trusted reference, paths and snapshot; do not weaken checks',file=sys.stderr);sys.exit(1)
