/** Real Google SDK rendering checks. No credentials or login/order submission. */
const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const url = process.env.SGL_TEST_URL;
if (!url) throw new Error('Set SGL_TEST_URL to an authorized guest page containing the button.');
const openStep = process.env.SGL_TEST_OPEN_STEP;
const container = process.env.SGL_TEST_CONTAINER || '.sgl-wrap';
const buttonSelector = process.env.SGL_TEST_BUTTON || '.sgl-btn';
const output = path.join(__dirname, '../.artifacts');
const results = [];
async function open(page) {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  if (openStep) await page.getByRole('button', { name: openStep, exact: true }).click();
}
async function ready(page) {
  await page.waitForFunction(selector => document.querySelector(selector)?.dataset.sglReady === 'true', buttonSelector).catch(async error => {
    console.error(await page.locator(buttonSelector).evaluate(host => {
      const rect = e => { const r = e?.getBoundingClientRect(); return r ? { top: r.top, width: r.width, height: r.height } : null; };
      return { data: host.dataset, style: host.getAttribute('style'), host: rect(host), frame: rect(host.querySelector('iframe')), documentReady: document.readyState, fonts: document.fonts.status };
    }));
    throw error;
  });
  await page.evaluate(() => document.fonts.ready);
}
(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
  try {
    for (const width of (process.env.SGL_TEST_WIDTHS || '1440,767,600,390').split(',').map(Number)) {
      const context = await browser.newContext({ viewport: { width, height: 1000 } });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('https://accounts.google.com/gsi/button?**', async route => {
        await new Promise(resolve => setTimeout(resolve, 750));
        await route.continue();
      });
      await page.addInitScript(selector => {
        window.__sglSamples = [];
        function sample() {
          const host = document.querySelector(selector);
          if (!host || !host.offsetWidth) return;
          const box = host.getBoundingClientRect();
          const frame = host.querySelector('iframe');
          const frameBox = frame?.getBoundingClientRect();
          window.__sglSamples.push({ height: box.height, opacity: +getComputedStyle(host).opacity, ready: host.dataset.sglReady === 'true', children: host.children.length, frameHeight: frameBox?.height || 0, frameOffset: frameBox ? frameBox.top - box.top : null });
        }
        new MutationObserver(sample).observe(document, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'data-sgl-ready'] });
        document.addEventListener('DOMContentLoaded', () => {
          const host = document.querySelector(selector);
          if (host) new ResizeObserver(sample).observe(host);
        });
      }, buttonSelector);
      await open(page);
      await ready(page);
      const geometry = await page.locator(buttonSelector).evaluate((host, selector) => {
        const box = host.getBoundingClientRect();
        const parent = host.closest(selector).getBoundingClientRect();
        return { centerError: Math.abs(box.x + box.width / 2 - parent.x - parent.width / 2), height: box.height, width: box.width };
      }, container);
      assert.ok(geometry.centerError < 1, JSON.stringify(geometry));
      assert.equal(geometry.height, 44);
      const samples = await page.evaluate(() => window.__sglSamples);
      assert.ok(samples.length > 0);
      assert.ok(samples.every(s => s.height === 44), 'Button must reserve its height throughout the iframe swap');
      assert.ok(samples.filter(s => s.children && s.opacity > 0).every(s => s.ready && s.frameHeight > 0 && Math.abs(s.frameOffset) <= 1), 'Temporary Google markup must never be visible');
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      if (openStep) {
        await page.locator(buttonSelector + ' iframe').evaluate(frame => frame.dataset.qaIdentity = 'original');
        const back = page.locator('[data-brx-checkout-step].brx-checkout-step--active').getByRole('button', { name: 'Back', exact: true });
        await back.scrollIntoViewIfNeeded();
        await page.waitForTimeout(300);
        await back.click();
        const next = page.getByRole('button', { name: openStep, exact: true });
        await next.scrollIntoViewIfNeeded();
        await page.waitForTimeout(300);
        await next.click();
        await ready(page);
        assert.equal(await page.locator(buttonSelector + ' iframe').getAttribute('data-qa-identity'), 'original', 'Reopening at the same width must reuse the iframe');
      }
      await page.screenshot({ path: path.join(output, 'button-' + width + '.png'), fullPage: true });
      if (width === 1440) {
        await page.setViewportSize({ width: 390, height: 1000 });
        await page.waitForFunction(selector => {
          const host = document.querySelector(selector);
          return host?.dataset.sglReady === 'true' && +host.dataset.sglWidth === Math.max(200, Math.min(400, Math.floor(host.clientWidth)));
        }, buttonSelector);
        assert.ok(await page.locator(buttonSelector).evaluate(host => +host.dataset.sglWidth >= 200 && +host.dataset.sglWidth <= 400));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        results.push({ check: 'responsive-redraw-finishes-at-the-new-width', passed: true });
        for (const [size, height] of Object.entries({ small: 24, medium: 36 })) {
          await page.evaluate(({ size, height }) => {
            const host = document.createElement('div');
            host.className = 'sgl-btn';
            host.id = 'qa-google-' + size;
            host.dataset.size = size;
            host.style.cssText = 'width:300px;height:' + height + 'px';
            document.body.appendChild(host);
          }, { size, height });
          await page.locator('#qa-google-' + size).scrollIntoViewIfNeeded();
          await page.waitForFunction(size => document.getElementById('qa-google-' + size)?.dataset.sglReady === 'true', size).catch(async error => {
            console.error(await page.locator('#qa-google-' + size).evaluate(host => ({ data: host.dataset, height: host.offsetHeight, frame: host.querySelector('iframe')?.getBoundingClientRect().toJSON(), top: host.getBoundingClientRect().top })));
            throw error;
          });
          assert.equal(await page.locator('#qa-google-' + size + ' iframe').evaluate(frame => frame.getBoundingClientRect().height), 44);
          assert.equal(await page.locator('#qa-google-' + size).evaluate(host => host.offsetHeight), height);
        }
        results.push({ check: 'small-and-medium-google-frame-heights', passed: true });
      }
      assert.deepEqual(errors, []);
      results.push({ width, passed: true, checks: ['delayed real iframe', 'no visible intermediate button', 'reserved height', 'centered', 'no horizontal overflow', ...(openStep ? ['same-width step reuses iframe'] : [])] });
      await context.close();
    }
    const blocked = await browser.newContext();
    const page = await blocked.newPage();
    await page.route('https://accounts.google.com/gsi/client', route => route.abort());
    await open(page);
    await page.locator(container + ' .sgl-error').waitFor({ state: 'visible' });
    results.push({ check: 'blocked-sdk-shows-visible-error', passed: true });
    await blocked.close();
    const blockedFrame = await browser.newContext();
    const framePage = await blockedFrame.newPage();
    await framePage.route('https://accounts.google.com/gsi/button?**', route => route.abort());
    await open(framePage);
    await framePage.locator(container + ' .sgl-error').waitFor({ state: 'visible' });
    results.push({ check: 'blocked-button-iframe-shows-visible-error', passed: true });
    await blockedFrame.close();
  } finally {
    fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify(results, null, 2));
    await browser.close();
  }
  console.log(JSON.stringify(results, null, 2));
})().catch(error => { console.error(error); process.exitCode = 1; });
