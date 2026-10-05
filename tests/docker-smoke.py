#!/usr/bin/env python3
"""Install and exercise a real Craft app on an isolated Docker network; no email."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
PHP_VERSION = os.environ.get('CRAFT_TEST_PHP', '8.3')
if PHP_VERSION not in ('8.2', '8.3'):
    raise ValueError('CRAFT_TEST_PHP must be 8.2 or 8.3')
IMAGE = 'visibility-craft-tests:php' + PHP_VERSION.replace('.', '')
SUFFIX = uuid.uuid4().hex[:10]
NETWORK = 'visibility-craft-' + SUFFIX
DB = NETWORK + '-db'


def run(*args, **kwargs):
    result = subprocess.run(args, text=True, capture_output=True, **kwargs)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result.stdout


with tempfile.TemporaryDirectory(prefix='visibility-craft-') as directory:
    app = Path(directory)
    shutil.copytree(ROOT / 'tests/fixture', app, dirs_exist_ok=True)
    for child in ['storage', 'templates', 'web/cpresources']:
        (app / child).mkdir(parents=True, exist_ok=True)
    # These are isolated fixture values, never account credentials.
    (app / '.env').write_text('\n'.join([
        'CRAFT_APP_ID=mailchannels-fixture',
        'CRAFT_ENVIRONMENT=dev',
        'CRAFT_SECURITY_KEY=fixture-only-key-not-for-production-123456',
        'CRAFT_DB_DRIVER=mysql', 'CRAFT_DB_SERVER=' + DB,
        'CRAFT_DB_PORT=3306', 'CRAFT_DB_DATABASE=craft_fixture',
        'CRAFT_DB_USER=root', 'CRAFT_DB_PASSWORD=fixture-only-password',
        'MAILCHANNELS_CRAFT_FIXTURE_KEY=fixture-secret-not-real', '',
    ]))
    rootless = 'name=rootless' in run('docker', 'info', '--format', '{{json .SecurityOptions}}')
    user = [] if rootless else ['--user', f'{os.getuid()}:{os.getgid()}']
    mounts = [*user, '-e', 'COMPOSER_HOME=/tmp/composer', '-v', str(app) + ':/app', '-v', str(ROOT) + ':/plugin:ro']
    runtime = ['docker', 'run', '--rm', '--network', NETWORK,
               '-e', 'CRAFT_ALLOW_SUPERUSER=1', '-e', 'CRAFT_WEB_ROOT=/app/web',
               *mounts, IMAGE, 'php']
    network_created = False
    db_created = False
    try:
        run('docker', 'build', '--build-arg', 'PHP_VERSION=' + PHP_VERSION,
            '-t', IMAGE, '-f', str(ROOT / 'tests/Dockerfile'), str(ROOT))
        run('docker', 'run', '--rm', *mounts, IMAGE,
            'composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress')
        run('docker', 'network', 'create', '--internal', NETWORK)
        network_created = True
        run('docker', 'run', '-d', '--name', DB, '--network', NETWORK,
            '-e', 'MYSQL_ROOT_PASSWORD=fixture-only-password',
            '-e', 'MYSQL_DATABASE=craft_fixture', 'mysql:8.4.4')
        db_created = True
        for attempt in range(60):
            probe = subprocess.run(['docker', 'exec', DB, 'mysqladmin',
                '--host=127.0.0.1', '--user=root', '--password=fixture-only-password', 'ping'],
                capture_output=True)
            if probe.returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Fixture MySQL did not become ready')
        output = run(*runtime, 'craft', 'install/craft', '--interactive=0',
                     '--email=admin@example.com', '--username=admin',
                     '--password=fixture-only-admin-password', '--site-name=Fixture',
                     '--site-url=http://localhost:8187', '--language=en-US')
        if 'installed Craft successfully' not in output:
            raise RuntimeError('Craft installation did not confirm success: ' + output)
        output = run(*runtime, 'craft', 'plugin/install', 'mailchannels', '--interactive=0')
        if 'installed mailchannels successfully' not in output:
            raise RuntimeError('Plugin installation did not confirm success: ' + output)
        output = run(*runtime, '/plugin/tests/app-smoke.php')
        if output.count('PASS:') != 9 or 'No live API requests or email sent.' not in output:
            raise RuntimeError('Incomplete smoke checks: ' + output)
        print(output, end='')
        checked = 0
        for folder in ['storage/logs', 'config/project']:
            for file in (app / folder).rglob('*'):
                if file.is_file():
                    checked += 1
                    if b'fixture-secret-not-real' in file.read_bytes():
                        raise RuntimeError('Fixture secret leaked into ' + str(file.relative_to(app)))
        print(json.dumps({'files_checked_for_secret_leaks': checked, 'secret_matches': 0,
                          'craft': '5.11.4', 'php_series': PHP_VERSION, 'live_email_sent': False}))
    finally:
        if db_created:
            subprocess.run(['docker', 'rm', '-f', DB], capture_output=True)
        if network_created:
            subprocess.run(['docker', 'network', 'rm', NETWORK], capture_output=True)
