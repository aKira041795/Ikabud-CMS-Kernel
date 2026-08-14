// Daily Ledger PWA — secure-origin browser acceptance runner.
//
// Runs the PWA offline spec through the localhost secure-origin proxy so the
// service-worker control and PBKDF2 offline-PIN paths (both require a
// potentially-trustworthy origin) actually execute instead of self-skipping on
// a plain HTTP vhost.
//
// Usage:  node tests/browser/modules/daily-ledger/pwa/run-pwa-tests.js [playwright args...]
//   (or)  npm run test:pwa -- [playwright args...]
'use strict';

const { spawn, spawnSync } = require('child_process');
const http = require('http');
const path = require('path');

const root = path.resolve(__dirname, '../../../..');
const proxyHost = '127.0.0.1';
const proxyPort = Number(process.env.DL_PWA_PROXY_PORT || 4179);
const proxyUrl = 'http://' + proxyHost + ':' + proxyPort;
const proxyScript = path.join(__dirname, 'local-secure-origin-proxy.js');
const spec = path.join(__dirname, 'offline.spec.js');

const proxy = spawn(process.execPath, [proxyScript], { cwd: root, stdio: 'ignore' });

function waitForProxy(attempt) {
    return new Promise((resolve, reject) => {
        const req = http.get({ host: proxyHost, port: proxyPort, path: '/', timeout: 1200 }, () => {
            req.destroy();
            resolve();
        });
        req.on('error', () => {
            if (attempt >= 25) reject(new Error('secure-origin proxy did not become ready'));
            else setTimeout(() => waitForProxy(attempt + 1).then(resolve, reject), 200);
        });
        req.on('timeout', () => {
            req.destroy();
            if (attempt >= 25) reject(new Error('secure-origin proxy did not become ready'));
            else setTimeout(() => waitForProxy(attempt + 1).then(resolve, reject), 200);
        });
    });
}

waitForProxy(0)
    .then(() => {
        console.log('PWA secure-origin proxy ready at ' + proxyUrl);
        const result = spawnSync('npx', ['playwright', 'test', spec, ...process.argv.slice(2)], {
            cwd: root,
            stdio: 'inherit',
            env: Object.assign({}, process.env, {
                TEST_BASE_URL: proxyUrl,
                APP_URL: proxyUrl,
            }),
        });
        proxy.kill('SIGTERM');
        process.exit(result.status === null ? 1 : result.status);
    })
    .catch((err) => {
        console.error(err.message);
        proxy.kill('SIGTERM');
        process.exit(1);
    });

function shutdown() {
    proxy.kill('SIGTERM');
    process.exit(130);
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
