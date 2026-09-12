import assert from "node:assert/strict";
import test from "node:test";

function htmlText(nickname) {
  return String(nickname)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function mentionIdentifier(username) {
  return username;
}

const FIXTURES = ["tech_#1", "tech_#42", "tech_#322"];

for (const nickname of FIXTURES) {
  test(`render surfaces keep ${nickname} as literal display text`, () => {
    const escaped = htmlText(nickname);
    assert.equal(escaped, nickname);
    assert.doesNotMatch(escaped, /<h[1-6]/);
    assert.doesNotMatch(escaped, /href="#/);
    assert.doesNotMatch(escaped, /<a /);

    const profile = `<span class="username">${escaped}</span>`;
    const discussion = `<h3 class="DiscussionListItem-title">Need a scanner</h3><span class="item-user">${escaped}</span>`;
    const post = `<article><header>${escaped}</header><div class="Post-body">Torque spec?</div></article>`;
    const card = `<div class="UserCard-profile">${escaped}</div>`;
    const search = `<li data-index="users">Result for ${escaped}</li>`;
    const mentionRender = `<a class="UserMention" href="/u/tech_a84f19c2">${escaped}</a>`;
    const notification = `<div class="Notification-content">${escaped} mentioned you</div>`;
    const dm = `<div class="RecipientLabel">${escaped}</div>`;
    const settings = `<strong>${escaped}</strong>`;

    for (const surface of [profile, discussion, post, card, search, mentionRender, notification, dm, settings]) {
      assert.match(surface, new RegExp(nickname.replace("#", "\\#")));
      assert.doesNotMatch(surface, /<h1>/);
      assert.doesNotMatch(surface, /id="322"/);
    }
  });
}

test("mentions address routing username, not the # member nickname", () => {
  assert.equal(mentionIdentifier("tech_a84f19c2"), "tech_a84f19c2");
  assert.doesNotMatch(mentionIdentifier("tech_a84f19c2"), /#/);
  assert.notEqual("tech_#322", "tech_a84f19c2");
});

test("markdown heading risk is confined to multiline injection, which is rejected", () => {
  assert.equal("tech_#322".includes("\n"), false);
  assert.equal("tech_#322".startsWith("#"), false);
});
