#!/usr/bin/env python3
"""Передать готовую сборку на сервер по SSH; пароль SMTP не передаётся."""
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys


def main():
    archive = Path(sys.argv[1]).resolve()
    release = os.environ['RELEASE_ID']
    host = os.environ['DEPLOY_HOST']
    port = os.environ.get('DEPLOY_PORT', '22')
    user = os.environ['DEPLOY_USER']
    root = os.environ['DEPLOY_ROOT']
    state = os.environ['DEPLOY_STATE']
    if not re.fullmatch(r'[a-f0-9]{40}-[0-9]+-[0-9]+', release):
        raise ValueError('Некорректный идентификатор релиза')
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', host) or not re.fullmatch(r'[a-zA-Z0-9_-]+', user):
        raise ValueError('Некорректный SSH-адрес')
    if not port.isdigit() or not 1 <= int(port) <= 65535:
        raise ValueError('Некорректный SSH-порт')
    for path in (root, state):
        if not re.fullmatch(r'/[a-zA-Z0-9_./-]+', path) or '..' in path.split('/'):
            raise ValueError('Некорректный путь деплоя')
    if not archive.is_file():
        raise FileNotFoundError(archive)
    target = user + '@' + host
    options = ['-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
               '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15']
    identity = os.environ.get('DEPLOY_IDENTITY')
    if identity:
        options += ['-i', str(Path(identity).expanduser())]
    ssh = ['ssh', '-p', port] + options + [target]
    incoming = state + '/incoming'
    subprocess.run(ssh + [shlex.join(['mkdir', '-p', incoming])], check=True)
    subprocess.run(ssh + [shlex.join(['chmod', '700', state, incoming])], check=True)
    remote_archive = incoming + '/' + release + '.tar.gz'
    subprocess.run(['scp', '-P', port] + options + [str(archive), target + ':' + remote_archive], check=True)
    script = Path(__file__).with_name('activate.sh').read_bytes()
    subprocess.run(ssh + [shlex.join(['bash', '-s', '--', root, state, release])], input=script, check=True)


if __name__ == '__main__':
    main()
