import { mkdir, readFile, rename, writeFile, chmod, unlink } from 'node:fs/promises';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { decryptJson, encryptJson } from './secure-store.js';
export class EncryptedEventStore {
  constructor(filePath, key) { this.filePath = filePath; this.key = key; this.records = []; this.writeQueue = Promise.resolve(); }
  async load() {
    await mkdir(path.dirname(this.filePath), { recursive: true, mode: 0o700 });
    try { const value = decryptJson(await readFile(this.filePath, 'utf8'), this.key); if (!Array.isArray(value)) throw new Error('Invalid event store.'); this.records = value; }
    catch (error) { if (error.code === 'ENOENT') return this; throw new Error('The encrypted call-event store cannot be read. Refusing to accept new event requests.'); }
    return this;
  }
  all() { return this.records; }
  findById(id) { return this.records.find((record) => record.providerEventId === id) || null; }
  async upsert(record) {
    const operation = async () => { const index = this.records.findIndex((item) => item.idempotencyKey === record.idempotencyKey); if (index < 0) this.records.push(record); else this.records[index] = record; await this.persist(); };
    const current = this.writeQueue.then(operation);
    this.writeQueue = current.catch(() => {});
    return current;
  }
  async persist() {
    const temporaryPath = this.filePath + '.' + randomUUID() + '.tmp';
    try { await writeFile(temporaryPath, encryptJson(this.records, this.key), { flag: 'wx', mode: 0o600 }); await chmod(temporaryPath, 0o600); await rename(temporaryPath, this.filePath); }
    catch (error) { await unlink(temporaryPath).catch(() => {}); throw error; }
  }
}
