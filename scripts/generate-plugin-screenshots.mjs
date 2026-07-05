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
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
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

async function openPluginPage(tab = "") {
  const tabQuery = tab ? `&trpl_tab=${tab}` : "";
  logStep(`Opening plugin admin page${tab ? ` (${tab})` : ""}`);
  await page.goto(`${baseUrl}/wp-admin/tools.php?page=thumbnail-manager${tabQuery}`, {
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

async function waitForResult(selector) {
  await page.waitForFunction((sel) => {
    const node = document.querySelector(sel);
    return Boolean(node && node.textContent && node.textContent.trim().length > 0);
  }, selector);
}

async function screenshotAdmin(filename) {
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.locator(".trpl-admin-tabs").waitFor();
  await page.locator(".wrap").screenshot({ path: path.join(screenshotDir, filename) });
  logStep(`Saved ${filename}`);
}

async function screenshotTab(tab, filename) {
  await openPluginPage(tab);
  await screenshotAdmin(filename);
}

async function checkPreferredCheckbox(selector, preferredValues = []) {
  for (const value of preferredValues) {
    const checkbox = page.locator(`${selector}[value="${value}"]`).first();
    if (await checkbox.count()) {
      await checkbox.check();
      return value;
    }
  }

  const checkbox = page.locator(selector).first();
  await checkbox.waitFor();
  await checkbox.check();
  return checkbox.inputValue();
}

await login();
await openPluginPage();

logStep("Running library analysis");
await page.click("#trpl-run-analysis");
await page.locator("#trpl-analysis-results .trpl-cards").waitFor({ timeout: 120000 });
await screenshotAdmin("screenshot-1.png");

await screenshotTab("sizes", "screenshot-2.png");

logStep("Preparing delete preview");
await openPluginPage("cleanup");
const deleteFolder = page.locator('#trpl-delete-form input[name="folders[]"]').first();
await checkPreferredCheckbox('#trpl-delete-form input[name="sizes[]"]', ['thumbnail', 'medium', '1536x1536']);
if (await deleteFolder.count()) {
  await deleteFolder.check();
}
await page.click("#trpl-preview-delete");
await waitForResult("#trpl-preview-results");

const hasPreviewMatches = await page.locator("#trpl-preview-results .trpl-cards").count();
if (hasPreviewMatches) {
  logStep("Moving thumbnails to trash");
  await page.click("#trpl-start-delete");
  await waitForNotice("#trpl-delete-results .notice-success");
} else {
  logStep("Skipping trash move because the preview did not find matching files");
}
await openPluginPage("cleanup");
await page.locator(".wrap h1", { hasText: "Thumbnail Manager" }).waitFor();
await page.locator("#trpl-trash-table-body").waitFor({ timeout: 120000 });
await screenshotAdmin("screenshot-3.png");

logStep("Regenerating removed thumbnails");
await openPluginPage("optimize");
await page.locator(".wrap h1", { hasText: "Thumbnail Manager" }).waitFor();
const regenFolder = page.locator('#trpl-regenerate-form input[name="regen_folders[]"]').first();
await checkPreferredCheckbox('#trpl-regenerate-form input[name="regen_sizes[]"]', ['thumbnail', 'medium', '2048x2048']);
if (await regenFolder.count()) {
  await regenFolder.check();
}
await page.click('#trpl-regenerate-form button[type="submit"]');
await waitForResult("#trpl-regenerate-results");
await screenshotAdmin("screenshot-4.png");

await screenshotTab("backup", "screenshot-5.png");
await screenshotTab("reports", "screenshot-6.png");
await screenshotTab("pro", "screenshot-7.png");

logStep("Screenshot capture complete");
await browser.close();
