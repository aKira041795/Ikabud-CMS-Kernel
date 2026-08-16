// Localhost is a potentially trustworthy origin for service-worker testing.
// This proxy preserves the application's required vhost while exposing it at
// http://127.0.0.1:4179. It is test-only and never runs in production.
const http = require('http');

const listenHost = '127.0.0.1';
const listenPort = Number(process.env.DL_PWA_PROXY_PORT || 4179);
const upstreamHost = process.env.DL_PWA_UPSTREAM_HOST || 'baronledger.test';
const upstreamPort = Number(process.env.DL_PWA_UPSTREAM_PORT || 80);

const server = http.createServer((request, response) => {
    const headers = { ...request.headers, host: upstreamHost };
    const upstream = http.request({
        hostname: '127.0.0.1',
        port: upstreamPort,
        method: request.method,
        path: request.url,
        headers
    }, upstreamResponse => {
        const responseHeaders = { ...upstreamResponse.headers };
        if (responseHeaders.location) {
            responseHeaders.location = responseHeaders.location
                .replace('http://' + upstreamHost, 'http://' + listenHost + ':' + listenPort)
                .replace('https://' + upstreamHost, 'http://' + listenHost + ':' + listenPort);
        }
        if (Array.isArray(responseHeaders['set-cookie'])) {
            responseHeaders['set-cookie'] = responseHeaders['set-cookie'].map(cookie =>
                cookie.replace(/;\s*Domain=[^;]+/ig, '')
            );
        }
        response.writeHead(upstreamResponse.statusCode || 502, responseHeaders);
        upstreamResponse.pipe(response);
    });
    upstream.on('error', error => {
        response.writeHead(502, { 'Content-Type': 'text/plain' });
        response.end('Daily Ledger test proxy error: ' + error.message);
    });
    request.pipe(upstream);
});

server.listen(listenPort, listenHost, () => {
    process.stdout.write('Daily Ledger secure-origin proxy listening on http://' + listenHost + ':' + listenPort + '\n');
});

function shutdown() {
    server.close(() => process.exit(0));
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
