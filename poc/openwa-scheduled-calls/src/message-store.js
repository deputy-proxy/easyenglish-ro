import { mkdir, readFile, rename, writeFile, chmod, unlink } from 'node:fs/promises';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { decryptJson, encryptJson } from './secure-store.js';

export class EncryptedMessageStore {
  constructor(filePath, key) { this.filePath = filePath; this.key = key; this.records = []; this.writeQueue = Promise.resolve(); }
  async load() {
    await mkdir(path.dirname(this.filePath), { recursive: true, mode: 0o700 });
    try {
      const value = decryptJson(await readFile(this.filePath, 'utf8'), this.key);
      if (!Array.isArray(value)) throw new Error('Invalid message store.');
      let recoveredSubmission = false;
      this.records = value.map((record) => {
        if (record.direction === 'outbound' && record.status === 'sending') {
          recoveredSubmission = true;
          return { ...record, status: 'submission_unconfirmed', recoveredAt: new Date().toISOString() };
        }
        return record;
      });
      if (recoveredSubmission) await this.persist();
    } catch (error) {
      if (error.code === 'ENOENT') return this;
      throw new Error('The encrypted message store cannot be read. Refusing to accept new message requests.');
    }
    return this;
  }
  findByKey(key) { return this.records.find((record) => record.idempotencyKey === key) || null; }
  pendingInbound(now = Date.now()) {
    return this.records.filter((record) => record.direction === 'inbound' && (
      (record.status === 'callback_pending' && (!record.nextAttemptAt || new Date(record.nextAttemptAt).getTime() <= now))
      || (record.status === 'callback_sending' && record.lastAttemptAt && new Date(record.lastAttemptAt).getTime() <= now - 60000)
    ));
  }
  async upsert(record) {
    const operation = async () => {
      const index = this.records.findIndex((item) => item.idempotencyKey === record.idempotencyKey);
      if (index < 0) this.records.push(record); else this.records[index] = record;
      await this.persist();
    };
    const current = this.writeQueue.then(operation);
    this.writeQueue = current.catch(() => {});
    return current;
  }
  async persist() {
    const temporaryPath = this.filePath + '.' + randomUUID() + '.tmp';
    try {
      await writeFile(temporaryPath, encryptJson(this.records, this.key), { flag: 'wx', mode: 0o600 });
      await chmod(temporaryPath, 0o600);
      await rename(temporaryPath, this.filePath);
    } catch (error) {
      await unlink(temporaryPath, { force: true }).catch(() => {});
      throw error;
    }
  }
}
