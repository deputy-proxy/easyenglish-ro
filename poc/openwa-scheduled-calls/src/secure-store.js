import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';

export function encryptJson(value, key) {
  if (!Buffer.isBuffer(key) || key.length !== 32) throw new Error('A 32-byte encryption key is required.');
  const iv = randomBytes(12);
  const cipher = createCipheriv('aes-256-gcm', key, iv);
  const encrypted = Buffer.concat([cipher.update(JSON.stringify(value), 'utf8'), cipher.final()]);
  return JSON.stringify({
    version: 1,
    iv: iv.toString('base64'),
    tag: cipher.getAuthTag().toString('base64'),
    data: encrypted.toString('base64'),
  });
}

export function decryptJson(payload, key) {
  if (!Buffer.isBuffer(key) || key.length !== 32) throw new Error('A 32-byte encryption key is required.');
  const envelope = JSON.parse(payload);
  if (envelope.version !== 1) throw new Error('Unsupported encrypted record version.');
  const decipher = createDecipheriv('aes-256-gcm', key, Buffer.from(envelope.iv, 'base64'));
  decipher.setAuthTag(Buffer.from(envelope.tag, 'base64'));
  const decrypted = Buffer.concat([
    decipher.update(Buffer.from(envelope.data, 'base64')),
    decipher.final(),
  ]);
  return JSON.parse(decrypted.toString('utf8'));
}
