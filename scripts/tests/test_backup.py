"""Host-script contract tests with command doubles; no real OCI, age or Docker calls.

Run with python scripts/tests/test_backup.py (Bash required; Git Bash is supported).
The age double copies bytes; these tests do not claim cryptographic/DB validation.
"""
import hashlib
import io
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
BASH = shutil.which('bash')
if os.name == 'nt':
    BASH = str(Path(os.environ.get('ProgramFiles', r'C:\Program Files')) / 'Git/bin/bash.exe')


def shellpath(path):
    value = Path(path).resolve().as_posix()
    return '/' + value[0].lower() + value[2:] if len(value) > 1 and value[1] == ':' else value


def tgz(entries):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode='w:gz') as archive:
        for name, data in entries.items():
            info = tarfile.TarInfo(name)
            if data is None:
                info.type = tarfile.DIRTYPE
                archive.addfile(info)
            else:
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
    return output.getvalue()


@unittest.skipUnless(BASH and Path(BASH).exists(), 'Bash is required')
class BackupScriptsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='homologa-backup-test-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.project = self.base / 'project'
        self.backups = self.base / 'backups'
        self.bin = self.base / 'bin'
        self.state = self.base / 'state'
        for directory in (self.project, self.backups, self.bin, self.state):
            directory.mkdir()
        (self.project / 'infra').mkdir()
        (self.project / 'secrets').mkdir()
        (self.project / 'data/storage/app/private').mkdir(parents=True)
        (self.project / 'data/storage/app/private/document.pdf').write_bytes(b'private document')
        for name in ('.env.production', '.env.app', '.env.db', '.env.web', 'compose.production.yaml',
                     'infra/Caddyfile', 'secrets/db_password.txt', 'secrets/redis_password.txt'):
            (self.project / name).write_text('fixture secret\n', encoding='utf-8')
        self.write_tool('python3', 'exec python "$@"')
        self.write_tool('flock', 'exit "${MOCK_LOCK_FAIL:-0}"')
        self.write_tool('git', 'printf "%s\\n" fixture-commit')
        self.write_tool('age', '''
output=''; input=''
while (( $# )); do
  case "$1" in
    -o) output=$2; shift 2 ;;
    -r|-i) shift 2 ;;
    --decrypt) shift ;;
    *) input=$1; shift ;;
  esac
done
if [[ -n "$input" ]]; then cp -- "$input" "$output"; else cat > "$output"; fi
''')
        self.write_tool('docker', '''
printf '%s\\n' "$*" >> "$MOCK_STATE/docker.log"
if [[ "$1" == compose ]]; then
  [[ "$2 $3 $4 $5" == '--env-file .env.production -f compose.production.yaml' ]] || exit 93
  case "$*" in
    *'config --quiet'*) exit 0 ;;
    *'pg_dump'*) printf 'PGDMP-fixture'; exit 0 ;;
    *'pg_restore --list'*) cat >/dev/null; printf 'fixture dump contents\\n'; exit 0 ;;
    *'printf'*'POSTGRES_DB'*) printf homologa; exit 0 ;;
    *'images --format json'*) printf '[{"ID":"sha256:fixture"}]\\n'; exit 0 ;;
    *'ps --all --quiet'*) printf 'fixture-container\\n'; exit 0 ;;
  esac
fi
case "$1 $2" in
  'inspect --format') printf '/fixture homologa:test sha256:fixture\\n' ;;
  'container inspect') exit 1 ;;
  'volume inspect')
    if [[ "${3:-}" == --format ]]; then cat "$MOCK_STATE/token"; else exit 1; fi ;;
  'volume create') printf '%s' "$4" | cut -d= -f2 > "$MOCK_STATE/token" ;;
  'volume rm'|'rm --force'|'create --name'|'start '*) : ;;
  'exec '*| 'exec -i')
    case "$*" in
      *'pg_restore -U'*) cat >/dev/null; exit "${MOCK_RESTORE_FAIL:-0}" ;;
      *pg_restore*) cat >/dev/null ;;
      *psql*) printf '%s\\n' "${MOCK_TABLE_COUNT:-12}" ;;
      *) : ;;
    esac ;;
  *) exit 94 ;;
esac
''')
        self.write_tool('oci', '''
printf '%s\\n' "$*" >> "$MOCK_STATE/oci.log"
action=$3; shift 3
file=''; md5=''
while (( $# )); do
  case "$1" in
    --file) file=$2; shift 2 ;;
    --content-md5) md5=$2; shift 2 ;;
    *) shift ;;
  esac
done
if [[ "$action" == put ]]; then
  printf '%s' "$md5" > "$MOCK_STATE/md5"
  stat -c %s "$file" > "$MOCK_STATE/size"
  printf '{"opc-content-md5":"%s"}\\n' "$md5"
else
  md5=$(cat "$MOCK_STATE/md5")
  [[ "${MOCK_BAD_HEAD:-0}" == 0 ]] || md5=wrong
  printf '{"content-md5":"%s","content-length":%s}\\n' "$md5" "$(cat "$MOCK_STATE/size")"
fi
''')
        self.env = os.environ.copy()
        self.env.update(MOCK_STATE=shellpath(self.state))
        self.config = self.base / 'backup.conf'
        self.configure()

    def configure(self, max_size=1000000000):
        settings = dict(PROJECT_DIR=shellpath(self.project), BACKUP_ROOT=shellpath(self.backups),
                        AGE_RECIPIENT='age1fixture', OCI_NAMESPACE='fixture', OCI_BUCKET='fixture',
                        OCI_REGION='fixture', OCI_BIN=shellpath(self.bin / 'oci'), MAX_ARCHIVE_BYTES=str(max_size))
        self.config.write_text('\n'.join(f'{key}={shlex.quote(value)}' for key, value in settings.items()) + '\n', encoding='utf-8')
        self.config.chmod(0o600)

    def write_tool(self, name, contents):
        path = self.bin / name
        path.write_text('#!/usr/bin/env bash\nset -euo pipefail\n' + contents + '\n', encoding='utf-8', newline='\n')
        path.chmod(0o755)

    def run_script(self, name, *args):
        # Bash establishes its own MSYS PATH, then puts the doubles first.
        command = 'export PATH=' + shlex.quote(shellpath(self.bin)) + ':"$PATH"; exec bash ' + shlex.quote(shellpath(ROOT / 'scripts' / name))
        command += ''.join(' ' + shlex.quote(shellpath(argument)) for argument in args)
        return subprocess.run([BASH, '-c', command], env=self.env, capture_output=True, text=True, timeout=30)

    def test_success_captures_private_files_and_secrets_and_verifies_remote(self):
        result = self.run_script('backup.sh', self.config)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue((self.backups / 'last-success.txt').exists())
        packet, = self.backups.glob('homologa-*.tar.gz.age')
        with tarfile.open(packet, 'r:gz') as archive:
            uploads = archive.extractfile('./uploads.tar.gz').read()
            configs = archive.extractfile('./config.tar.gz').read()
        with tarfile.open(fileobj=io.BytesIO(uploads), mode='r:gz') as archive:
            self.assertEqual(archive.extractfile('app/private/document.pdf').read(), b'private document')
        with tarfile.open(fileobj=io.BytesIO(configs), mode='r:gz') as archive:
            self.assertIn('secrets/db_password.txt', archive.getnames())
            self.assertIn('.env.app', archive.getnames())
        calls = (self.state / 'oci.log').read_text()
        self.assertIn('--auth instance_principal', calls)
        self.assertIn('--no-overwrite --verify-checksum --content-md5', calls)
        self.assertFalse(list(self.backups.glob('.work.*')))

    def test_wrong_remote_hash_keeps_old_copies_and_no_success_marker(self):
        old = self.backups / 'homologa-old.tar.gz.age'
        old.write_bytes(b'old')
        os.utime(old, (1, 1))
        self.env['MOCK_BAD_HEAD'] = '1'
        result = self.run_script('backup.sh', self.config)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Remote size/checksum differs', result.stderr)
        self.assertFalse((self.backups / 'last-success.txt').exists())
        self.assertTrue(old.exists())

    def test_oversized_archive_is_not_uploaded(self):
        self.configure(max_size=1)
        result = self.run_script('backup.sh', self.config)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('MAX_ARCHIVE_BYTES', result.stderr)
        self.assertFalse((self.state / 'oci.log').exists())
        self.assertFalse((self.backups / 'last-success.txt').exists())
        self.assertFalse(list(self.backups.glob('*.partial')))

    def test_lock_prevents_second_backup(self):
        self.env['MOCK_LOCK_FAIL'] = '1'
        result = self.run_script('backup.sh', self.config)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Another backup is running', result.stderr)
        self.assertFalse((self.state / 'docker.log').exists())

    def test_backup_root_cannot_be_project_ancestor(self):
        self.config.write_text(self.config.read_text().replace(
            'BACKUP_ROOT=' + shlex.quote(shellpath(self.backups)),
            'BACKUP_ROOT=' + shlex.quote(shellpath(self.base))))
        result = self.run_script('backup.sh', self.config)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('ancestor of the project', result.stderr)
        self.assertFalse((self.state / 'docker.log').exists())

    def test_success_prunes_only_old_local_project_archives(self):
        old = self.backups / 'homologa-old.tar.gz.age'
        unrelated = self.backups / 'other-old.tar.gz.age'
        for path in (old, unrelated):
            path.write_bytes(b'old')
            os.utime(path, (1, 1))
        result = self.run_script('backup.sh', self.config)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse(old.exists())
        self.assertTrue(unrelated.exists())

    def restore_packet(self, corrupt=False):
        config = {name: b'fixture' for name in ('.env.production', '.env.app', '.env.db', '.env.web',
                  'compose.production.yaml', 'infra/Caddyfile', 'secrets/db_password.txt', 'secrets/redis_password.txt')}
        contents = {'database.dump': b'PGDMP-fixture', 'database.contents.txt': b'fixture',
                    'uploads.tar.gz': tgz({'app': None, 'app/private': None, 'app/private/test.pdf': b'upload'}),
                    'config.tar.gz': tgz(config), 'checkout.txt': b'commit', 'running-images.txt': b'images',
                    'running-containers.txt': b'containers', 'manifest.json': json.dumps({'format_version': 1}).encode()}
        contents['SHA256SUMS'] = ''.join(f'{hashlib.sha256(data).hexdigest()}  {name}\n' for name, data in contents.items()).encode()
        if corrupt:
            contents['database.dump'] = b'corruption'
        packet = self.base / 'test.tar.gz.age'
        packet.write_bytes(tgz(contents))
        identity = self.base / 'identity'
        identity.write_text('fixture')
        return packet, identity

    def test_restore_isolated_and_removes_only_disposable_resources(self):
        packet, identity = self.restore_packet()
        result = self.run_script('restore-check.sh', packet, identity)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        calls = (self.state / 'docker.log').read_text()
        self.assertIn('--network none', calls)
        self.assertIn('pg_isready -h 127.0.0.1 -U postgres', calls)
        self.assertNotIn('compose', calls)
        self.assertNotIn('--publish', calls)
        self.assertIn('pg_restore -U postgres -d restore_check --no-owner --no-acl --exit-on-error', calls)
        self.assertRegex(calls, r'rm --force homologa-restore-[0-9a-f]{24}')
        self.assertRegex(calls, r'volume rm homologa-restore-[0-9a-f]{24}')

    def test_corrupted_archive_does_not_touch_docker(self):
        packet, identity = self.restore_packet(corrupt=True)
        result = self.run_script('restore-check.sh', packet, identity)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Checksum mismatch', result.stderr)
        self.assertFalse((self.state / 'docker.log').exists())

    def test_failed_restore_cleans_only_disposable_resources(self):
        packet, identity = self.restore_packet()
        self.env['MOCK_RESTORE_FAIL'] = '1'
        result = self.run_script('restore-check.sh', packet, identity)
        self.assertNotEqual(result.returncode, 0)
        calls = (self.state / 'docker.log').read_text()
        self.assertRegex(calls, r'rm --force homologa-restore-[0-9a-f]{24}')
        self.assertRegex(calls, r'volume rm homologa-restore-[0-9a-f]{24}')
        self.assertNotIn('homologa-solar-prod_pgdata', calls)

    def test_empty_restored_database_is_rejected(self):
        packet, identity = self.restore_packet()
        self.env['MOCK_TABLE_COUNT'] = '0'
        result = self.run_script('restore-check.sh', packet, identity)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('No application tables were restored', result.stderr)

    def test_path_traversal_archive_does_not_touch_docker(self):
        packet, identity = self.restore_packet()
        packet.write_bytes(tgz({'../escape': b'bad'}))
        result = self.run_script('restore-check.sh', packet, identity)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Unsafe or unexpected', result.stderr)
        self.assertFalse((self.state / 'docker.log').exists())
        self.assertFalse((self.base / 'escape').exists())


if __name__ == '__main__':
    unittest.main(verbosity=2)
