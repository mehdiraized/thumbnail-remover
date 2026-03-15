import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, "..");
const screenshotDir = path.join(rootDir, "assets", "screenshots");

const baseUrl = process.env.WP_BASE_URL || "http://localhost:8888";
const username = process.env.WP_ADMIN_USER || "admin";
const password = process.env.WP_ADMIN_PASSWORD || "password";

fs.mkdirSync(screenshotDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1600, height: 2200 } });

page.on("dialog", async (dialog) => {
  await dialog.accept();
});

async function login() {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: "networkidle" });
  await page.fill("#user_login", username);
  await page.fill("#user_pass", password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle" }),
    page.click("#wp-submit"),
  ]);
}

async function openPluginPage() {
  await page.goto(`${baseUrl}/wp-admin/tools.php?page=thumbnail-manager`, {
    waitUntil: "networkidle",
  });
  await page.locator(".wrap h1", { hasText: "Thumbnail Manager" }).waitFor();
}

async function waitForNotice(selector) {
  await page.waitForFunction((sel) => {
    const node = document.querySelector(sel);
    return Boolean(node && node.textContent && node.textContent.trim().length > 0);
  }, selector);
}

async function screenshotBox(title, filename) {
  const box = page.locator(".wrt-box", {
    has: page.locator("h2", { hasText: title }),
  }).first();
  await box.scrollIntoViewIfNeeded();
  await box.screenshot({ path: path.join(screenshotDir, filename) });
}

await login();
await openPluginPage();

await page.click("#trpl-run-analysis");
await page.locator("#trpl-analysis-results .trpl-cards").waitFor({ timeout: 120000 });
await screenshotBox("Library Analysis", "screenshot-1.png");

const deleteSize = page.locator('#trpl-delete-form input[name="sizes[]"]').first();
const deleteFolder = page.locator('#trpl-delete-form input[name="folders[]"]').first();
await deleteSize.check();
if (await deleteFolder.count()) {
  await deleteFolder.check();
}
await page.click("#trpl-preview-delete");
await page.locator("#trpl-preview-results .trpl-cards").waitFor({ timeout: 120000 });
await screenshotBox("Preview and Move Thumbnails to Trash", "screenshot-2.png");

await page.click("#trpl-start-delete");
await waitForNotice("#trpl-delete-results .notice-success");
await page.locator("#trpl-trash-table-body tr").first().waitFor({ timeout: 120000 });
await screenshotBox("Trash and Restore", "screenshot-3.png");

const regenSize = page.locator('#trpl-regenerate-form input[name="regen_sizes[]"]').first();
const regenFolder = page.locator('#trpl-regenerate-form input[name="regen_folders[]"]').first();
await regenSize.check();
if (await regenFolder.count()) {
  await regenFolder.check();
}
await page.click('#trpl-regenerate-form button[type="submit"]');
await waitForNotice("#trpl-regenerate-results .notice-success");
await screenshotBox("Regenerate Missing Sizes", "screenshot-4.png");

await browser.close();
