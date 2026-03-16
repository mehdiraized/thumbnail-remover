import fs from "node:fs";
import path from "node:path";
import { execSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, "..");
const outputDir = path.join(rootDir, "output", "screenshots");
const fixtureDir = path.join(outputDir, "fixtures");
const wpEnvTemplate = path.join(rootDir, "wp-env-screenshots.json");
const wpEnvConfig = path.join(rootDir, ".wp-env.json");
const pluginSlug = process.env.PLUGIN_SLUG || "thumbnail-remover";
const pluginPath = `/var/www/html/wp-content/plugins/${pluginSlug}`;

function run(command) {
  execSync(command, {
    cwd: rootDir,
    stdio: "inherit",
    env: process.env,
  });
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\\''`)}'`;
}

async function createFixture(browser, filename, title, subtitle, background, accent) {
  const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
  await page.setContent(
    `
      <style>
        body {
          margin: 0;
          background: rgb(${background.join(",")});
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .frame {
          box-sizing: border-box;
          width: 100%;
          height: 100vh;
          padding: 80px;
        }
        .card {
          box-sizing: border-box;
          width: 100%;
          height: 100%;
          border-radius: 32px;
          background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.05));
          border: 1px solid rgba(255,255,255,0.25);
          color: white;
          padding: 56px;
          position: relative;
          overflow: hidden;
        }
        h1 {
          margin: 0 0 12px;
          font-size: 54px;
          line-height: 1.1;
        }
        p {
          margin: 0;
          font-size: 24px;
          opacity: 0.92;
        }
        .rail {
          position: absolute;
          right: 56px;
          top: 56px;
          width: 420px;
          display: grid;
          gap: 18px;
        }
        .pill {
          height: 72px;
          border-radius: 18px;
          background: rgba(${accent.join(",")}, 0.88);
        }
        .bars {
          position: absolute;
          left: 56px;
          right: 540px;
          bottom: 56px;
          display: grid;
          grid-template-columns: repeat(6, 1fr);
          gap: 18px;
          align-items: end;
          height: 360px;
        }
        .bar {
          border-radius: 16px 16px 0 0;
          background: rgba(${accent.join(",")}, 0.94);
        }
      </style>
      <div class="frame">
        <div class="card">
          <h1>${title}</h1>
          <p>${subtitle}</p>
          <div class="rail">
            <div class="pill"></div>
            <div class="pill" style="opacity:0.85"></div>
            <div class="pill" style="opacity:0.7"></div>
            <div class="pill" style="opacity:0.55"></div>
          </div>
          <div class="bars">
            <div class="bar" style="height:220px"></div>
            <div class="bar" style="height:300px"></div>
            <div class="bar" style="height:260px"></div>
            <div class="bar" style="height:340px"></div>
            <div class="bar" style="height:280px"></div>
            <div class="bar" style="height:320px"></div>
          </div>
        </div>
      </div>
    `,
    { waitUntil: "load" }
  );

  await page.screenshot({
    path: path.join(fixtureDir, filename),
    type: "png",
  });
  await page.close();
}

async function generateFixtures() {
  fs.mkdirSync(fixtureDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });

  await createFixture(
    browser,
    "fixture-1.png",
    "Media Audit",
    "Fixture for the library analysis screenshot",
    [26, 81, 170],
    [242, 201, 76]
  );

  await createFixture(
    browser,
    "fixture-2.png",
    "Cleanup Preview",
    "Fixture for the trash workflow screenshot",
    [32, 118, 74],
    [255, 255, 255]
  );

  await createFixture(
    browser,
    "fixture-3.png",
    "Regeneration Demo",
    "Fixture for the regenerate workflow screenshot",
    [149, 46, 46],
    [255, 220, 120]
  );

  await browser.close();
}

await generateFixtures();
fs.copyFileSync(wpEnvTemplate, wpEnvConfig);

run("npx wp-env start --update");

const setupScript = `
set -e
wp plugin activate '${pluginSlug}'
wp option update blogname 'Thumbnail Remover Demo'
wp option update blogdescription 'Automated screenshot environment'

attachment_ids=$(wp post list --post_type=attachment --format=ids)
if [ -n "$attachment_ids" ]; then
  wp post delete $attachment_ids --force
fi

imported_ids=$(wp media import ${pluginPath}/output/screenshots/fixtures/*.png --porcelain)
set -- $imported_ids
first_id=\${1:-}
second_id=\${2:-}

demo_post_id=$(wp post create --post_type=post --post_status=publish --post_title='Thumbnail Manager Demo Post' --post_content='This post is generated automatically for release screenshots.' --porcelain)

if [ -n "$first_id" ]; then
  wp post meta update $demo_post_id _thumbnail_id $first_id
fi

if [ -n "$second_id" ]; then
  second_url=$(wp post get $second_id --field=guid)
  wp post update $demo_post_id --post_content="<p>Automated screenshot content.</p><p><img src='$second_url' alt='Demo image'></p>"
fi
`;

run(`npx wp-env run cli --env-cwd=wp-content/plugins/${pluginSlug} -- sh -lc ${shellQuote(setupScript)}`);
