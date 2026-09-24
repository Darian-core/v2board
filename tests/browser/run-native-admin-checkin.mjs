import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
import nativeAdminCheckinSmoke from './native-admin-checkin.smoke.mjs';

const root = new URL('../../', import.meta.url);
const html = (await readFile(new URL('resources/views/admin.blade.php', root), 'utf8'))
    .replace(/\{\{\$[^}]+\}\}/g, 'test');
const server = createServer(async (request, response) => {
    const path = new URL(request.url, 'http://localhost').pathname;
    if (!path.startsWith('/assets/')) {
        response.writeHead(200, {'Content-Type': 'text/html'});
        return response.end(html);
    }
    try {
        const file = new URL('public' + path, root);
        if (!file.href.startsWith(new URL('public/assets/', root).href)) throw new Error('Invalid asset path');
        const data = await readFile(fileURLToPath(file));
        response.writeHead(200, {'Content-Type': path.endsWith('.js') ? 'application/javascript' : path.endsWith('.css') ? 'text/css' : 'application/octet-stream'});
        response.end(data);
    } catch {
        response.writeHead(404);
        response.end();
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
let browser;
try {
    browser = await chromium.launch();
    const page = await browser.newPage();
    const passed = await nativeAdminCheckinSmoke(page, 'http://127.0.0.1:' + server.address().port);
    if (process.env.CHECKIN_SCREENSHOT) await page.screenshot({path: process.env.CHECKIN_SCREENSHOT, fullPage: true});
    console.log(`${passed.length} native admin check-in browser checks passed.`);
} finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
}
