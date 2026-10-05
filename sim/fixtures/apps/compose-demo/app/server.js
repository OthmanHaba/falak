// Falak sim compose demo: a dependency-free HTTP server that keeps state in Redis (RESP over a raw socket).
//   GET /            {app, release, greeting, hits}
//   GET /health      ok (200) — the E2E "broken release" changes HEALTHY to false
//   GET /set?value=x store x in Redis (key "marker")
//   GET /get         {marker}
const http = require('node:http');
const net = require('node:net');

const HEALTHY = true;
const redisHost = process.env.REDIS_HOST || 'redis';

function redis(...args) {
    return new Promise((resolve, reject) => {
        const socket = net.createConnection(6379, redisHost);
        let buffer = '';
        socket.setTimeout(3000, () => socket.destroy(new Error('redis timeout')));
        socket.on('connect', () => socket.write(`*${args.length}\r\n` + args.map((a) => `$${Buffer.byteLength(String(a))}\r\n${a}\r\n`).join('')));
        socket.on('data', (chunk) => {
            buffer += chunk.toString();
            const type = buffer[0];
            const lineEnd = buffer.indexOf('\r\n');
            if (lineEnd < 0) return;
            const head = buffer.slice(1, lineEnd);
            if (type === '+' || type === ':') return socket.end(), resolve(type === ':' ? Number(head) : head);
            if (type === '-') return socket.end(), reject(new Error(head));
            if (type === '$') {
                const length = Number(head);
                if (length < 0) return socket.end(), resolve(null);
                if (buffer.length >= lineEnd + 2 + length + 2) return socket.end(), resolve(buffer.slice(lineEnd + 2, lineEnd + 2 + length));
            }
        });
        socket.on('error', reject);
    });
}

const json = (res, status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
};

http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    res.on('finish', () => console.log(`${req.method} ${url.pathname} ${res.statusCode}`));
    try {
        if (url.pathname === '/health') {
            if (!HEALTHY) return json(res, 500, { ok: false });
            await redis('PING');
            res.writeHead(200, { 'Content-Type': 'text/plain' });
            return res.end('ok');
        }
        if (url.pathname === '/set') {
            await redis('SET', 'marker', url.searchParams.get('value') || '');
            return json(res, 200, { marker: url.searchParams.get('value') });
        }
        if (url.pathname === '/get') return json(res, 200, { marker: await redis('GET', 'marker') });
        const hits = await redis('INCR', 'hits');
        return json(res, 200, { app: 'falak-compose-demo', release: process.env.FALAK_RELEASE_ID || null, greeting: process.env.APP_GREETING, hits });
    } catch (error) {
        console.error('request failed:', error.message);
        return json(res, 500, { error: error.message });
    }
}).listen(Number(process.env.PORT || 8080), () => console.log('falak-compose-demo listening on :' + (process.env.PORT || 8080)));
