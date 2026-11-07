import { access, mkdir, open, rm, stat, chmod } from 'node:fs/promises';
import { constants } from 'node:fs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import { readConfig } from './config.js';

const execFileAsync = promisify(execFile);
async function executable(command) {
  try {
    const result = await execFileAsync('sh', ['-lc', 'command -v ' + command]);
    return result.stdout.trim() || null;
  } catch {
    return null;
  }
}
async function checkBrowser(binary) {
  try {
    const args = ['--headless', '--disable-gpu', ...(process.env.OPENWA_CHROME_NO_SANDBOX === 'true' ? ['--no-sandbox', '--disable-setuid-sandbox'] : []), '--dump-dom', 'about:blank'];
    const result = await execFileAsync(binary, args, { timeout: 20000, maxBuffer: 1024 * 1024 });
    return result.stdout.includes('<html') || result.stdout.includes('<HTML');
  } catch (error) {
    console.error('Browser launch failed: ' + error.message.split('\n')[0]);
    return false;
  }
}
async function checkPrivateDirectory(directory, label) {
  await mkdir(directory, { recursive: true, mode: 0o700 });
  await chmod(directory, 0o700);
  await access(directory, constants.R_OK | constants.W_OK);
  const details = await stat(directory);
  if (!details.isDirectory()) throw new Error(label + ' path is not a directory.');
  const probe = path.join(directory, '.preflight-' + process.pid);
  const handle = await open(probe, 'wx', 0o600);
  await handle.close();
  await rm(probe, { force: true });
  console.log(label + ': writable with private directory permissions');
}

async function main() {
  const failures = [];
  const version = process.versions.node.split('.').map(Number);
  console.log('Node.js: ' + process.version);
  if (version[0] < 22 || (version[0] === 22 && (version[1] < 21 || (version[1] === 21 && version[2] < 1)))) failures.push('Node.js 22.21.1 or newer is required.');

  let config;
  try {
    config = readConfig();
    console.log('HTTPS pairing URL: configured');
    console.log('Service-to-service authentication token: ' + (config.serviceToken ? 'configured' : 'not configured; API remains disabled'));
    console.log('Session encryption key: valid 32-byte base64 key');
  } catch (error) {
    failures.push(error.message);
  }

  const zstd = await executable('zstd');
  console.log('zstd: ' + (zstd || 'not found'));
  if (!zstd) failures.push('Install zstd and make it available on PATH.');

  const chrome = (config && config.chromeExecutable) || await executable('google-chrome') || await executable('chromium') || await executable('chromium-browser') || await executable('chrome');
  console.log('Chrome/Chromium: ' + (chrome || 'not found'));
  console.log('Chrome sandbox: ' + (process.env.OPENWA_CHROME_NO_SANDBOX === 'true' ? 'disabled for restricted container runtime' : 'enabled'));
  if (!chrome) failures.push('Install Chrome/Chromium or set OPENWA_CHROME_EXECUTABLE to its absolute path.');
  else if (!(await checkBrowser(chrome))) failures.push('Chrome/Chromium could not launch headlessly with its current sandbox configuration.');

  if (config) {
    for (const [directory, label] of [[config.sessionDir, 'Compact session-data directory'], [config.sessionKeyDir, 'Encryption-key directory']]) {
      try { await checkPrivateDirectory(directory, label); }
      catch (error) { failures.push(label + ' check failed: ' + error.message); }
    }
  }

  if (failures.length) {
    console.error('\nPREFLIGHT FAILED');
    failures.forEach((failure) => console.error('- ' + failure));
    process.exitCode = 1;
    return;
  }
  console.log('\nPREFLIGHT PASSED. Pairing remains disabled until npm audit findings are reviewed and OPENWA_ALLOW_LIVE_TEST=true is explicitly set.');
}
main().catch((error) => {
  console.error('Preflight failed: ' + error.message);
  process.exitCode = 1;
});
