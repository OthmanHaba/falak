// Run under real Node with `node --import <dist>/register.js` to exercise NodeSDK +
// auto-instrumentation (http server, undici fetch) end-to-end against the fake agent.
import http from 'node:http';

const { shutdown, recordException } = await import(process.env.FALAK_DIST_INDEX);

const server = http.createServer(async (req, res) => {
  if (req.url.startsWith('/downstream')) {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ traceparent: req.headers.traceparent ?? null }));
    return;
  }
  if (req.url.startsWith('/boom')) {
    recordException(new Error('handled in app'));
    res.writeHead(500);
    res.end('err');
    return;
  }
  const { port } = server.address();
  const down = await fetch(`http://127.0.0.1:${port}/downstream?api_key=abc`).then((r) => r.json());
  res.writeHead(200, { 'content-type': 'application/json' });
  res.end(JSON.stringify(down));
});

await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const { port } = server.address();

const body = await fetch(`http://127.0.0.1:${port}/hello?token=t`).then((r) => r.json());
await fetch(`http://127.0.0.1:${port}/boom`);
server.close();
await shutdown();
process.stdout.write(JSON.stringify(body));
