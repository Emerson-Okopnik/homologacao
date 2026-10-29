"""Production startup contracts using temporary files and command doubles.

Run with python scripts/tests/test_production.py (Bash or Git Bash required).
No database, Redis, Docker, image build, or real application cache is used.
"""
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
BASH = shutil.which('bash')
if os.name == 'nt':
    BASH = str(Path(os.environ.get('ProgramFiles', r'C:\Program Files')) / 'Git/bin/bash.exe')


def shellpath(path):
    value = Path(path).resolve().as_posix()
    return '/' + value[0].lower() + value[2:] if len(value) > 1 and value[1] == ':' else value


def redis_start_command():
    """Read the actual plain-scalar Redis shell command, including Compose escaping."""
    source = (ROOT / 'compose.production.yaml').read_text(encoding='utf-8')
    block = re.search(r'^  redis:\n(.*?)(?=^  [a-zA-Z_][\w-]*:\n|\Z)', source, re.M | re.S)
    if not block:
        raise AssertionError('Redis service is absent from production Compose')
    command_block = re.search(r'^    command:\n((?:      - [^\n]+\n)+)', block.group(1), re.M)
    commands = re.findall(r'^      - (.+)$', command_block.group(1), re.M) if command_block else []
    if len(commands) != 3 or commands[:2] != ['/bin/sh', '-ec']:
        raise AssertionError('Update this reader for the Redis command YAML representation')
    return commands[2].replace('$$', '$')


@unittest.skipUnless(BASH and Path(BASH).exists(), 'Bash is required')
class ProductionStartupTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='homologa-production-test-')
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name)
        self.bin = self.base / 'bin'
        self.state = self.base / 'state'
        self.bin.mkdir()
        self.state.mkdir()
        self.db_secret = self.base / 'db-secret'
        self.redis_secret = self.base / 'redis-secret'
        self.resend_secret = self.base / 'resend-secret'
        self.db_password = 'db-test-with spaces-\'"$!'
        self.redis_password = 'redis-test-with spaces-\'"$!'
        self.resend_api_key = 're_test-with spaces-\'"$!'
        self.db_secret.write_text(self.db_password + '\n', encoding='utf-8')
        self.redis_secret.write_text(self.redis_password + '\n', encoding='utf-8')
        self.resend_secret.write_text(self.resend_api_key + '\n', encoding='utf-8')
        self.env = dict(os.environ, MOCK_STATE=shellpath(self.state),
                        DB_PASSWORD_FILE=shellpath(self.db_secret),
                        REDIS_PASSWORD_FILE=shellpath(self.redis_secret),
                        RESEND_API_KEY_FILE=shellpath(self.resend_secret),
                        MOCK_REDIS_SECRET=shellpath(self.redis_secret),
                        APP_KEY='test-key-only', DB_USERNAME='homologa_app',
                        APP_ENV='production', MAIL_MAILER='resend',
                        MAIL_FROM_ADDRESS='nao-responda@verigrid.com.br',
                        POSTGRES_USER='postgres', POSTGRES_DB='homologa',
                        APP_DB_USERNAME='homologa_app',
                        APP_DB_PASSWORD_FILE=shellpath(self.db_secret))
        self.write_tool('php', '''
printf '%s\n' "$*" >> "$MOCK_STATE/php.calls"
test "$*" != "${MOCK_PHP_FAIL_COMMAND:-}" || exit 91
''')
        self.write_tool('app-server', '''
printf '%s\n%s\n%s\n' "$DB_PASSWORD" "$REDIS_PASSWORD" "$RESEND_API_KEY" > "$MOCK_STATE/app-passwords"
''')
        self.write_tool('psql', '''
printf '%s\n' "$@" > "$MOCK_STATE/psql.args"
/usr/bin/cat > "$MOCK_STATE/psql.sql"
''')
        self.write_tool('cat', '''
if [ "${1:-}" = /run/secrets/redis_password ]; then
    exec /usr/bin/cat "$MOCK_REDIS_SECRET"
fi
exec /usr/bin/cat "$@"
''')
        self.write_tool('docker-entrypoint.sh', '''
printf 'called\n' > "$MOCK_STATE/redis-entrypoint"
exec "$@"
''')
        self.write_tool('redis-server', '''
printf '%s\n' "$@" > "$MOCK_STATE/redis.args"
''')

    def write_tool(self, name, contents):
        path = self.bin / name
        path.write_text('#!/bin/sh\nset -eu\n' + contents + '\n', encoding='utf-8', newline='\n')
        path.chmod(0o755)

    def run_shell(self, command, updates=None):
        prefix = 'export PATH=' + shlex.quote(shellpath(self.bin)) + ':"$PATH"; '
        return subprocess.run([BASH, '-c', prefix + command], cwd=self.base,
                              env=dict(self.env, **(updates or {})), text=True,
                              capture_output=True, timeout=15)

    def run_app(self, updates=None):
        script = shlex.quote(shellpath(ROOT / 'backend/docker/entrypoint.sh'))
        return self.run_shell('exec sh ' + script + ' app-server', updates)

    def run_postgres_init(self, updates=None, sourced=False):
        script = shlex.quote(shellpath(ROOT / 'docker/postgres/production/10-create-app-role.sh'))
        command = 'exec sh ' + script
        if sourced:
            command = 'exec sh -ec ' + shlex.quote('. ' + script + '; printf "source-completed\\n"')
        return self.run_shell(command, updates)

    def run_redis(self):
        return self.run_shell('exec sh -ec ' + shlex.quote(redis_start_command()))

    def assert_app_never_started(self):
        self.assertFalse((self.state / 'php.calls').exists())
        self.assertFalse((self.state / 'app-passwords').exists())

    def assert_redis_never_started(self):
        self.assertFalse((self.state / 'redis-entrypoint').exists())
        self.assertFalse((self.state / 'redis.args').exists())

    def test_app_exports_secret_values_and_executes_only_after_successful_bootstrap(self):
        result = self.run_app()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.state / 'app-passwords').read_text().splitlines(),
                         [self.db_password, self.redis_password, self.resend_api_key])
        self.assertNotIn(self.resend_api_key, result.stdout + result.stderr)
        self.assertEqual((self.state / 'php.calls').read_text().splitlines(),
                         ['artisan package:discover --ansi', 'artisan config:cache', 'artisan route:cache'])

    def test_app_rejects_empty_or_newline_only_secrets_before_php(self):
        for secret in (self.db_secret, self.redis_secret, self.resend_secret):
            for content in ('', '\n', '\n\n'):
                with self.subTest(secret=secret.name, content=repr(content)):
                    original = secret.read_bytes()
                    secret.write_text(content)
                    try:
                        result = self.run_app()
                        self.assertNotEqual(result.returncode, 0)
                        self.assert_app_never_started()
                    finally:
                        secret.write_bytes(original)

    def test_app_rejects_missing_secret_before_php(self):
        for variable in ('DB_PASSWORD_FILE', 'REDIS_PASSWORD_FILE', 'RESEND_API_KEY_FILE'):
            with self.subTest(variable=variable):
                result = self.run_app({variable: shellpath(self.base / 'missing-secret')})
                self.assertNotEqual(result.returncode, 0)
                self.assert_app_never_started()

    def test_production_rejects_incomplete_resend_configuration_before_php(self):
        for updates in (
                {'MAIL_MAILER': 'log'}, {'MAIL_MAILER': 'array'},
                {'MAIL_MAILER': 'failover'}, {'MAIL_MAILER': 'smtp'},
                {'RESEND_API_KEY_FILE': ''}, {'MAIL_FROM_ADDRESS': ''}):
            with self.subTest(updates=updates):
                result = self.run_app(updates)
                self.assertNotEqual(result.returncode, 0)
                self.assert_app_never_started()
                self.assertNotIn(self.resend_api_key, result.stdout + result.stderr)

    def test_local_mailpit_does_not_require_production_credentials_or_tls(self):
        self.write_tool('local-app', 'printf "started\\n"')
        script = shlex.quote(shellpath(ROOT / 'backend/docker/entrypoint.sh'))
        result = self.run_shell('exec sh ' + script + ' local-app', {
            'APP_ENV': 'local', 'RESEND_API_KEY_FILE': '',
            'MAIL_MAILER': 'smtp', 'MAIL_HOST': 'mailpit', 'MAIL_PORT': '1025',
        })
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('started', result.stdout)

    def test_app_rejects_admin_role_and_missing_key_before_php(self):
        for updates in ({'DB_USERNAME': 'postgres'}, {'APP_KEY': ''}):
            with self.subTest(updates=updates):
                result = self.run_app(updates)
                self.assertNotEqual(result.returncode, 0)
                self.assert_app_never_started()

    def test_failed_cache_command_prevents_application_start(self):
        result = self.run_app({'MOCK_PHP_FAIL_COMMAND': 'artisan config:cache'})
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.state / 'app-passwords').exists())
        self.assertEqual((self.state / 'php.calls').read_text().splitlines(),
                         ['artisan package:discover --ansi', 'artisan config:cache'])

    def test_postgres_init_runs_executed_and_sourced_and_preserves_secret_argument(self):
        for sourced in (False, True):
            with self.subTest(sourced=sourced):
                (self.state / 'psql.args').unlink(missing_ok=True)
                result = self.run_postgres_init(sourced=sourced)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertIn('app_password=' + self.db_password,
                              (self.state / 'psql.args').read_text().splitlines())
                if sourced:
                    self.assertIn('source-completed', result.stdout)

    def test_postgres_init_rejects_empty_or_newline_only_secret_before_psql(self):
        for content in ('', '\n', '\n\n'):
            with self.subTest(content=repr(content)):
                self.db_secret.write_text(content)
                result = self.run_postgres_init()
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse((self.state / 'psql.args').exists())

    def test_postgres_init_rejects_missing_secret_and_admin_role_before_psql(self):
        for updates in ({'APP_DB_PASSWORD_FILE': shellpath(self.base / 'missing-secret')},
                        {'APP_DB_USERNAME': 'postgres'}):
            with self.subTest(updates=updates):
                result = self.run_postgres_init(updates)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse((self.state / 'psql.args').exists())

    def test_redis_receives_nonempty_secret_as_single_argument(self):
        result = self.run_redis()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        arguments = (self.state / 'redis.args').read_text().splitlines()
        self.assertEqual(arguments[arguments.index('--requirepass') + 1], self.redis_password)

    def test_redis_rejects_empty_or_newline_only_secret_before_server(self):
        for content in ('', '\n', '\n\n'):
            with self.subTest(content=repr(content)):
                self.redis_secret.write_text(content)
                result = self.run_redis()
                self.assertNotEqual(result.returncode, 0)
                self.assert_redis_never_started()

    def test_redis_rejects_missing_secret_before_server(self):
        self.redis_secret.unlink()
        result = self.run_redis()
        self.assertNotEqual(result.returncode, 0)
        self.assert_redis_never_started()


if __name__ == '__main__':
    unittest.main(verbosity=2)
