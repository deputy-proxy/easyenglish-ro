import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('container entrypoint always runs preflight once and then starts the HTTP server', async () => {
  const script = await readFile(new URL('../docker-entrypoint.sh', import.meta.url), 'utf8');
  assert.match(script, /gosu node node src\/preflight\.js/);
  assert.match(script, /exec gosu node node src\/server\.js/);
  assert.doesNotMatch(script, /exec gosu node ["']?\$@/);
});
