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
page.setDefaultTimeout(45000);

page.on("dialog", async (dialog) => {
  await dialog.accept();
});

function logStep(message) {
  console.log(`[screenshots] ${message}`);
}

async function login() {
  logStep("Opening wp-login.php");
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: "domcontentloaded" });
  await page.fill("#user_login", username);
  await page.fill("#user_pass", password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    page.click("#wp-submit"),
  ]);
  logStep("Logged into wp-admin");
}

async function openPluginPage() {
  logStep("Opening plugin admin page");
  await page.goto(`${baseUrl}/wp-admin/tools.php?page=thumbnail-manager`, {
    waitUntil: "domcontentloaded",
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
  logStep(`Saved ${filename}`);
}

async function checkCheckboxByValue(selector, value) {
  const checkbox = page.locator(`${selector}[value="${value}"]`).first();
  await checkbox.waitFor();
  await checkbox.check();
}

await login();
await openPluginPage();

logStep("Running library analysis");
await page.click("#trpl-run-analysis");
await page.locator("#trpl-analysis-results .trpl-cards").waitFor({ timeout: 120000 });
await screenshotBox("Library Analysis", "screenshot-1.png");

logStep("Preparing delete preview");
const deleteFolder = page.locator('#trpl-delete-form input[name="folders[]"]').first();
await checkCheckboxByValue('#trpl-delete-form input[name="sizes[]"]', '1536x1536');
if (await deleteFolder.count()) {
  await deleteFolder.check();
}
await page.click("#trpl-preview-delete");
await page.locator("#trpl-preview-results .trpl-cards").waitFor({ timeout: 120000 });
await screenshotBox("Preview and Move Thumbnails to Trash", "screenshot-2.png");

logStep("Moving thumbnails to trash");
await page.click("#trpl-start-delete");
await waitForNotice("#trpl-delete-results .notice-success");
await page.reload({ waitUntil: "domcontentloaded" });
await page.locator(".wrap h1", { hasText: "Thumbnail Manager" }).waitFor();
await page.locator("#trpl-trash-table-body tr[data-batch-id]").first().waitFor({ timeout: 120000 });
await screenshotBox("Trash and Restore", "screenshot-3.png");

logStep("Regenerating removed thumbnails");
await page.reload({ waitUntil: "domcontentloaded" });
await page.locator(".wrap h1", { hasText: "Thumbnail Manager" }).waitFor();
const regenFolder = page.locator('#trpl-regenerate-form input[name="regen_folders[]"]').first();
await checkCheckboxByValue('#trpl-regenerate-form input[name="regen_sizes[]"]', '2048x2048');
if (await regenFolder.count()) {
  await regenFolder.check();
}
await page.click('#trpl-regenerate-form button[type="submit"]');
await waitForNotice("#trpl-regenerate-results .notice-success");
await screenshotBox("Regenerate Missing Sizes", "screenshot-4.png");

logStep("Screenshot capture complete");
await browser.close();
