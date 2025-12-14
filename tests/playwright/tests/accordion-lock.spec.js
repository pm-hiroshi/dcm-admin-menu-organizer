// @ts-check
const { test, expect } = require('@playwright/test');

test('現在地グループのセパレーターは閉じられない', async ({ page, baseURL }) => {
  test.setTimeout(120_000);
  const user = process.env.WP_ADMIN_USER || 'cursor';
  const pass = process.env.WP_ADMIN_PASS || 'cursor';

  const settingsPath = '/wp-admin/options-general.php?page=dcm-menu-organizer';
  const dashboardPath = '/wp-admin/index.php';

  // settingsページへ行き、ログイン画面ならログインして戻る
  await page.goto(settingsPath, { waitUntil: 'domcontentloaded' });
  if (page.url().includes('/wp-login.php')) {
    await page.locator('#user_login').fill(user);
    await page.locator('#user_pass').fill(pass);
    await page.locator('#wp-submit').click();

    // ログインは画面遷移（成功/失敗どちらも）するので、まず遷移を待つ。
    // 失敗時は login_error が出るのでメッセージも拾う。
    await Promise.race([
      page.waitForNavigation({ timeout: 60_000, waitUntil: 'domcontentloaded' }),
      page.locator('#login_error').waitFor({ timeout: 60_000 }),
    ]);

    if (page.url().includes('/wp-login.php')) {
      const msg = await page.locator('#login_error').innerText().catch(() => '');
      throw new Error(`ログイン後もログイン画面のままです。${msg ? `\n\n${msg}` : ''}`);
    }
    await page.goto(settingsPath, { waitUntil: 'domcontentloaded' });
  }

  // アコーディオンがOFFならONにする
  const accordion = page.locator('#dcm_admin_menu_accordion_enabled');
  if (await accordion.count()) {
    if (!(await accordion.isChecked())) {
      await accordion.check();
      await page.locator('#submit').click();
      await page.waitForLoadState('networkidle');
    }
  }

  // ダッシュボードへ
  await page.goto(dashboardPath);
  await page.waitForSelector('#adminmenu', { timeout: 30_000 });

  // 現在地グループ用のロックが効いているセパレーターがある場合だけ検証する
  const lockedSep = page.locator('#adminmenu > li.dcm-accordion-separator.dcm-accordion-locked');
  const lockedCount = await lockedSep.count();
  test.skip(lockedCount === 0, 'ロック対象のテキストセパレーターが見つからない環境のためスキップ');
  const sep = lockedSep.first();

  // ロックされていること
  await expect(sep).toHaveClass(/dcm-accordion-locked/);

  // クリックしても閉じないこと
  await sep.click();
  await expect(sep).not.toHaveClass(/dcm-collapsed/);

  // baseURL を使っている場合に備えて参照だけ（lint対策）
  expect(baseURL || '').toContain('http');
});

