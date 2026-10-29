// Browser regression using the actual Hogan engine bundled by GoAccess.
// Usage: node tests/browser.cjs /path/to/php /path/to/goaccess/resources/js/hogan.min.js
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'goaccess-browser-'));
    let browser;
    try {
        const fixture = path.join(temporary, 'report.html');
        const hogan = fs.readFileSync(process.argv[3], 'utf8');
        fs.writeFileSync(fixture, `<p id="status">No authentication provided.</p>
<script>${hogan}</script>
<script>
document.getElementById('status').textContent = Hogan.compile('Traffic: {{count}}').render({count: 42});
try { parent.document.body.dataset.reportEscaped = 'yes'; } catch (error) {}
</script>`);
        const linuxPath = fixture.replaceAll('\\', '/').replace(/^[A-Za-z]:/, '');
        const report = execFileSync(process.argv[2], ['-r',
            'require $argv[1]; echo \\App\\Plugins\\GoAccess\\Support\\Report::document($argv[2]);',
            path.resolve(__dirname, '../GoAccess/Support/Report.php'), linuxPath], {encoding: 'utf8'});
        browser = await chromium.launch(process.env.GOACCESS_BROWSER_CHANNEL ? {channel: process.env.GOACCESS_BROWSER_CHANNEL} : {});
        const page = await browser.newPage();
        const errors = [];
        const requests = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
        const escaped = report.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
        await page.setContent(`<iframe sandbox="allow-scripts" srcdoc="${escaped}"></iframe>`);
        const frame = page.frameLocator('iframe');
        await frame.locator('#status').waitFor();
        const status = await frame.locator('#status').textContent();
        if (status !== 'Traffic: 42') {
            throw Error(`GoAccess template rendering failed: ${status}; browser errors: ${errors.join('; ')}`);
        }
        if (await page.evaluate(() => document.body.dataset.reportEscaped)) {
            throw Error('Report escaped its sandbox');
        }
        if (await page.locator('iframe').getAttribute('sandbox') !== 'allow-scripts') {
            throw Error('Unexpected sandbox privileges');
        }
        const reportFrame = page.frames().find(frame => frame !== page.mainFrame());
        const blocked = await reportFrame.evaluate(async () => {
            try { await fetch('https://example.invalid/report-data'); return false; }
            catch (error) { return true; }
        });
        if (!blocked || requests.length) throw Error('Report network request was not blocked by CSP');
        if (errors.length) throw Error(`Unexpected browser errors: ${errors.join('; ')}`);
        console.log('PASS: GoAccess Hogan template renders inside sandbox; parent DOM remains inaccessible.');
    } finally {
        if (browser) await browser.close();
        fs.rmSync(temporary, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
