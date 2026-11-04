#!/usr/bin/python3
"""Manual emergency recovery only; installation and invocation require separate administrator approval."""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import secrets
import sys

module=Path(__file__).with_name('ll_release.py')
if not module.exists():module=Path(__file__).parents[1]/'server/ll_release.py'
spec=importlib.util.spec_from_file_location('ll_release',module)
ll=importlib.util.module_from_spec(spec);spec.loader.exec_module(ll)


def recover(root,original,journal_sha,host,uid,gid):
    root=Path(root);fd=ll.secure_dir(root)
    lock=os.open('.ll-release.lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600,dir_fd=fd)
    try:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        body,_=ll.read_file(fd,'.ll-transaction.json')
        ll.require(hashlib.sha256(body).hexdigest()==journal_sha,'journal changed since approval')
        state=json.loads(body)
        ll.require(state.get('phase') not in ('committed','recovered','aborted-before-activation'),'no unfinished transaction')
        ll.require(state.get('original')==original and ll.RELEASE.fullmatch(original),'unexpected original release')
        ll.Trees(root,uid,gid).inventory(root/'releases'/original)
        ll.atomic_json(fd,'.ll-recovery-evidence-'+secrets.token_hex(8)+'.json',state)
        if state.get('operation') != 'bootstrap':
            host.reset_password()
        else:
            ll.require(not state.get('migrator_credential_may_be_set'), 'unexpected bootstrap migration credential')
            gate = json.loads(host.app(None, str(root / 'tools/ll-bootstrap-check.php'), str(root / 'releases' / original / 'api')))
            ll.require(gate.get('ready') is True and gate.get('schema') == state.get('schema'),
                       'bootstrap recovery schema changed or has pending migrations')
        engine=ll.Engine(root,host,uid,gid);engine.root_fd=fd
        engine.switch(original)
        try:
            host.restart();host.readiness(root/'releases'/original)
        except BaseException:
            state['phase']='recovery-failed';ll.atomic_json(fd,'.ll-transaction.json',state);raise
        state.update(phase='recovered',migrator_credential_may_be_set=False,recovery_required=False)
        ll.atomic_json(fd,'.ll-transaction.json',state)
        print('MANUAL RECOVERY=healthy; database schema unchanged')
    finally:os.close(lock);os.close(fd)


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--original',required=True);parser.add_argument('--journal-sha256',required=True);parser.add_argument('--confirm-forward-schema-compatible',action='store_true')
    args=parser.parse_args();ll.require(os.geteuid()==0 and args.confirm_forward_schema_compatible,'administrator and explicit schema-compatibility review required')
    user=pwd.getpwnam('linklibrary')
    recover(ll.ROOT,args.original,args.journal_sha256,ll.ProductionHost(ll.ROOT),user.pw_uid,user.pw_gid)


if __name__=='__main__':
    try:main()
    except BaseException:
        print('MANUAL RECOVERY FAILED; keep deployment disabled and inspect preserved evidence',file=sys.stderr);sys.exit(1)
