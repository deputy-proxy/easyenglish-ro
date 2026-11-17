import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readlinkSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const packageScript = path.join(repositoryRoot, 'scripts/deploy/package-hostinger-release.sh');
const deployScript = path.join(repositoryRoot, 'scripts/deploy/hostinger-deploy.sh');

function writeFile(root, relativePath, content, mode) {
    const absolutePath = path.join(root, relativePath);
    mkdirSync(path.dirname(absolutePath), { recursive: true });
    writeFileSync(absolutePath, content);
    if (mode) chmodSync(absolutePath, mode);
}

function createReleaseFixture(root) {
    writeFile(root, 'artisan', '#!/usr/bin/env php\n', 0o755);
    writeFile(root, 'composer.json', '{}');
    writeFile(root, 'vendor/autoload.php', '<?php // autoloader');
    writeFile(root, 'bootstrap/app.php', '<?php // bootstrap');
    writeFile(root, 'public/index.php', [
        '<?php',
        "if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) { require $maintenance; }",
        "require __DIR__.'/../vendor/autoload.php';",
        "require_once __DIR__.'/../bootstrap/app.php';",
        '',
    ].join('\n'));
    writeFile(root, 'public/build/manifest.json', '{"resources/js/app.js":{"file":"assets/app.js"}}');
    writeFile(root, 'public/build/assets/app.js', 'console.log("release");');
    writeFile(root, 'app/ReleaseMarker.php', '<?php // release code');
}

test('Hostinger release packaging excludes secrets and persistent runtime state', () => {
    const temporaryRoot = mkdtempSync(path.join(os.tmpdir(), 'easyenglish-package-test-'));
    try {
        const fixtureRoot = path.join(temporaryRoot, 'repository');
        const archivePath = path.join(temporaryRoot, 'release.tar.gz');
        createReleaseFixture(fixtureRoot);
        writeFile(fixtureRoot, '.env', 'APP_KEY=must-not-ship');
        writeFile(fixtureRoot, '.env.example', 'APP_ENV=local');
        writeFile(fixtureRoot, 'storage/logs/laravel.log', 'private log');
        writeFile(fixtureRoot, 'node_modules/example/index.js', 'dev dependency');
        writeFile(fixtureRoot, 'tests/Feature/ExampleTest.php', '<?php');
        writeFile(fixtureRoot, 'poc/openwa-scheduled-calls/package.json', '{}');
        symlinkSync('../storage/app/public', path.join(fixtureRoot, 'public/storage'));

        execFileSync('bash', [packageScript, archivePath, fixtureRoot], { stdio: 'pipe' });
        const entries = execFileSync('tar', ['-tzf', archivePath], { encoding: 'utf8' }).split('\n');
        assert.ok(entries.includes('./artisan'));
        assert.ok(entries.includes('./vendor/autoload.php'));
        assert.ok(entries.includes('./public/build/manifest.json'));
        assert.ok(entries.includes('./app/ReleaseMarker.php'));
        assert.ok(!entries.some((entry) => /(^|\/)\.env($|\.)/.test(entry)));
        assert.ok(!entries.some((entry) => /(^|\/)storage\//.test(entry)));
        assert.ok(!entries.some((entry) => /(^|\/)public\/storage($|\/)/.test(entry)));
        assert.ok(!entries.some((entry) => /(^|\/)(node_modules|tests|poc)\//.test(entry)));
    } finally {
        rmSync(temporaryRoot, { recursive: true, force: true });
    }
});

test('Hostinger deployment preserves .env and storage and adapts public paths', () => {
    const temporaryHome = mkdtempSync(path.join(os.tmpdir(), 'easyenglish-deploy-test-'));
    const appDirectory = path.join(temporaryHome, 'domains/easyenglish.ro/easyenglish-app');
    const publicDirectory = path.join(temporaryHome, 'domains/easyenglish.ro/public_html');
    const sourceDirectory = path.join(temporaryHome, 'release-source');
    const archivePath = path.join(temporaryHome, 'release.tar.gz');
    const binDirectory = path.join(temporaryHome, 'bin');
    const phpLog = path.join(temporaryHome, 'php.log');
    const sha = 'a'.repeat(40);

    try {
        mkdirSync(appDirectory, { recursive: true });
        mkdirSync(publicDirectory, { recursive: true });
        mkdirSync(binDirectory, { recursive: true });
        const productionEnv = 'APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://easyenglish.ro\nAPP_KEY=base64:production-key\nDB_CONNECTION=mysql\nDB_PASSWORD=preserve-me';
        writeFile(appDirectory, '.env', productionEnv);
        writeFile(appDirectory, 'artisan', '#!/usr/bin/env php');
        writeFile(appDirectory, 'vendor/autoload.php', '<?php');
        writeFile(appDirectory, 'storage/logs/preserved.log', 'keep persistent logs');
        writeFile(appDirectory, 'obsolete-old-code.txt', 'remove stale code');
        writeFile(appDirectory, '.git/config', '[core]');
        writeFile(publicDirectory, 'index.php', 'old front controller');
        writeFile(publicDirectory, 'old-placeholder.html', 'remove me');
        writeFile(publicDirectory, '.user.ini', 'memory_limit=512M');
        writeFile(publicDirectory, '.well-known/acme-challenge/token', 'keep certificate validation');
        writeFile(publicDirectory, 'storage/uploads/keep.txt', 'keep public uploads');

        createReleaseFixture(sourceDirectory);
        execFileSync('tar', ['-czf', archivePath, '-C', sourceDirectory, '.']);

        const fakePhp = writeFile(binDirectory, 'php', [
            '#!/usr/bin/env bash',
            'set -eu',
            'if [[ "\${1:-}" == "-r" ]]; then exit 0; fi',
            'printf "%s\\n" "$*" >> "\${PHP_LOG}"',
        ].join('\n'), 0o755);

        const result = spawnSync('bash', [deployScript, archivePath, sha], {
            encoding: 'utf8',
            env: { ...process.env, HOME: temporaryHome, PATH: binDirectory + ':' + process.env.PATH, PHP_LOG: phpLog },
        });

        assert.equal(result.status, 0, result.stdout + '\n' + result.stderr);
        assert.equal(readFileSync(path.join(appDirectory, '.env'), 'utf8'), productionEnv);
        assert.equal(readFileSync(path.join(appDirectory, 'storage/logs/preserved.log'), 'utf8'), 'keep persistent logs');
        assert.ok(existsSync(path.join(appDirectory, '.git/config')));
        assert.ok(existsSync(path.join(appDirectory, 'app/ReleaseMarker.php')));
        assert.ok(!existsSync(path.join(appDirectory, 'obsolete-old-code.txt')));
        assert.ok(existsSync(path.join(publicDirectory, 'build/manifest.json')));
        assert.ok(!existsSync(path.join(publicDirectory, 'old-placeholder.html')));
        assert.equal(readFileSync(path.join(publicDirectory, '.user.ini'), 'utf8'), 'memory_limit=512M');
        assert.equal(readFileSync(path.join(publicDirectory, '.well-known/acme-challenge/token'), 'utf8'), 'keep certificate validation');
        assert.equal(readFileSync(path.join(publicDirectory, 'storage/uploads/keep.txt'), 'utf8'), 'keep public uploads');

        const frontController = readFileSync(path.join(publicDirectory, 'index.php'), 'utf8');
        assert.match(frontController, /\.\.\/easyenglish-app\/storage\/framework\/maintenance\.php/);
        assert.match(frontController, /\.\.\/easyenglish-app\/vendor\/autoload\.php/);
        assert.match(frontController, /\.\.\/easyenglish-app\/bootstrap\/app\.php/);

        const phpCommands = readFileSync(phpLog, 'utf8');
        assert.match(phpCommands, /artisan down --retry=60/);
        assert.match(phpCommands, /artisan optimize:clear/);
        assert.match(phpCommands, /artisan migrate --force/);
        assert.match(phpCommands, /artisan optimize/);
        assert.match(phpCommands, /artisan up/);
        assert.ok(!existsSync(archivePath));

        const backupDirectory = path.join(temporaryHome, '.easyenglish-deploy-backups');
        const backupName = execFileSync('find', [backupDirectory, '-maxdepth', '1', '-name', '*.tar.gz', '-printf', '%f'], { encoding: 'utf8' }).trim();
        const backupEntries = execFileSync('tar', ['-tzf', path.join(backupDirectory, backupName)], { encoding: 'utf8' }).split('\n');
        assert.ok(!backupEntries.some((entry) => /easyenglish-app\/\.env/.test(entry)));
        assert.ok(!backupEntries.some((entry) => /easyenglish-app\/storage\//.test(entry)));
    } finally {
        rmSync(temporaryHome, { recursive: true, force: true });
    }
});

test('Hostinger deployment refuses missing .env without touching the document root', () => {
    const temporaryHome = mkdtempSync(path.join(os.tmpdir(), 'easyenglish-missing-env-test-'));
    try {
        const appDirectory = path.join(temporaryHome, 'domains/easyenglish.ro/easyenglish-app');
        const publicDirectory = path.join(temporaryHome, 'domains/easyenglish.ro/public_html');
        const archivePath = path.join(temporaryHome, 'release.tar.gz');
        const sourceDirectory = path.join(temporaryHome, 'release-source');
        mkdirSync(appDirectory, { recursive: true });
        mkdirSync(publicDirectory, { recursive: true });
        writeFile(appDirectory, 'artisan', '#!/usr/bin/env php');
        writeFile(appDirectory, 'vendor/autoload.php', '<?php');
        writeFile(publicDirectory, 'index.php', 'must remain unchanged');
        createReleaseFixture(sourceDirectory);
        execFileSync('tar', ['-czf', archivePath, '-C', sourceDirectory, '.']);

        const result = spawnSync('bash', [deployScript, archivePath, 'b'.repeat(40)], {
            encoding: 'utf8',
            env: { ...process.env, HOME: temporaryHome },
        });
        assert.notEqual(result.status, 0);
        assert.match(result.stdout + '\n' + result.stderr, /\.env/);
        assert.equal(readFileSync(path.join(publicDirectory, 'index.php'), 'utf8'), 'must remain unchanged');
    } finally {
        rmSync(temporaryHome, { recursive: true, force: true });
    }
});

test('Hostinger deployment retargets the public storage symlink to private app storage', () => {
    const temporaryHome = mkdtempSync(path.join(os.tmpdir(), 'easyenglish-storage-link-test-'));
    const appDirectory = path.join(temporaryHome, 'domains/easyenglish.ro/easyenglish-app');
    const publicDirectory = path.join(temporaryHome, 'domains/easyenglish.ro/public_html');
    const sourceDirectory = path.join(temporaryHome, 'release-source');
    const archivePath = path.join(temporaryHome, 'release.tar.gz');
    const binDirectory = path.join(temporaryHome, 'bin');
    const phpLog = path.join(temporaryHome, 'php.log');

    try {
        mkdirSync(appDirectory, { recursive: true });
        mkdirSync(publicDirectory, { recursive: true });
        mkdirSync(binDirectory, { recursive: true });
        writeFile(appDirectory, '.env', 'APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://easyenglish.ro\nAPP_KEY=base64:production-key\nDB_CONNECTION=mysql');
        writeFile(appDirectory, 'artisan', '#!/usr/bin/env php');
        writeFile(appDirectory, 'vendor/autoload.php', '<?php');
        writeFile(appDirectory, 'storage/app/public/learner-upload.txt', 'persist this upload');
        writeFile(publicDirectory, 'index.php', 'old front controller');
        symlinkSync('../storage/app/public', path.join(publicDirectory, 'storage'));

        createReleaseFixture(sourceDirectory);
        execFileSync('tar', ['-czf', archivePath, '-C', sourceDirectory, '.']);
        writeFile(binDirectory, 'php', [
            '#!/usr/bin/env sh',
            'printf "%s\\n" "$*" >> "$PHP_LOG"',
            'exit 0',
        ].join('\n'), 0o755);

        const result = spawnSync('bash', [deployScript, archivePath, 'c'.repeat(40)], {
            encoding: 'utf8',
            env: { ...process.env, HOME: temporaryHome, PATH: binDirectory + ':' + process.env.PATH, PHP_LOG: phpLog },
        });

        assert.equal(result.status, 0, result.stdout + '\n' + result.stderr);
        assert.equal(readlinkSync(path.join(publicDirectory, 'storage')), '../easyenglish-app/storage/app/public');
        assert.equal(readFileSync(path.join(appDirectory, 'storage/app/public/learner-upload.txt'), 'utf8'), 'persist this upload');
    } finally {
        rmSync(temporaryHome, { recursive: true, force: true });
    }
});
