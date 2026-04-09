import { chromium } from 'playwright-core';
import { createServer } from 'node:http';

const CDP_PORT = process.env.CDP_PORT || 9222;
const SERVER_PORT = process.env.SERVER_PORT || 9223;

async function captureScreenshot(targetUrl) {
    const browser = await chromium.connectOverCDP(`http://127.0.0.1:${CDP_PORT}`);

    try {
        const contexts = browser.contexts();
        let targetPage = null;

        for (const context of contexts) {
            for (const page of context.pages()) {
                if (page.url() === targetUrl || page.url().startsWith(targetUrl.split('?')[0])) {
                    targetPage = page;
                    break;
                }
            }
            if (targetPage) break;
        }

        if (!targetPage) {
            return { error: `No tab found matching: ${targetUrl}`, status: 404 };
        }

        const buffer = await targetPage.screenshot({ type: 'png', fullPage: false });
        return { data: `data:image/png;base64,${buffer.toString('base64')}` };
    } finally {
        await browser.close();
    }
}

const server = createServer(async (req, res) => {
    const url = new URL(req.url, `http://localhost:${SERVER_PORT}`);

    if (url.pathname !== '/screenshot') {
        res.writeHead(404);
        res.end();
        return;
    }

    const targetUrl = url.searchParams.get('url');
    if (!targetUrl) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Missing ?url= parameter' }));
        return;
    }

    try {
        const result = await captureScreenshot(targetUrl);

        if (result.error) {
            res.writeHead(result.status || 500, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: result.error }));
            return;
        }

        res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
        res.end(JSON.stringify({ screenshot: result.data }));
    } catch (error) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: error.message }));
    }
});

server.listen(SERVER_PORT, '0.0.0.0', () => {
    console.log(`Screenshot server listening on http://0.0.0.0:${SERVER_PORT}`);
    console.log(`Connecting to Chrome CDP on localhost:${CDP_PORT}`);
});
