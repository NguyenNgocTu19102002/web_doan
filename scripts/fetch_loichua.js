/*
 Puppeteer script to fetch daily Gospel and reflection and save into MySQL.
 Usage: node fetch_loichua.js
 Configure DB credentials below.
*/

const puppeteer = require('puppeteer');
const mysql = require('mysql2/promise');

// --- CONFIG ---
const DB_CONFIG = {
  host: 'localhost',
  user: 'o0kgbp36f9sl_sang17',
  password: 'Sang17092001',
  database: 'o0kgbp36f9sl_tntt_lap_tri',
};

const SOURCE_URL = 'https://loichuahomnay.vn/'; // change if needed

(async () => {
  let browser;
  try {
    browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox','--disable-setuid-sandbox'] });
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    await page.goto(SOURCE_URL, { waitUntil: 'networkidle2', timeout: 60000 });

    // Try multiple selectors (site structure may vary)
    const selectors = [
      { gospel: '.entry-content .content-gospel', reflection: '.entry-content .content-reflection' },
      { gospel: '.gospel', reflection: '.reflection' },
      { gospel: 'article .gospel', reflection: 'article .reflection' },
    ];

    let gospelHtml = null;
    let reflectionHtml = null;

    for (const s of selectors) {
      try {
        const g = await page.$(s.gospel);
        const r = await page.$(s.reflection);
        if (g) {
          gospelHtml = await page.evaluate(el => el.innerHTML, g);
        }
        if (r) {
          reflectionHtml = await page.evaluate(el => el.innerHTML, r);
        }
        if (gospelHtml || reflectionHtml) break;
      } catch (err) {
        // ignore and try next
      }
    }

    // As fallback, try to extract by heading text
    if (!gospelHtml || !reflectionHtml) {
      const content = await page.$eval('body', el => el.innerText || '');
      // crude fallback: split by known headings
      if (!gospelHtml) {
        const matchG = content.match(/TIN MỪNG[\s\S]*?\n\n/);
        if (matchG) gospelHtml = matchG[0];
      }
      if (!reflectionHtml) {
        const matchR = content.match(/SUY NIỆM[\s\S]*/);
        if (matchR) reflectionHtml = matchR[0];
      }
    }

    // Normalise
    gospelHtml = gospelHtml ? gospelHtml.trim() : '';
    reflectionHtml = reflectionHtml ? reflectionHtml.trim() : '';

    // Connect to DB and upsert
    const conn = await mysql.createConnection(DB_CONFIG);
    const today = new Date().toISOString().slice(0,10);
    const title = '';
    const sql = `INSERT INTO loichua_daily (ngay, title, gospel, reflection, created_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE title=VALUES(title), gospel=VALUES(gospel), reflection=VALUES(reflection), created_at=NOW()`;
    await conn.execute(sql, [today, title, gospelHtml, reflectionHtml]);
    await conn.end();

    console.log('Saved loichua for', today);
  } catch (err) {
    console.error('Error fetching/saving loichua:', err);
    process.exitCode = 2;
  } finally {
    if (browser) await browser.close();
  }
})();
