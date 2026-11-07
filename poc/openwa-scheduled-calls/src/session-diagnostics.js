import { createHash } from 'node:crypto';
import { constants } from 'node:fs';
import { copyFile, lstat, chmod, readFile } from 'node:fs/promises';

function inspectPayloadFormat(contents) {
  if (contents === 'LOGGED OUT') return 'logged-out-marker';

  let value;
  try {
    value = JSON.parse(contents);
  } catch {
    const raw = contents.trim();
    if (!/^[A-Za-z0-9+/]+=*$/.test(raw)) return 'invalid-json';
    value = raw;
  }

  if (typeof value === 'string') {
    try {
      value = JSON.parse(Buffer.from(value, 'base64').toString('utf8'));
    } catch {
      return 'invalid-base64-json';
    }
  }

  if (!value || typeof value !== 'object' || Array.isArray(value)) return 'invalid-payload-shape';
  const keys = Object.keys(value).sort();
  if (keys.join(',') !== 'auth,data'
    || typeof value.auth !== 'string' || !value.auth
    || typeof value.data !== 'string' || !value.data) {
    return 'invalid-payload-shape';
  }

  return 'valid-auth-data-payload';
}

export async function inspectAndBackupSession({ sessionDataPath, encryptionKey, now = Date.now() }) {
  const keyFingerprint = createHash('sha256').update(encryptionKey).digest('hex').slice(0, 16);
  let checkpoint = { exists: false, sizeBytes: null, modifiedAt: null, backupPath: null, payloadFormat: 'missing' };

  try {
    const info = await lstat(sessionDataPath);
    if (!info.isFile() || info.isSymbolicLink() || info.size <= 0 || info.size > 64 * 1024 * 1024) {
      checkpoint = { ...checkpoint, exists: true, sizeBytes: info.size, modifiedAt: info.mtime.toISOString(), invalidFileType: true, payloadFormat: 'not-inspected' };
    } else {
      const backupPath = `${sessionDataPath}.startup-backup-${now}.enc`;
      await copyFile(sessionDataPath, backupPath, constants.COPYFILE_EXCL);
      await chmod(backupPath, 0o600);
      const contents = await readFile(sessionDataPath, 'utf8');
      checkpoint = {
        exists: true,
        sizeBytes: info.size,
        modifiedAt: info.mtime.toISOString(),
        backupPath,
        payloadFormat: inspectPayloadFormat(contents),
      };
    }
  } catch (error) {
    if (error?.code !== 'ENOENT') throw error;
  }

  return { keyFingerprint, checkpoint };
}
