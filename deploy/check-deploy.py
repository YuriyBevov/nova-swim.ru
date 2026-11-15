#!/usr/bin/env python3
"""Проверить публикацию и откат в отдельной временной папке (Linux)."""
import io
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile


def main():
    script = Path(__file__).with_name('activate.sh').resolve()
    rsync = shutil.which('rsync')
    # На хостинге /tmp может иметь noexec; проверяем в приватной папке скрипта.
    with tempfile.TemporaryDirectory(prefix='nova-swim-deploy-check-', dir=str(script.parent)) as tmp:
        tmp = Path(tmp)
        root = tmp / 'nova-swim/public_html'
        state = tmp / '.deploy/nova-swim'
        (root / 'cgi-bin').mkdir(parents=True)
        (state / 'incoming').mkdir(parents=True)
        (root / 'index.htm').write_text('Страница хостинга')
        (root / 'cgi-bin/keep.txt').write_text('Служебный файл хостинга')
        neighbour = tmp / 'other-site.txt'
        neighbour.write_text('Другой сайт')

        def archive(release, extra=False):
            files = {'index.html': 'Nova Swim', '.htaccess': 'DirectoryIndex index.html\n',
                     'mail.php': '<?php http_response_code(405);', 'phpmailer/PHPMailer.php': '<?php // fixture\n'}
            if extra:
                files['introduced.txt'] = 'Новый файл неудачного релиза'
            with tarfile.open(str(state / 'incoming' / (release + '.tar.gz')), 'w:gz') as tar:
                for name, content in files.items():
                    data = content.encode()
                    info = tarfile.TarInfo(name)
                    info.size = len(data)
                    tar.addfile(info, io.BytesIO(data))

        first = 'a' * 40 + '-1-1'
        archive(first)
        subprocess.run(['bash', str(script), str(root), str(state), first], check=True)
        assert (root / 'index.html').read_text() == 'Nova Swim'
        assert (root / 'index.htm').read_text() == 'Страница хостинга'
        assert (root / 'cgi-bin/keep.txt').is_file()
        assert (state / 'backups' / (first + '.tar.gz')).is_file()

        second = 'b' * 40 + '-2-1'
        archive(second, extra=True)
        # Прерываем второе копирование после появления новых файлов на сайте.
        bindir = tmp / 'bin'
        bindir.mkdir()
        wrapper = bindir / 'rsync'
        wrapper.write_text('#!/bin/bash\n'
                           'if test -e "$NOVA_SWIM_TEST_COUNTER"; then exit 17; fi\n'
                           'touch "$NOVA_SWIM_TEST_COUNTER"\n'
                           'exec "' + rsync + '" "$@"\n')
        wrapper.chmod(0o700)
        env = dict(os.environ, PATH=str(bindir) + ':' + os.environ['PATH'],
                   NOVA_SWIM_TEST_COUNTER=str(tmp / 'rsync-counter'))
        result = subprocess.run(['bash', str(script), str(root), str(state), second], env=env)
        assert result.returncode == 17, result.returncode
        assert (root / 'index.html').read_text() == 'Nova Swim'
        assert not (root / 'introduced.txt').exists()
        assert (state / 'deployed-sha').read_text().strip() == 'a' * 40
        assert (root / 'cgi-bin/keep.txt').is_file()
        assert neighbour.read_text() == 'Другой сайт'
        print('Деплой: публикация, резервная копия и откат при сбое rsync проверены; соседние файлы сохранены.')


if __name__ == '__main__':
    main()
