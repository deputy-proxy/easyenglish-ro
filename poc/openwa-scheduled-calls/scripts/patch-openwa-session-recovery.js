import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
const entryPath = require.resolve('@open-wa/core');
const packageRoot = path.resolve(path.dirname(entryPath), '..');
const packageInfo = JSON.parse(await readFile(path.join(packageRoot, 'package.json'), 'utf8'));
if (packageInfo.version !== '5.10.0') {
  throw new Error(`Review the OpenWA session recovery patch for @open-wa/core ${packageInfo.version} before installing.`);
}

const original = `if (this.portable.hasCheckpoint) {
				await this.resetSessionForPairing(portableFailure);
				await this.navigate();
				return true;
			}`;
const safe = `if (this.portable.hasCheckpoint) {
				// Preserve the only durable credentials. Pairing reset is an explicit operator action.
				throw new Error("SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED");
			}`;

for (const filename of ['index.cjs', 'index.mjs']) {
  const target = path.join(packageRoot, 'dist', filename);
  const source = await readFile(target, 'utf8');
  if (source.includes(safe)) continue;
  if (!source.includes(original)) throw new Error(`Expected OpenWA recovery branch not found in ${filename}; refusing an unsafe install.`);
  await writeFile(target, source.replace(original, safe), 'utf8');
}
console.log('Applied OpenWA checkpoint-preserving startup recovery guard.');
