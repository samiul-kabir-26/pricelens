import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';

// 1x1 transparent PNG
const PIXEL = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
  'base64',
);

const PRODUCT = `<!doctype html>
<html><head><title>Acme Widget</title></head>
<body>
  <h1>Acme Widget</h1>
  <span class="price" data-testid="price">$19.99</span>
  <img id="hero" src="/img/hero.png" alt="hero">
</body></html>`;

// The price is computed at runtime (cents / 100) so the literal "$42.00" never
// appears in the served source - it can only show up in rendered HTML.
const spa = (delayMs: number, cents: number) => `<!doctype html>
<html><head><title>SPA</title></head>
<body>
  <div id="root"></div>
  <script>
    setTimeout(function () {
      var el = document.createElement('span');
      el.setAttribute('data-testid', 'price');
      el.textContent = '$' + (${cents} / 100).toFixed(2);
      document.getElementById('root').appendChild(el);
    }, ${delayMs});
  </script>
</body></html>`;

/**
 * A page that looks public but pulls from `target` (another origin): a direct
 * <img>, an <img> that 302-redirects there via this origin, a fetch() and a
 * WebSocket. Results are written into the DOM so the rendered HTML shows them.
 */
const ssrfPage = (target: string) => `<!doctype html>
<html><head><title>Totally public shop</title></head>
<body>
  <h1>Public Widget</h1><span data-testid="price">$5.00</span>
  <img id="direct" src="${target}/img/direct.png">
  <img id="via-redirect" src="/to?url=${encodeURIComponent(`${target}/img/redirected.png`)}">
  <p id="fetch">pending</p><p id="ws">pending</p>
  <script>
    fetch(${JSON.stringify(`${target}/api/secrets`)}).then(
      function () { document.getElementById('fetch').textContent = 'fetch-ok'; },
      function () { document.getElementById('fetch').textContent = 'fetch-failed'; });
    var ws = new WebSocket(${JSON.stringify(`${target.replace(/^http/, 'ws')}/socket`)});
    ws.onopen = function () { document.getElementById('ws').textContent = 'ws-open'; };
    ws.onclose = function () { var el = document.getElementById('ws'); if (el.textContent === 'pending') el.textContent = 'ws-closed'; };
  </script>
</body></html>`;

export interface FixtureServer {
  readonly origin: string;
  readonly port: number;
  readonly imageHits: () => number;
  /** Every request (incl. WebSocket upgrades) as "host path". */
  readonly requests: () => string[];
  close(): Promise<void>;
}

/**
 * Static fixture site:
 *  /product     static product page with an image
 *  /spa         price injected by JS after 300 ms
 *  /spa-slow    price injected by JS after 1500 ms
 *  /hang        sends headers + partial body, never finishes loading
 *  /redirect    302 -> /product
 *  /img/*       counts hits (to observe resource blocking)
 *  /to?url=X    302 -> X (any URL)
 *  /ssrf-page?target=ORIGIN   page whose subresources point at ORIGIN
 *  /js-nav?url=X  navigates the main frame to X via script after 50 ms
 */
export async function startFixtureServer(): Promise<FixtureServer> {
  let imageHits = 0;
  const log: string[] = [];
  const server: Server = createServer((req, res) => {
    const parsed = new URL(req.url ?? '/', 'http://fixture');
    const path = parsed.pathname;
    log.push(`${req.headers.host ?? ''} ${parsed.pathname}${parsed.search}`);
    const html = (body: string) => {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' });
      res.end(body);
    };
    if (path === '/product') return html(PRODUCT);
    if (path === '/spa') return html(spa(300, 4200));
    if (path === '/spa-slow') return html(spa(1500, 7750));
    if (path === '/redirect') {
      res.writeHead(302, { location: '/product' });
      return res.end();
    }
    if (path === '/to') {
      res.writeHead(302, { location: parsed.searchParams.get('url') ?? '/product' });
      return res.end();
    }
    if (path === '/js-nav') {
      const to = JSON.stringify(parsed.searchParams.get('url') ?? '/product');
      return html(`<!doctype html><p>moving</p><script>setTimeout(function () { location.href = ${to}; }, 50);</script>`);
    }
    if (path === '/ssrf-page') return html(ssrfPage(parsed.searchParams.get('target') ?? ''));
    if (path === '/api/secrets') {
      res.writeHead(200, { 'content-type': 'application/json', 'access-control-allow-origin': '*' });
      return res.end('{"secret":true}');
    }
    if (path === '/hang') {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
      res.write('<!doctype html><html><body><p>loading forever');
      return; // never end()
    }
    if (path.startsWith('/img/')) {
      imageHits++;
      res.writeHead(200, { 'content-type': 'image/png', 'cache-control': 'no-store' });
      return res.end(PIXEL);
    }
    res.writeHead(404, { 'content-type': 'text/plain' });
    res.end('not found');
  });

  server.on('upgrade', (req, socket) => {
    log.push(`${req.headers.host ?? ''} ${req.url ?? ''} [upgrade]`);
    socket.destroy();
  });

  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address() as AddressInfo;

  return {
    origin: `http://127.0.0.1:${port}`,
    port,
    imageHits: () => imageHits,
    requests: () => [...log],
    close: () =>
      new Promise<void>((resolve) => {
        server.closeAllConnections();
        server.close(() => resolve());
      }),
  };
}
