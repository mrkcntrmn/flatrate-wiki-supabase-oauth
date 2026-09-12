import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const source = readFileSync(join(root, "js/dist/member-display.js"), "utf8");

function loadState() {
  const sandbox = { globalThis: {} };
  sandbox.globalThis = sandbox;
  const context = createContext(sandbox);
  runInContext(source, context);
  return sandbox.FlatRateMemberDisplayState;
}

function userFrom(attributes) {
  return {
    attribute(key) {
      return attributes[key];
    },
    displayName() {
      return attributes.displayName;
    },
  };
}

test("new member with no retained custom does not treat tech_#N as custom", () => {
  const state = loadState();
  const user = userFrom({
    flatRateMemberNumber: 324,
    flatRateMemberNickname: "tech_#324",
    flatRateNicknameMode: "member_number",
    flatRateCustomNickname: null,
    displayName: "tech_#324",
  });

  assert.equal(state.retainedCustomNickname(user), "");
  assert.equal(state.nicknameMode(user), "member_number");
  assert.equal(state.customRadioEnabled(user, false), false);
  assert.equal(state.initialCustomDraft(user), "");
  assert.notEqual(state.retainedCustomNickname(user), user.displayName());
});

test("saving DieselDave retains custom and enables restore", () => {
  const state = loadState();
  const attributes = {
    flatRateMemberNumber: 324,
    flatRateMemberNickname: "tech_#324",
    flatRateNicknameMode: "member_number",
    flatRateCustomNickname: null,
    displayName: "tech_#324",
  };
  const user = userFrom(attributes);

  assert.equal(state.customRadioEnabled(user, false), false);

  Object.assign(attributes, {
    flatRateNicknameMode: "custom",
    flatRateCustomNickname: "DieselDave",
    displayName: "DieselDave",
    nickname: "DieselDave",
  });

  assert.equal(state.retainedCustomNickname(user), "DieselDave");
  assert.equal(state.nicknameMode(user), "custom");
  assert.equal(state.customRadioEnabled(user, false), true);
  assert.equal(state.initialCustomDraft(user), "DieselDave");
});

test("member-mode switch keeps retained DieselDave", () => {
  const state = loadState();
  const attributes = {
    flatRateMemberNumber: 324,
    flatRateMemberNickname: "tech_#324",
    flatRateNicknameMode: "member_number",
    flatRateCustomNickname: "DieselDave",
    displayName: "tech_#324",
  };
  const user = userFrom(attributes);

  assert.equal(state.nicknameMode(user), "member_number");
  assert.equal(state.retainedCustomNickname(user), "DieselDave");
  assert.equal(state.customRadioEnabled(user, false), true);
  assert.equal(user.displayName(), "tech_#324");
});

test("custom switch restores DieselDave without using displayName fallback", () => {
  const state = loadState();
  const attributes = {
    flatRateMemberNumber: 324,
    flatRateMemberNickname: "tech_#324",
    flatRateNicknameMode: "custom",
    flatRateCustomNickname: "DieselDave",
    displayName: "DieselDave",
  };
  const user = userFrom(attributes);

  assert.equal(state.nicknameMode(user), "custom");
  assert.equal(state.retainedCustomNickname(user), "DieselDave");
  assert.equal(state.initialCustomDraft(user), "DieselDave");
});

test("production IIFE does not fall back to displayName for retained custom", () => {
  assert.match(source, /retainedCustomNickname/);
  assert.match(source, /customRadioEnabled/);
  assert.doesNotMatch(source, /displayName\(\)/);
});
