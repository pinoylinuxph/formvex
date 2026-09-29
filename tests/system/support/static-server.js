import { createReadStream } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { resolve } from 'node:path';

const repositoryRoot = resolve(import.meta.dirname, '../../..');
const routes = new Map([
  ['/fixture', resolve(repositoryRoot, 'tests/fixtures/client/structural.html')],
  ['/logoslab', resolve(repositoryRoot, 'logoslab/index.html')],
  ['/multi-form', resolve(repositoryRoot, 'tests/fixtures/forms/multi-form.html')],
  ['/spoke-shell', resolve(repositoryRoot, 'tests/fixtures/spoke-admin/shell.html')],
  ['/build/client.js', resolve(repositoryRoot, 'build/client.js')],
  ['/build/formvex-ui.css', resolve(repositoryRoot, 'build/formvex-ui.css')],
  ['/build/platform-ui.js', resolve(repositoryRoot, 'build/platform-ui.js')],
  ['/build/spoke-admin.js', resolve(repositoryRoot, 'build/spoke-admin.js')],
]);

const server = createServer(async (request, response) => {
  const pathname = new URL(request.url ?? '/', 'http://127.0.0.1').pathname;

  if ('/health' === pathname) {
    response.writeHead(204);
    response.end();
    return;
  }

  const file = routes.get(pathname);
  if (undefined === file) {
    response.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    response.end('Not found');
    return;
  }

  try {
    await stat(file);
    const contentType = file.endsWith('.js')
      ? 'text/javascript; charset=utf-8'
      : file.endsWith('.css')
        ? 'text/css; charset=utf-8'
        : 'text/html; charset=utf-8';
    response.writeHead(200, { 'content-type': contentType });
    createReadStream(file).pipe(response);
  } catch {
    response.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    response.end('Not found');
  }
});

server.listen(4174, '127.0.0.1');
