#!/usr/bin/env python3
"""Проверить ответы PHP без отправки писем и без настоящих SMTP-паролей."""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


def main():
    root = Path(__file__).resolve().parent.parent
    with tempfile.TemporaryDirectory(prefix='nova-swim-mail-check-') as tmp:
        config = Path(tmp) / 'mail-config.php'
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = dict(os.environ, NOVA_SWIM_MAIL_CONFIG=str(config))
        with open(Path(tmp) / 'php.log', 'w') as log:
            server = subprocess.Popen(['php', '-S', '127.0.0.1:' + str(port), '-t', str(root / 'dist')],
                                      env=env, stdout=log, stderr=log)
            try:
                url = 'http://127.0.0.1:' + str(port) + '/mail.php'

                def request(expected, fields=None, origin=None):
                    data = urlencode(fields).encode() if fields is not None else None
                    headers = {'Origin': origin} if origin else {}
                    try:
                        response = urlopen(Request(url, data=data, headers=headers), timeout=10)
                    except HTTPError as exc:
                        response = exc
                    with response:
                        payload = json.loads(response.read())
                        assert response.code == expected, (response.code, payload)
                        assert payload['result'] == 'error', payload
                        return payload

                for _ in range(50):
                    try:
                        request(405)
                        break
                    except URLError:
                        time.sleep(0.1)
                else:
                    raise RuntimeError('PHP-сервер не запустился')
                request(503, {})
                config.write_text('<?php return [];\n')
                request(503, {})
                # Порт 1 локального компьютера: настоящее письмо отправлено не будет.
                config.write_text("""<?php return [
                    'smtp' => ['host' => '127.0.0.1', 'username' => 'test', 'password' => 'test', 'secure' => 'ssl', 'port' => 1],
                    'from' => ['email' => 'test@example.com', 'name' => 'Test'],
                    'recipients' => ['receiver@example.com' => 'Test'],
                    'allowed_origins' => ['https://nova-swim.ru'],
                ];
                """)
                request(400, {})
                request(400, {'Телефон[]': 'invalid'})
                fields = {'Имя': 'Тест', 'Телефон': '+7 (999) 123-45-67',
                          'Согласие на обработку персональных данных': 'on'}
                request(403, fields, 'https://invalid.example')
                payload = request(502, fields, 'https://nova-swim.ru')
                assert 'test@example.com' not in json.dumps(payload)
                print('PHP: проверены 405, 503 (нет конфига / неверный конфиг), 400 (пустые / массивы), 403 и 502 при ошибке SMTP.')
            finally:
                server.terminate()
                server.wait(timeout=10)


if __name__ == '__main__':
    main()
