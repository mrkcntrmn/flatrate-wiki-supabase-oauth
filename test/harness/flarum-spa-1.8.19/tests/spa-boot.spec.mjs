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
  return page
    .locator(
      ".Post-actions .CommentPost-votes .Post-upvote, .CommentPost-votes .Post-upvote, .Post-votes .Post-voteButton--up",
    )
    .first();
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
        document.querySelector(".Post-actions .CommentPost-votes") ||
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

  async function readPostChromeGeometry(page) {
    return page.evaluate(() => {
      const post =
        document.querySelector(".PostStream article.Post.CommentPost") ||
        document.querySelector("article.Post.CommentPost");
      if (!post) {
        return { present: false };
      }
      const actions = post.querySelector(".Post-actions");
      if (actions) {
        actions.style.opacity = "1";
      }
      const header = post.querySelector(".Post-header");
      const username =
        header?.querySelector(".PostUser .username") ||
        header?.querySelector(".PostUser-name .username") ||
        header?.querySelector(".username");
      const reply = actions?.querySelector(".item-reply");
      const votesItem = actions?.querySelector(".item-votes");
      const votesBox =
        votesItem?.querySelector(".CommentPost-votes") ||
        votesItem?.querySelector(".Post-votes");
      const thumb =
        votesBox?.querySelector(".Post-upvote") ||
        votesBox?.querySelector(".Post-voteButton--up");
      const count =
        votesBox?.querySelector(".Post-points") ||
        votesBox?.querySelector(".Post-voteCount");
      const controls =
        actions?.querySelector(".Post-controls .Dropdown-toggle") ||
        actions?.querySelector(".Post-controls");
      const headerVotes = post.querySelector(
        ".Post-header .item-votes .Post-votes, .Post-header .item-votes .CommentPost-votes",
      );

      const center = (el) => {
        const box = el.getBoundingClientRect();
        return { x: box.left + box.width / 2, y: box.top + box.height / 2, box };
      };

      const out = {
        present: true,
        COMMENT_POST_VOTE_OWNER: votesItem && votesBox ? "POST_ACTIONS" : "MISSING",
        ALTERNATE_POST_VOTE_UI: !!headerVotes,
        hasReply: !!reply,
        hasVotes: !!votesItem,
        hasControls: !!controls,
        hasUsername: !!username,
      };

      if (username && controls) {
        const u = center(username);
        const c = center(controls);
        out.usernameCenterY = u.y;
        out.controlsCenterY = c.y;
        out.headerMenuDeltaY = Math.abs(u.y - c.y);
        out.controlsNearRight =
          Math.abs(c.box.right - post.getBoundingClientRect().right) < 24;
      }

      if (reply && votesItem) {
        const r = center(reply);
        const v = center(votesItem);
        const postBox = post.getBoundingClientRect();
        const actionsBox = actions.getBoundingClientRect();
        out.replyCenterY = r.y;
        out.votesCenterY = v.y;
        out.actionRowDeltaY = Math.abs(r.y - v.y);
        out.replyCentered = Math.abs(r.x - (postBox.left + postBox.width / 2)) < postBox.width * 0.12;
        out.votesRightOfReply = v.box.left > r.box.right - 4;
        out.votesNearRight = Math.abs(v.box.right - actionsBox.right) < 24;
        out.votesSameRowAsReply = Math.abs(r.y - v.y) <= 4;
        out.replyLabel = /reply/i.test((reply.textContent || "").trim());
      }

      if (thumb && count && votesBox) {
        const t = center(thumb);
        const n = center(count);
        const thumbBox = thumb.getBoundingClientRect();
        const countBox = count.getBoundingClientRect();
        const voteBox = votesBox.getBoundingClientRect();
        out.thumbCountDeltaY = Math.abs(t.y - n.y);
        out.countRightOfThumb = countBox.left >= thumbBox.right - 2;
        out.countNotUnderThumb = countBox.top < thumbBox.bottom - 2;
        out.voteBoxWiderThanThumb = voteBox.width > thumbBox.width + 4;
        out.voteBoxHeight = voteBox.height;
        out.thumbHeight = thumbBox.height;
      }

      return out;
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

  const viewports = [
    { name: "DESKTOP_1024", width: 1024, height: 768 },
    { name: "TABLET_768", width: 768, height: 1024 },
    { name: "MOBILE_390", width: 390, height: 844 },
    { name: "MOBILE_360", width: 360, height: 800 },
  ];

  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await login(page, seed.newToken);
    await openAuthorPost(page);
    const geo = await readPostChromeGeometry(page);
    expect(geo.present, `${viewport.name} present`).toBe(true);
    expect(geo.COMMENT_POST_VOTE_OWNER, `${viewport.name} vote owner`).toBe("POST_ACTIONS");
    expect(geo.ALTERNATE_POST_VOTE_UI, `${viewport.name} alt header votes`).toBe(false);
    expect(geo.replyCentered, `${viewport.name} reply centered`).toBe(true);
    expect(geo.votesRightOfReply, `${viewport.name} votes right of reply`).toBe(true);
    expect(geo.votesNearRight, `${viewport.name} votes near right`).toBe(true);
    expect(geo.replyLabel, `${viewport.name} reply label`).toBe(true);
    expect(geo.actionRowDeltaY, `${viewport.name} ACTION_ROW_ALIGNMENT`).toBeLessThanOrEqual(4);
    expect(geo.votesSameRowAsReply, `${viewport.name} REPLY_VOTE_SINGLE_ROW`).toBe(true);
    expect(geo.countRightOfThumb, `${viewport.name} count right of thumb`).toBe(true);
    expect(geo.thumbCountDeltaY, `${viewport.name} THUMB_COUNT_INLINE`).toBeLessThanOrEqual(3);
    expect(geo.countNotUnderThumb, `${viewport.name} count not under thumb`).toBe(true);
    expect(geo.voteBoxWiderThanThumb, `${viewport.name} not vertical provider box`).toBe(true);
  }

  // Admin always has post controls — prove ⋯ aligns with username/time row.
  await login(page, seed.adminToken);
  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await openAuthorPost(page);
    const geo = await readPostChromeGeometry(page);
    expect(geo.present, `${viewport.name} admin present`).toBe(true);
    expect(geo.hasControls, `${viewport.name} has controls`).toBe(true);
    expect(geo.hasUsername, `${viewport.name} has username`).toBe(true);
    expect(geo.headerMenuDeltaY, `${viewport.name} HEADER_MENU_ALIGNMENT`).toBeLessThanOrEqual(4);
    expect(geo.controlsNearRight, `${viewport.name} controls right`).toBe(true);
    expect(geo.COMMENT_POST_VOTE_OWNER, `${viewport.name} admin vote owner`).toBe("POST_ACTIONS");
    expect(geo.ALTERNATE_POST_VOTE_UI, `${viewport.name} admin alt ui`).toBe(false);
  }

  // Resume peer voter for upvote color assertions.
  await page.setViewportSize({ width: 1024, height: 768 });
  await login(page, seed.newToken);
  await openAuthorPost(page);

  // Synthetic has-votes (not mine): white thumb, lime count.
  const limeProbe = await page.evaluate(() => {
    const votes =
      document.querySelector(".Post-actions .CommentPost-votes") ||
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
  expect(limeProbe.thumbColor).toMatch(WHITE);
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

  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await login(page, seed.newToken);
    await page.goto(discussionUrl, { waitUntil: "networkidle" });
    await expect(upvoteButton(page)).toBeVisible();
    await expect(downvoteButton(page)).toHaveCount(0);
    await expect(page.locator(".PostStream")).toBeVisible();
  }

  errors.assertClean();
});

test("GROWTH-001UI discussion aggregate upvote header + one ballot", async ({ page }) => {
  test.skip(!seed.votingEnabled, "FoF voting not seeded in this harness run");
  test.skip(!seed.replyBPostId, "reply B not seeded");
  const errors = attachErrorCollectors(page);
  const discussionUrl = `/d/${seed.discussionId}-${seed.discussionSlug}`;
  const LIME = /rgb\(\s*132,\s*204,\s*22\s*\)|#84cc16/i;
  const PINK = /rgb\(\s*199,\s*45,\s*93\s*\)|#c72d5d/i;

  async function readSummary(token) {
    if (token) {
      await login(page, token);
    } else {
      await page.context().clearCookies();
    }
    // Use the browser document session (remember-cookie → PHP session) rather
    // than page.request, which can stay guest-scoped in this harness.
    await page.goto(discussionUrl, { waitUntil: "networkidle" });
    const body = await page.evaluate(async (discussionId) => {
      const res = await fetch(`/api/discussions/${discussionId}`, {
        credentials: "same-origin",
        headers: { Accept: "application/vnd.api+json" },
      });
      if (!res.ok) {
        throw new Error(`discussion summary HTTP ${res.status}`);
      }
      return res.json();
    }, seed.discussionId);
    const attrs = body.data.attributes || {};
    return {
      total: attrs.flatRateDiscussionUpvotes,
      mine: attrs.flatRateDiscussionViewerUpvoted,
      votePostId: attrs.flatRateDiscussionViewerVotePostId,
      canUpvote: attrs.flatRateDiscussionCanUpvote,
      fofVotes: attrs.votes,
      included: body.included || [],
    };
  }

  async function votePost(token, postId, up) {
    await login(page, token);
    await page.goto(discussionUrl, { waitUntil: "networkidle" });
    const status = await page.evaluate(
      async ({ postId, up }) => {
        const post = app.store.getById("posts", String(postId));
        if (!post) return { ok: false, reason: "missing-post" };
        await post.save([!!up, false, "vote"]);
        return { ok: true };
      },
      { postId, up },
    );
    expect(status.ok).toBe(true);
  }

  let summary = await readSummary(seed.newToken);
  expect(summary.total).toBe(0);
  expect(summary.mine).toBe(false);
  expect(summary.canUpvote).toBe(true);
  expect(summary.votePostId).toBeNull();

  await login(page, seed.newToken);
  await page.goto(discussionUrl, { waitUntil: "networkidle" });
  const header = page.locator(".FlatRateDiscussionVote");
  await expect(header).toBeVisible();
  await expect(header).toHaveClass(/FlatRateDiscussionVote--available/);
  const headerColor = await header.evaluate((el) => getComputedStyle(el).color);
  expect(headerColor).toMatch(LIME);

  await header.click();
  await expect.poll(async () => {
    const next = await readSummary(seed.newToken);
    return next.mine && next.total === 1;
  }).toBe(true);
  summary = await readSummary(seed.newToken);
  expect(Number(summary.votePostId)).toBe(seed.authorPostId);
  expect(summary.canUpvote).toBe(false);
  await page.goto(discussionUrl, { waitUntil: "networkidle" });
  await expect(page.locator(".FlatRateDiscussionVote")).toHaveClass(
    /FlatRateDiscussionVote--mine/,
  );
  const pink = await page
    .locator(".FlatRateDiscussionVote")
    .evaluate((el) => getComputedStyle(el).color);
  expect(pink).toMatch(PINK);

  await votePost(seed.newToken, seed.mentionPostId, true);
  summary = await readSummary(seed.newToken);
  expect(summary.total).toBe(1);
  expect(summary.mine).toBe(true);
  expect(Number(summary.votePostId)).toBe(seed.mentionPostId);

  await votePost(seed.newToken, seed.replyBPostId, true);
  summary = await readSummary(seed.newToken);
  expect(summary.total).toBe(1);
  expect(summary.mine).toBe(true);
  expect(Number(summary.votePostId)).toBe(seed.replyBPostId);

  await votePost(seed.sentinelToken, seed.mentionPostId, true);
  const asNew = await readSummary(seed.newToken);
  const asSentinel = await readSummary(seed.sentinelToken);
  expect(asNew.total).toBe(2);
  expect(asSentinel.total).toBe(2);
  expect(asSentinel.mine).toBe(true);
  expect(Number(asSentinel.votePostId)).toBe(seed.mentionPostId);
  expect(asNew.total).not.toBe(asNew.fofVotes);

  await votePost(seed.newToken, seed.replyBPostId, false);
  summary = await readSummary(seed.newToken);
  expect(summary.total).toBe(1);
  expect(summary.mine).toBe(false);
  expect(summary.canUpvote).toBe(true);
  expect(summary.votePostId).toBeNull();

  const guest = await readSummary(null);
  expect(typeof guest.total).toBe("number");
  expect(guest.mine).toBe(false);
  expect(guest.canUpvote).toBe(false);
  expect(guest.votePostId).toBeNull();
  for (const row of guest.included) {
    if (row.type === "users") {
      expect(row.id).not.toBe(String(seed.newUserId));
      expect(row.id).not.toBe(String(seed.sentinelUserId));
    }
  }

  for (const viewport of [
    { width: 1024, height: 768 },
    { width: 390, height: 844 },
  ]) {
    await page.setViewportSize(viewport);
    await login(page, seed.sentinelToken);
    await page.goto(discussionUrl, { waitUntil: "networkidle" });
    const geo = await page.evaluate(() => {
      const btn = document.querySelector(".FlatRateDiscussionVote");
      const icon = btn?.querySelector(".icon");
      const count = btn?.querySelector(".FlatRateDiscussionVote-count");
      if (!btn || !icon || !count) return { present: false };
      const ib = icon.getBoundingClientRect();
      const cb = count.getBoundingClientRect();
      const bb = btn.getBoundingClientRect();
      return {
        present: true,
        inline:
          cb.left >= ib.right - 2 &&
          Math.abs(ib.top + ib.height / 2 - (cb.top + cb.height / 2)) <= 4,
        nowrap: bb.height < 48,
      };
    });
    expect(geo.present).toBe(true);
    expect(geo.inline).toBe(true);
    expect(geo.nowrap).toBe(true);
  }

  errors.assertClean();
});
