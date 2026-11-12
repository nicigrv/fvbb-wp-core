// Browser-Tests: Website ohne JS-Fehler und ohne seitliches Scrollen, Hinweis-Popup und Cookie-Banner,
// Block-Editor (alle Blöcke gültig, Zeitleiste bearbeitbar) und Screenshots (Desktop + Handy) als Artefakt.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const CI = path.join(__dirname, '..', 'ci');
const SHOTS = path.join(__dirname, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });

let failed = 0;
const ok = (m) => console.log('  ok   ' + m);
const fail = (m) => { console.log('  FAIL ' + m); failed++; };
const fileFor = (u, name) => (u.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'start') + '-' + name + '.png';

// Einmal ganz nach unten scrollen, damit alle Bilder (loading="lazy") geladen sind
async function loadAll(page) {
  await page.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 500) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 60)); }
    window.scrollTo(0, 0);
  });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);
}

// Willkommens-Fenster im Editor abschalten
async function noWelcome(page) {
  await page.evaluate(() => {
    try { wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); } catch (e) {}
    try { wp.data.dispatch('core/preferences').set('core', 'enableChoosePatternModal', false); } catch (e) {}
  });
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
}

async function dismiss(page) {
  // Cookie-Banner: „Nur notwendige“, danach den Hinweis schließen (damit nichts die Screenshots verdeckt)
  const nec = page.locator('#fvbb-consent button', { hasText: 'Nur notwendige' });
  if (await nec.count() && await nec.first().isVisible()) { await nec.first().click(); }
  const close = page.locator('#fvbb-notice[open] .fvbb-notice__ok');
  try { await close.waitFor({ state: 'visible', timeout: 1500 }); await close.click(); } catch (e) { /* kein Hinweis offen */ }
  await page.waitForTimeout(250);
}

(async () => {
  const browser = await chromium.launch();
  const urls = fs.readFileSync(path.join(CI, 'urls.txt'), 'utf8').split('\n').filter(Boolean);
  const ids = fs.readFileSync(path.join(CI, 'editor-ids.txt'), 'utf8').trim().split(/\s+/);

  // --- Erster Besuch: Cookie-Banner, danach Hinweis ---
  console.log('== Erster Besuch');
  {
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    (await page.isVisible('#fvbb-consent')) ? ok('Cookie-Banner erscheint') : fail('Cookie-Banner fehlt');
    await page.screenshot({ path: path.join(SHOTS, 'erster-besuch-cookie-banner.png') });
    await page.locator('#fvbb-consent button', { hasText: 'Nur notwendige' }).click();
    const shown = await page.waitForSelector('#fvbb-notice[open]', { timeout: 4000 }).then(() => true).catch(() => false);
    shown ? ok('Danach erscheint der Hinweis „Neuer Newsletter“') : fail('Hinweis erscheint nicht');
    if (shown) {
      const t = await page.textContent('#fvbb-notice-title');
      /Newsletter/.test(t) ? ok('Hinweis-Titel: ' + t.trim()) : fail('Hinweis-Titel: ' + t);
      await page.screenshot({ path: path.join(SHOTS, 'erster-besuch-hinweis.png') });
      await page.click('#fvbb-notice .fvbb-notice__ok');
      await page.reload({ waitUntil: 'networkidle' });
      await page.waitForTimeout(1200);
      (await page.isVisible('#fvbb-notice[open]')) ? fail('Hinweis erscheint nach dem Schließen erneut') : ok('Geschlossener Hinweis bleibt zu');
    }
    await page.close();
  }

  // --- Website ---
  console.log('== Website');
  for (const [name, viewport] of [['desktop', { width: 1400, height: 900 }], ['handy', { width: 390, height: 844 }]]) {
    const ctx = await browser.newContext({ viewport });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
    let first = true;
    for (const u of urls) {
      errors.length = 0;
      await page.goto(BASE + u, { waitUntil: 'networkidle' });
      if (first) { await dismiss(page); await page.reload({ waitUntil: 'networkidle' }); first = false; }
      await loadAll(page);
      await page.screenshot({ path: path.join(SHOTS, fileFor(u, name)), fullPage: true });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (errors.length) fail(`${u} (${name}): JS-Fehler: ${errors.join(' | ')}`); else ok(`${u} (${name}) ohne JS-Fehler`);
      if (overflow > 2) fail(`${u} (${name}): Seite ist ${overflow}px zu breit`);
    }

    // Startseite: Karten ragen ins Kopfbild, Personen-Karten in einer Reihe
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const m = await page.evaluate(() => {
      const hero = document.querySelector('.front-hero').getBoundingClientRect();
      const card = document.querySelector('.fvbb-overlap .is-style-fvbb-card');
      const people = [...document.querySelectorAll('.is-style-fvbb-person')].map(e => e.getBoundingClientRect());
      return { overlap: card ? hero.bottom - card.getBoundingClientRect().top : -1, people: people.length, rows: new Set(people.map(p => Math.round(p.top))).size };
    });
    m.overlap > 20 ? ok(`(${name}) „Wir …“-Karten ragen ${Math.round(m.overlap)}px ins Kopfbild`) : fail(`(${name}) Karten ragen nicht ins Kopfbild (${m.overlap})`);
    m.people === 6 ? ok(`(${name}) 6 Personen-Karten in ${m.rows} Reihe(n)`) : fail(`(${name}) Personen-Karten: ${m.people}`);

    // Mobiles Menü
    if (name === 'handy') {
      await page.click('.nav-toggle');
      await page.waitForTimeout(400);
      const vis = await page.isVisible('.primary-nav .menu-item-cta a');
      vis ? ok('Handy: Menü öffnet, „Jetzt Mitglied werden!“ sichtbar') : fail('Handy: Menü');
      await page.screenshot({ path: path.join(SHOTS, 'menue-handy.png') });
    }
    await ctx.close();
  }

  // --- Block-Editor ---
  console.log('== Block-Editor');
  {
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    await page.goto(BASE + '/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    for (const id of ids) {
      await page.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId(), null, { timeout: 60000 });
      await noWelcome(page);
      await page.waitForTimeout(2000);
      const res = await page.evaluate(() => {
        const bad = []; let total = 0;
        const walk = bs => bs.forEach(b => { total++; if (!b.isValid) bad.push(b.name); walk(b.innerBlocks || []); });
        walk(wp.data.select('core/block-editor').getBlocks());
        return { total, bad, title: wp.data.select('core/editor').getEditedPostAttribute('title') };
      });
      res.bad.length ? fail(`Editor „${res.title}“: ungültige Blöcke ${res.bad.join(', ')}`) : ok(`Editor „${res.title}“: ${res.total} Blöcke gültig`);
      await page.screenshot({ path: path.join(SHOTS, `editor-${id}.png`) });
    }

    // Zeitleiste im Editor bearbeiten und speichern
    {
      const aid = ids[2];
      await page.goto(`${BASE}/wp-admin/post.php?post=${aid}&action=edit`, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => window.wp && wp.data && wp.data.select('core/block-editor').getBlocks().length, null, { timeout: 60000 });
      await noWelcome(page);
      await page.waitForTimeout(1500);
      const canvas = page.frameLocator('iframe[name="editor-canvas"]');
      const hasFrame = await page.locator('iframe[name="editor-canvas"]').count();
      const root = hasFrame ? canvas : page;
      await root.locator('.fvbb-te-head button', { hasText: 'Neuer Eintrag' }).click();
      const firstItem = root.locator('.fvbb-te-item').first();
      await firstItem.locator('input').first().fill('12. November 2026');
      await firstItem.locator('textarea').fill('Neue Testaktion aus dem Editor');
      await page.screenshot({ path: path.join(SHOTS, 'admin-zeitleiste.png'), fullPage: false });
      await page.evaluate(() => wp.data.dispatch('core/editor').savePost());
      await page.waitForFunction(() => !wp.data.select('core/editor').isSavingPost() && !wp.data.select('core/editor').isEditedPostDirty(), null, { timeout: 30000 });
      await page.goto(BASE + '/aktionen/', { waitUntil: 'networkidle' });
      const txt = await page.textContent('.fvbb-timeline');
      /2026[\s\S]*Neue Testaktion aus dem Editor/.test(txt) ? ok('Zeitleiste: neuer Eintrag gespeichert und unter 2026 sichtbar') : fail('Zeitleiste: neuer Eintrag fehlt');
    }

    // Verwaltung
    for (const [u, f] of [['tools.php?page=fvbb-migration', 'admin-umstellung'], ['edit.php?post_type=fvbb_notice', 'admin-hinweise'], ['edit.php?post_type=fvbb_table', 'admin-tabellen'], ['options-general.php?page=fvbb-consent', 'admin-cookie-banner']]) {
      await page.goto(`${BASE}/wp-admin/${u}`, { waitUntil: 'networkidle' });
      const err = await page.locator('#error-page, .wp-die-message').count();
      err ? fail('Verwaltung: ' + u) : ok('Verwaltung öffnet: ' + u);
      await page.screenshot({ path: path.join(SHOTS, f + '.png'), fullPage: true });
    }
    await page.close();
  }

  await browser.close();
  console.log(failed ? `\n${failed} Browser-Prüfungen fehlgeschlagen` : '\nAlle Browser-Prüfungen bestanden');
  process.exit(failed ? 1 : 0);
})();
