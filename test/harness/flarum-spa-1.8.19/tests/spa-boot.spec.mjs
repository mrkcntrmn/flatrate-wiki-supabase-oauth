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

test("guest public profile hides Member # and custom nickname sentinel", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  const response = await page.goto(`/u/${seed.sentinelUsername}`, { waitUntil: "networkidle" });
  expect(response && response.status()).toBe(200);
  await expect(page.locator(".UserPage")).toBeVisible();
  await expect(page.locator(".FlatRateMemberNumber")).toHaveCount(0);
  await expect(page.locator(".FlatRateOwnerDashboard")).toHaveCount(0);
  await expect(page.locator("body")).toContainText(seed.sentinelUsername);
  await expect(page.locator("body")).not.toContainText(seed.sentinelNickname);
  await expect(page.locator("body")).not.toContainText(`Member #${seed.sentinelUserId}`);
  await expect(page.locator("body")).not.toContainText(seed.sentinelMemberNickname);

  const bootLeak = await page.evaluate((sentinel) => {
    const attrs = app.store.all("users").map((user) => user.data && user.data.attributes);
    const raw = document.documentElement.outerHTML;
    return {
      attrs,
      hasNickname: raw.includes(sentinel.nickname),
      hasMemberPresentation: raw.includes(`Member #${sentinel.userId}`) || raw.includes(sentinel.memberNickname),
      title: document.title,
    };
  }, {
    nickname: seed.sentinelNickname,
    userId: seed.sentinelUserId,
    memberNickname: seed.sentinelMemberNickname,
  });
  for (const attrs of bootLeak.attrs) {
    if (!attrs) continue;
    expect(attrs.flatRateMemberNumber).toBeUndefined();
    expect(attrs.flatRateMemberNickname).toBeUndefined();
    expect(attrs.flatRateOwnerDashboard).toBeUndefined();
    if (attrs.username === seed.sentinelUsername) {
      expect(attrs.displayName).toBe(seed.sentinelUsername);
      expect(attrs.avatarUrl == null).toBeTruthy();
    }
  }
  expect(bootLeak.hasNickname).toBe(false);
  expect(bootLeak.hasMemberPresentation).toBe(false);
  expect(bootLeak.title).toContain(seed.sentinelUsername);
  expect(bootLeak.title).not.toContain(seed.sentinelNickname);

  const api = await page.request.get(`/api/users/${seed.sentinelUserId}`);
  expect(api.ok()).toBeTruthy();
  const body = await api.json();
  expect(body.data.attributes.username).toBe(seed.sentinelUsername);
  expect(body.data.attributes.displayName).toBe(seed.sentinelUsername);
  expect(body.data.attributes.flatRateMemberNumber).toBeUndefined();
  expect(body.data.attributes.flatRateMemberNickname).toBeUndefined();
  expect(body.data.attributes.avatarUrl == null).toBeTruthy();
  const rawApi = JSON.stringify(body);
  expect(rawApi).not.toContain(seed.sentinelNickname);
  expect(rawApi).not.toContain(`tech_#${seed.sentinelUserId}`);
  errors.assertClean();
});

test("authenticated stranger still sees public Member # and nickname", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  await login(page, seed.newToken);
  await page.goto(`/u/${seed.sentinelUsername}`, { waitUntil: "networkidle" });
  await expect(page.locator(".UserPage")).toBeVisible();
  await expect(page.locator(".FlatRateMemberNumber")).toHaveText(`Member #${seed.sentinelUserId}`);
  await expect(page.locator("body")).toContainText(seed.sentinelNickname);
  const apiRes = await page.request.get(`/api/users/${seed.sentinelUserId}`);
  expect(apiRes.ok()).toBeTruthy();
  const body = await apiRes.json();
  expect(Number(body.data.attributes.flatRateMemberNumber)).toBe(seed.sentinelUserId);
  expect(body.data.attributes.displayName).toBe(seed.sentinelNickname);
  expect(body.data.attributes.flatRateOwnerDashboard).toBeUndefined();
  errors.assertClean();
});

test("guest mention contentHtml projects username not sentinel nickname", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  const discussionUrl = `/d/${seed.discussionId}-${seed.discussionSlug}`;
  const response = await page.goto(discussionUrl, { waitUntil: "networkidle" });
  expect(response && response.status()).toBe(200);
  await expect(page.locator(".DiscussionPage")).toBeVisible();

  const guestHtml = await page.request.get(`/api/posts/${seed.mentionPostId}`);
  expect(guestHtml.ok()).toBeTruthy();
  const guestBody = await guestHtml.json();
  const contentHtml = guestBody.data.attributes.contentHtml || "";
  expect(contentHtml).toContain(seed.sentinelUsername);
  expect(contentHtml).not.toContain(seed.sentinelNickname);

  const included = JSON.stringify(guestBody.included || []);
  expect(included).not.toContain(seed.sentinelNickname);
  expect(included).not.toContain(`flatRateMemberNumber`);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(discussionUrl, { waitUntil: "networkidle" });
  await expect(page.locator(".PostStream")).toBeVisible();
  await expect(page.locator("body")).toContainText(seed.sentinelUsername);
  await expect(page.locator("body")).not.toContainText(seed.sentinelNickname);
  errors.assertClean();
});

test("authenticated mention contentHtml keeps nickname", async ({ page }) => {
  const errors = attachErrorCollectors(page);
  await login(page, seed.grandfatheredToken);
  // Establish forum origin so authenticated request context shares login cookies.
  await page.goto(`/d/${seed.discussionId}-${seed.discussionSlug}`, { waitUntil: "networkidle" });
  const authHtml = await page.request.get(`/api/posts/${seed.mentionPostId}`);
  expect(authHtml.ok()).toBeTruthy();
  const authBody = await authHtml.json();
  const contentHtml = authBody.data.attributes.contentHtml || "";
  expect(contentHtml).toContain(seed.sentinelNickname);

  const userRes = await page.request.get(`/api/users/${seed.sentinelUserId}`);
  expect(userRes.ok()).toBeTruthy();
  const userApi = await userRes.json();
  expect(userApi.data.attributes.displayName).toBe(seed.sentinelNickname);
  expect(Number(userApi.data.attributes.flatRateMemberNumber)).toBe(seed.sentinelUserId);
  errors.assertClean();
});

test("guest and auth profile routes share username canonical path", async ({ page }) => {
  const guest = await page.goto(`/u/${seed.sentinelUsername}`, { waitUntil: "networkidle" });
  expect(guest && guest.status()).toBe(200);
  expect(page.url()).toContain(`/u/${seed.sentinelUsername}`);
  await login(page, seed.newToken);
  const auth = await page.goto(`/u/${seed.sentinelUsername}`, { waitUntil: "networkidle" });
  expect(auth && auth.status()).toBe(200);
  expect(page.url()).toContain(`/u/${seed.sentinelUsername}`);
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

async function openAuthorPost(page) {
  await page.goto(`/d/${seed.discussionId}-${seed.discussionSlug}`, { waitUntil: "networkidle" });
  await expect(page.locator(".DiscussionPage")).toBeVisible();
  await expect(page.locator(".PostStream")).toBeVisible();
}

function upvoteButton(page) {
  return page.locator(".CommentPost-votes .Post-upvote, .Post-votes .Post-voteButton--up").first();
}

function downvoteButton(page) {
  return page.locator(
    ".CommentPost-votes .Post-downvote, .Post-votes .Post-voteButton--down, .DiscussionListItem-voteButton--down",
  );
}

async function readUpvoteState(page) {
  return page.evaluate(() => {
    const btn =
      document.querySelector(".CommentPost-votes .Post-upvote") ||
      document.querySelector(".Post-votes .Post-voteButton--up");
    if (!btn) {
      return { present: false };
    }
    const style = getComputedStyle(btn);
    const active =
      btn.classList.contains("Post-vote--active") || btn.getAttribute("data-active") === "true";
    const ariaPressed = btn.getAttribute("aria-pressed");
    return {
      present: true,
      active,
      ariaPressed,
      color: style.color,
      display: style.display,
    };
  });
}

test("GROWTH-001UI upvote-only thumb three-state colors", async ({ page }) => {
  test.skip(!seed.votingEnabled, "FoF voting not seeded in this harness run");
  const errors = attachErrorCollectors(page);
  const discussionUrl = `/d/${seed.discussionId}-${seed.discussionSlug}`;
  const WHITE = /rgb\(\s*255,\s*255,\s*255\s*\)|#ffffff/i;
  const LIME = /rgb\(\s*132,\s*204,\s*22\s*\)|#84cc16/i;
  const PINK = /rgb\(\s*199,\s*45,\s*93\s*\)|#c72d5d/i;

  async function readVoteChrome(page) {
    return page.evaluate(() => {
      const votes =
        document.querySelector(".CommentPost-votes") ||
        document.querySelector(".Post-votes");
      const btn =
        votes?.querySelector(".Post-upvote") ||
        votes?.querySelector(".Post-voteButton--up");
      const count =
        votes?.querySelector(".Post-points") ||
        votes?.querySelector(".Post-voteCount");
      if (!votes || !btn) {
        return { present: false };
      }
      return {
        present: true,
        zero: votes.classList.contains("FlatRateVotes--zero"),
        hasVotes: votes.classList.contains("FlatRateVotes--hasVotes"),
        mine: votes.classList.contains("FlatRateVotes--mine"),
        active:
          btn.classList.contains("Post-vote--active") ||
          btn.getAttribute("data-active") === "true",
        thumbColor: getComputedStyle(btn).color,
        countColor: count ? getComputedStyle(count).color : null,
        countRight:
          count &&
          count.getBoundingClientRect().left > btn.getBoundingClientRect().left,
      };
    });
  }

  // Peer voter (not the author) exercises the visible upvote control.
  await login(page, seed.newToken);
  await openAuthorPost(page);

  await expect(upvoteButton(page)).toBeVisible();
  await expect(downvoteButton(page)).toHaveCount(0);
  let chrome = await readVoteChrome(page);
  expect(chrome.present).toBe(true);
  expect(chrome.active).toBe(false);
  expect(chrome.zero).toBe(true);
  expect(chrome.mine).toBe(false);
  expect(chrome.countRight).toBe(true);
  expect(chrome.thumbColor).toMatch(WHITE);

  const actionLayout = await page.evaluate(() => {
    const post =
      document.querySelector(".PostStream article.Post.CommentPost") ||
      document.querySelector("article.Post.CommentPost");
    const actions = post?.querySelector(".Post-actions");
    const reply = actions?.querySelector(".item-reply");
    const votes = actions?.querySelector(".item-votes");
    const controls = actions?.querySelector(".Post-controls");
    if (!post || !actions || !reply || !votes) {
      return {
        present: false,
        hasReply: !!reply,
        hasVotes: !!votes,
        hasControls: !!controls,
      };
    }
    const postBox = post.getBoundingClientRect();
    const replyBox = reply.getBoundingClientRect();
    const votesBox = votes.getBoundingClientRect();
    const actionsBox = actions.getBoundingClientRect();
    const replyCenter = replyBox.left + replyBox.width / 2;
    const postCenter = postBox.left + postBox.width / 2;
    const replyBtn = reply.querySelector(".Button") || reply;
    const replyColor = getComputedStyle(replyBtn).color;
    const out = {
      present: true,
      hasControls: !!controls,
      replyCentered: Math.abs(replyCenter - postCenter) < postBox.width * 0.12,
      votesRightOfReply: votesBox.left > replyBox.right - 4,
      votesNearRight: Math.abs(votesBox.right - actionsBox.right) < 24,
      replyPink: /rgb\(\s*199,\s*45,\s*93\s*\)|#c72d5d/i.test(replyColor),
    };
    if (controls) {
      const controlsBox = controls.getBoundingClientRect();
      out.controlsNearTop = controlsBox.top <= postBox.top + 48;
      out.controlsNearRight = Math.abs(controlsBox.right - postBox.right) < 24;
      out.controlsAboveActions = controlsBox.bottom < actionsBox.top + 8;
    }
    return out;
  });
  expect(actionLayout.present).toBe(true);
  expect(actionLayout.replyCentered).toBe(true);
  expect(actionLayout.votesRightOfReply).toBe(true);
  expect(actionLayout.votesNearRight).toBe(true);
  expect(actionLayout.replyPink).toBe(true);

  // Admin always has post controls — prove ⋯ sits in the post top-right.
  await login(page, seed.adminToken);
  await openAuthorPost(page);
  const authorControls = await page.evaluate(() => {
    const post =
      document.querySelector(".PostStream article.Post.CommentPost") ||
      document.querySelector("article.Post.CommentPost");
    const actions = post?.querySelector(".Post-actions");
    const controls = actions?.querySelector(".Post-controls");
    if (!post || !actions || !controls) {
      return { present: false };
    }
    // Desktop hides actions until hover; force visible for geometry.
    actions.style.opacity = "1";
    const postBox = post.getBoundingClientRect();
    const actionsBox = actions.getBoundingClientRect();
    const controlsBox = controls.getBoundingClientRect();
    return {
      present: true,
      controlsNearTop: controlsBox.top <= postBox.top + 48,
      controlsNearRight: Math.abs(controlsBox.right - postBox.right) < 24,
      controlsAboveActions: controlsBox.bottom < actionsBox.top + 8,
    };
  });
  expect(authorControls.present).toBe(true);
  expect(authorControls.controlsNearTop).toBe(true);
  expect(authorControls.controlsNearRight).toBe(true);
  expect(authorControls.controlsAboveActions).toBe(true);

  // Resume peer voter for upvote color assertions.
  await login(page, seed.newToken);
  await openAuthorPost(page);

  // Synthetic has-votes (not mine) proves lime without a second harness voter.
  const limeProbe = await page.evaluate(() => {
    const votes =
      document.querySelector(".CommentPost-votes") ||
      document.querySelector(".Post-votes");
    if (!votes) return null;
    votes.classList.remove("FlatRateVotes--zero", "FlatRateVotes--mine");
    votes.classList.add("FlatRateVotes--hasVotes");
    const btn =
      votes.querySelector(".Post-upvote") ||
      votes.querySelector(".Post-voteButton--up");
    const count =
      votes.querySelector(".Post-points") ||
      votes.querySelector(".Post-voteCount");
    return {
      thumbColor: btn ? getComputedStyle(btn).color : null,
      countColor: count ? getComputedStyle(count).color : null,
    };
  });
  expect(limeProbe.thumbColor).toMatch(LIME);
  expect(limeProbe.countColor).toMatch(LIME);

  const voteResponses = [];
  page.on("response", (response) => {
    if (response.request().method() === "POST" || response.request().method() === "PATCH") {
      if (response.url().includes("/api/posts/")) {
        voteResponses.push(response.status());
      }
    }
  });

  await upvoteButton(page).click();
  await expect.poll(async () => {
    const next = await readVoteChrome(page);
    return next.mine && next.active;
  }).toBe(true);
  chrome = await readVoteChrome(page);
  expect(chrome.thumbColor).toMatch(PINK);
  expect(chrome.countColor).toMatch(PINK);
  // FoF exposes selected state via class/data-active (no native aria-pressed).
  expect(chrome.active).toBe(true);

  await page.reload({ waitUntil: "networkidle" });
  await expect(upvoteButton(page)).toBeVisible();
  chrome = await readVoteChrome(page);
  expect(chrome.active).toBe(true);
  expect(chrome.mine).toBe(true);
  expect(chrome.thumbColor).toMatch(PINK);
  expect(chrome.countColor).toMatch(PINK);

  await upvoteButton(page).click();
  await expect.poll(async () => {
    const next = await readVoteChrome(page);
    return next.active === false && next.mine === false;
  }).toBe(true);
  chrome = await readVoteChrome(page);
  expect(chrome.active).toBe(false);
  expect(chrome.zero).toBe(true);
  expect(chrome.thumbColor).toMatch(WHITE);

  await page.reload({ waitUntil: "networkidle" });
  chrome = await readVoteChrome(page);
  expect(chrome.active).toBe(false);
  expect(chrome.zero).toBe(true);
  await expect(downvoteButton(page)).toHaveCount(0);

  // Self-vote on own post must remain denied (FoF disables the control).
  await login(page, seed.grandfatheredToken);
  await page.goto(discussionUrl, { waitUntil: "networkidle" });
  await expect(upvoteButton(page)).toBeDisabled();
  const selfState = await readUpvoteState(page);
  expect(selfState.active).toBe(false);

  // Guest cannot mutate; voter includes stay private.
  await page.context().clearCookies();
  await page.goto(discussionUrl, { waitUntil: "networkidle" });
  await expect(downvoteButton(page)).toHaveCount(0);
  const guestInclude = await page.request.get(
    `/api/posts/${seed.authorPostId}?include=upvotes,downvotes`,
  );
  expect(guestInclude.ok()).toBeTruthy();
  const guestBody = await guestInclude.json();
  expect(guestBody.data.relationships?.upvotes?.data || []).toEqual([]);
  expect(guestBody.data.relationships?.downvotes?.data || []).toEqual([]);
  const includedUsers = (guestBody.included || []).filter((row) => row.type === "users");
  // Ordinary guest must not receive voter identity via upvotes/downvotes includes.
  for (const user of includedUsers) {
    expect(user.id).not.toBe(String(seed.newUserId));
  }

  for (const viewport of [
    { width: 1024, height: 768 },
    { width: 390, height: 844 },
    { width: 360, height: 800 },
  ]) {
    await page.setViewportSize(viewport);
    await login(page, seed.newToken);
    await page.goto(discussionUrl, { waitUntil: "networkidle" });
    await expect(upvoteButton(page)).toBeVisible();
    await expect(downvoteButton(page)).toHaveCount(0);
    await expect(page.locator(".PostStream")).toBeVisible();
  }

  errors.assertClean();
});
