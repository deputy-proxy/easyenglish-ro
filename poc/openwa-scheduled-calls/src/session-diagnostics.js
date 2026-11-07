import { createHash } from 'node:crypto';
import { constants } from 'node:fs';
import { copyFile, lstat, chmod } from 'node:fs/promises';

export async function inspectAndBackupSession({ sessionDataPath, encryptionKey, now = Date.now() }) {
  const keyFingerprint = createHash('sha256').update(encryptionKey).digest('hex').slice(0, 16);
  let checkpoint = { exists: false, sizeBytes: null, modifiedAt: null, backupPath: null };

  try {
    const info = await lstat(sessionDataPath);
    if (!info.isFile() || info.isSymbolicLink() || info.size <= 0 || info.size > 64 * 1024 * 1024) {
      checkpoint = { ...checkpoint, exists: true, sizeBytes: info.size, modifiedAt: info.mtime.toISOString(), invalidFileType: true };
    } else {
      const backupPath = `${sessionDataPath}.startup-backup-${now}.enc`;
      await copyFile(sessionDataPath, backupPath, constants.COPYFILE_EXCL);
      await chmod(backupPath, 0o600);
      checkpoint = { exists: true, sizeBytes: info.size, modifiedAt: info.mtime.toISOString(), backupPath };
    }
  } catch (error) {
    if (error?.code !== 'ENOENT') throw error;
  }

  return { keyFingerprint, checkpoint };
}
