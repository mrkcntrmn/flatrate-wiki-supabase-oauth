import { expect, test } from "@playwright/test";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const harnessDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const seed = JSON.parse(readFileSync(join(harnessDir, ".work/seed.json"), "utf8"));

function attachErrorCollectors(page) {
  const pageErrors = [];
  const consoleErrors = [];
  page.on("pageerror", (error) => {
    pageErrors.push(String(error.message || error));
  });
  page.on("console", (msg) => {
    if (msg.type() === "error") {
      const text = msg.text();
      if (/flatrate|member-display|member-dashboard|wiki-supabase-oauth/i.test(text)) {
        consoleErrors.push(text);
      }
    }
  });
  return {
    pageErrors,
    consoleErrors,
    assertClean() {
      expect(pageErrors, `JS_PAGEERROR_COUNT=${pageErrors.length}`).toEqual([]);
      expect(consoleErrors).toEqual([]);
    },
  };
}

async function login(page, token) {
  await page.context().addCookies([
    {
      name: seed.cookieName || "flarum_remember",
      value: token,
      url: process.env.FLARUM_BASE_URL || "http://127.0.0.1:8080",
    },
  ]);
}

test("forum homepage SPA boots without extend crash", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  const response = await page.goto("/", { waitUntil: "networkidle" });
  expect(response && response.status()).toBe(200);
  await expect(page.locator("#app")).toBeVisible();
  try {
    await expect(page.locator(".IndexPage")).toBeVisible();
  } catch (err) {
    const bodyText = await page.locator("body").innerText();
    const htmlSnippet = (await page.content()).slice(0, 4000);
    console.error(`SPA_BOOT_PAGEERRORS=${JSON.stringify(errors.pageErrors)}`);
    console.error(`SPA_BOOT_CONSOLEERRORS=${JSON.stringify(errors.consoleErrors)}`);
    console.error(`SPA_BOOT_BODY_TEXT=${JSON.stringify(bodyText.slice(0, 2000))}`);
    console.error(`SPA_BOOT_HTML_SNIPPET=${JSON.stringify(htmlSnippet)}`);
    throw err;
  }
  await expect(page.locator("#flarum-loading-error")).toBeHidden();
  await expect(page.locator("body")).not.toContainText("reading 'extend'");
  errors.assertClean();
});

test("authenticated settings page renders Community identity", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  await login(page, seed.grandfatheredToken);
  await page.goto("/settings", { waitUntil: "networkidle" });
  await expect(page.locator(".SettingsPage")).toBeVisible();
  await expect(page.locator(".FlatRateMemberDisplay")).toBeVisible();
  await expect(page.locator(".FlatRateMemberDisplay-heading")).toHaveText("Community identity");
  await expect(page.locator(".FlatRateMemberDisplay-number")).toHaveText(
    `Member #${seed.grandfatheredUserId}`,
  );
  await expect(
    page.locator(".FlatRateMemberDisplay").getByText(seed.grandfatheredMemberNickname, { exact: true }),
  ).toBeVisible();
  await expect(
    page.locator(".FlatRateMemberDisplay").getByText("Custom nickname", { exact: true }),
  ).toBeVisible();
  await expect(page.locator("#flarum-loading-error")).toBeHidden();
  errors.assertClean();
});

test("grandfathered user can switch to member mode and restore custom", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  const api = [];
  page.on("response", (response) => {
    if (response.url().includes("/api/flatrate/member-display")) {
      api.push(response.status());
    }
  });
  await login(page, seed.grandfatheredToken);
  await page.goto("/settings", { waitUntil: "networkidle" });
  await expect(page.locator(".FlatRateMemberDisplay")).toBeVisible();
  await page.locator('input[name="flatrate-member-display-mode"]').first().click({ force: true });
  await expect.poll(() => api.at(-1)).toBe(200);
  await expect(page.locator(".FlatRateMemberDisplay-option").first()).toHaveClass(/--active/);
  const afterMember = await page.evaluate(() => ({
    mode: app.session.user.attribute("flatRateNicknameMode"),
    nickname: app.session.user.attribute("nickname"),
  }));
  expect(afterMember.mode).toBe("member_number");
  expect(afterMember.nickname).toBe(seed.grandfatheredMemberNickname);
  await page.locator('input[name="flatrate-member-display-mode"]').nth(1).click({ force: true });
  await expect.poll(() => api.at(-1)).toBe(200);
  await expect(page.locator(".FlatRateMemberDisplay-option").nth(1)).toHaveClass(/--active/);
  await expect(page.locator(".FlatRateMemberDisplay")).toContainText(seed.grandfatheredNickname);
  const me = await page.evaluate(async () => {
    const res = await fetch("/api/users/" + app.session.user.id(), { credentials: "same-origin" });
    const body = await res.json();
    return {
      id: body.data.id,
      username: body.data.attributes.username,
      memberNumber: body.data.attributes.flatRateMemberNumber,
    };
  });
  expect(me.username).toBe(seed.grandfatheredUsername);
  expect(Number(me.memberNumber)).toBe(seed.grandfatheredUserId);
  errors.assertClean();
});

test("new member has empty custom draft and can save DieselDave", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  const api = [];
  page.on("response", (response) => {
    if (response.url().includes("/api/flatrate/member-display")) {
      api.push(response.status());
    }
  });
  await login(page, seed.newToken);
  await page.goto("/settings", { waitUntil: "networkidle" });
  await expect(
    page.locator(".FlatRateMemberDisplay").getByText(seed.newNickname, { exact: true }),
  ).toBeVisible();
  const customRadio = page.locator('input[name="flatrate-member-display-mode"]').nth(1);
  await expect(customRadio).toBeDisabled();
  const draft = page.locator(".FlatRateMemberDisplay-customEditor input");
  await expect(draft).toHaveValue("");
  await draft.fill("DieselDave");
  await page.getByRole("button", { name: "Save" }).click();
  await expect.poll(() => api.at(-1)).toBe(200);
  await expect(page.locator("body")).toContainText("DieselDave");
  errors.assertClean();
});

test("discussion and navigation still boot with member-display enabled", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  await login(page, seed.grandfatheredToken);
  await page.goto(`/d/${seed.discussionId}-${seed.discussionSlug}`, { waitUntil: "networkidle" });
  await expect(page.locator(".DiscussionPage")).toBeVisible();
  await expect(page.locator(".PostStream")).toBeVisible();
  await expect(page.locator("body")).toContainText("Harness post body");
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/", { waitUntil: "networkidle" });
  await expect(page.locator("#app")).toBeVisible();
  errors.assertClean();
});

test("guest public profile shows Member # without owner dashboard DTO", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  const response = await page.goto(`/u/${seed.grandfatheredUsername}`, { waitUntil: "networkidle" });
  expect(response && response.status()).toBe(200);
  await expect(page.locator(".UserPage")).toBeVisible();
  await expect(page.locator(".FlatRateMemberNumber")).toHaveText(`Member #${seed.grandfatheredUserId}`);
  await expect(page.locator(".FlatRateOwnerDashboard")).toHaveCount(0);
  const bootAttrs = await page.evaluate(() =>
    app.store.all("users").map((user) => user.data && user.data.attributes),
  );
  for (const attrs of bootAttrs) {
    expect(attrs && attrs.flatRateOwnerDashboard).toBeUndefined();
  }
  const api = await page.request.get(`/api/users/${seed.grandfatheredUserId}`);
  expect(api.ok()).toBeTruthy();
  const body = await api.json();
  expect(Number(body.data.attributes.flatRateMemberNumber)).toBe(seed.grandfatheredUserId);
  expect(body.data.attributes.flatRateOwnerDashboard).toBeUndefined();
  expect(body.data.attributes.flatRateNicknameMode).toBeUndefined();
  errors.assertClean();
});

test("another member sees public Member # and never receives owner DTO", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  await login(page, seed.newToken);
  await page.goto(`/u/${seed.grandfatheredUsername}`, { waitUntil: "networkidle" });
  await expect(page.locator(".UserPage")).toBeVisible();
  await expect(page.locator(".FlatRateMemberNumber")).toHaveText(`Member #${seed.grandfatheredUserId}`);
  await expect(page.locator(".FlatRateOwnerDashboard")).toHaveCount(0);
  const body = await page.evaluate(async (id) => {
    const res = await fetch("/api/users/" + id, { credentials: "same-origin" });
    return res.json();
  }, seed.grandfatheredUserId);
  expect(body.data.attributes.flatRateOwnerDashboard).toBeUndefined();
  expect(body.data.attributes.flatRateNicknameMode).toBeUndefined();
  expect(Number(body.data.attributes.flatRateMemberNumber)).toBe(seed.grandfatheredUserId);
  errors.assertClean();
});

test("owner dashboard chrome is server-authorized and compatibility routes remain", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  await login(page, seed.grandfatheredToken);
  await page.goto(`/u/${seed.grandfatheredUsername}`, { waitUntil: "networkidle" });
  await expect(page.locator(".FlatRateOwnerDashboard")).toBeVisible();
  await expect(page.locator(".FlatRateMemberNumber")).toHaveText(`Member #${seed.grandfatheredUserId}`);
  await expect(page.getByRole("link", { name: "Manage account & security" })).toHaveAttribute(
    "href",
    "https://flatrate.wiki/account",
  );
  await expect(page.getByRole("link", { name: "Manage Community identity" })).toHaveAttribute(
    "href",
    "/settings",
  );
  await expect(page.getByRole("link", { name: "Notification preferences" })).toHaveAttribute(
    "href",
    "/settings",
  );
  await expect(page.locator('input[name="member-number"], input[name="member_number"]')).toHaveCount(0);
  const self = await page.evaluate(async () => {
    const res = await fetch("/api/users/" + app.session.user.id(), { credentials: "same-origin" });
    const body = await res.json();
    return body.data.attributes;
  });
  expect(self.flatRateOwnerDashboard).toBeTruthy();
  expect(self.flatRateOwnerDashboard.schema_version).toBe(1);
  expect(self.flatRateOwnerDashboard.account_url).toBe("https://flatrate.wiki/account");
  expect(self.flatRateOwnerDashboard.settings_path).toBe("/settings");
  await page.reload({ waitUntil: "networkidle" });
  await expect(page.locator(".FlatRateOwnerDashboard")).toBeVisible();
  await page.locator(".SessionDropdown .Dropdown-toggle").click();
  await expect(page.getByRole("link", { name: "My Profile" })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.locator(".FlatRateOwnerDashboard")).toBeVisible();
  await expect(page.locator(".FlatRateOwnerDashboard-cards")).toBeVisible();
  await page.goto("/settings", { waitUntil: "networkidle" });
  await expect(page.locator(".SettingsPage")).toBeVisible();
  await expect(page.locator(".FlatRateMemberDisplay")).toBeVisible();
  errors.assertClean();
});
