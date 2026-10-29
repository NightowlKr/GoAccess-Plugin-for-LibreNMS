// Browser regression using the actual Hogan engine bundled by GoAccess.
// Usage: node tests/browser.cjs /path/to/php /path/to/goaccess/resources/js/hogan.min.js /path/to/goaccess/resources/tpls.html
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
        const templates = fs.readFileSync(process.argv[4], 'utf8');
        const menuTemplate = templates.match(/<script id="tpl-nav-menu" type="text\/template">([\s\S]*?)<\/script>/)[1];
        fs.writeFileSync(fixture, `<style>.toggle-panel, nav i { display:inline-block; width:18px; height:18px; }</style><p id="status">No authentication provided.</p>
<nav style="position:fixed;top:0;right:0;background:white;z-index:2"></nav>
<section id="requests" style="margin-top:2000px;height:100px">Requests panel</section>
<script>${hogan}</script>
<script>
document.getElementById('status').textContent = Hogan.compile('Traffic: {{count}}').render({count: 42});
document.querySelector('nav').innerHTML = Hogan.compile(${JSON.stringify(menuTemplate)}).render({labels:{panels:'PANELS',thead:'Overall',toggle_panel:'Toggle panel'},nav:[{key:'requests',head:'Requests',icon:'file'}]});
document.querySelector('.toggle-panel[data-panel="requests"]').onclick = function(event) { event.stopPropagation(); this.classList.toggle('active'); };
try { parent.document.body.dataset.reportEscaped = 'yes'; } catch (error) {}
</script>`);
        const linuxPath = fixture.replaceAll('\\', '/').replace(/^[A-Za-z]:/, '');
        const report = execFileSync(process.argv[2], ['-r',
            'require $argv[1]; echo \\App\\Plugins\\GoAccess\\Support\\Report::document($argv[2]);',
            path.resolve(__dirname, '../GoAccess/Support/Report.php'), linuxPath], {encoding: 'utf8'});
        browser = await chromium.launch(process.env.GOACCESS_BROWSER_CHANNEL ? {channel: process.env.GOACCESS_BROWSER_CHANNEL} : {});
        const page = await browser.newPage();
        page.setDefaultTimeout(5000);
        const errors = [];
        const requests = [];
        page.on('pageerror', error => errors.push(error.message));
        const escaped = report.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
        const hostUrl = 'https://librenms.test/plugin/GoAccess';
        await page.route('**/*', route => {
            if (route.request().frame() === page.mainFrame() && route.request().url() === hostUrl) {
                return route.fulfill({contentType:'text/html',body:`<iframe style="width:100%;height:400px" sandbox="allow-scripts" srcdoc="${escaped}"></iframe>`});
            }
            requests.push(route.request().url());
            return route.abort();
        });
        await page.goto(hostUrl);
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
        await frame.locator('.toggle-panel[data-panel="requests"]').click();
        if (!(await frame.locator('.toggle-panel[data-panel="requests"]').getAttribute('class')).includes('active')) {
            throw Error('Panel toggle handler stopped working');
        }
        await frame.locator('a[href="#requests"] i').click();
        if (requests.length) throw Error(`Panel link triggered authenticated navigation: ${requests.join(', ')}`);
        const top = await frame.locator('#requests').evaluate(element => element.getBoundingClientRect().top);
        if (top < -1 || top >= 400) throw Error('Panel link did not scroll within the report');
        await frame.locator('a[href="#"]').click();
        const scrollTop = await frame.locator('body').evaluate(() => window.scrollY);
        if (scrollTop !== 0 || requests.length || page.url() !== hostUrl) throw Error('Overall link did not stay within report');
        const reportFrame = page.frames().find(frame => frame !== page.mainFrame());
        const blocked = await reportFrame.evaluate(async () => {
            try { await fetch('https://example.invalid/report-data'); return false; }
            catch (error) { return true; }
        });
        if (!blocked || requests.length) throw Error('Report network request was not blocked by CSP');
        if (errors.length) throw Error(`Unexpected browser errors: ${errors.join('; ')}`);
        console.log('PASS: GoAccess templates, panel toggles and fragment scrolling work; navigation, parent DOM and network remain isolated.');
    } finally {
        if (browser) await browser.close();
        fs.rmSync(temporary, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
