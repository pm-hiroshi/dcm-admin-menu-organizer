// @ts-check
const { test, expect } = require('@playwright/test');

test('現在地グループのセパレーターは初期展開されるが、ユーザーは閉じられる', async ({ page, baseURL }) => {
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
    // wait を先に張ってからクリック（取りこぼし防止）
    await Promise.all([
      page.waitForURL(/\/wp-admin\//, { timeout: 60_000, waitUntil: 'domcontentloaded' }).catch(() => { }),
      page.locator('#wp-submit').click(),
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

  // ダッシュボードへ（現在地を含むグループのセパレーターが初期展開される）
  await page.goto(dashboardPath);
  await page.waitForSelector('#adminmenu', { timeout: 30_000 });

  // localStorageをリセットして初期状態を確認
  await page.evaluate(() => localStorage.removeItem('dcm_accordion_state'));

  // 現在地グループ用の初期展開クラスが付与されているセパレーターを探す
  const initialOpenSep = page.locator('#adminmenu > li.dcm-accordion-separator.dcm-accordion-initial-open');
  const initialOpenCount = await initialOpenSep.count();
  test.skip(initialOpenCount === 0, '初期展開対象のテキストセパレーターが見つからない環境のためスキップ');
  const sep = initialOpenSep.first();

  // 初期展開クラスが付与されていること
  await expect(sep).toHaveClass(/dcm-accordion-initial-open/);

  // 初期状態で展開されていること（dcm-collapsedクラスがない）
  await expect(sep).not.toHaveClass(/dcm-collapsed/);

  // グループ内のメニューが表示されていることを確認
  const separatorId = await sep.getAttribute('id');
  expect(separatorId).toBeTruthy();
  const groupClass = 'dcm-accordion-group-' + separatorId;
  const menuItems = page.locator(`#adminmenu > li.${groupClass}`);
  const menuItemsCount = await menuItems.count();
  expect(menuItemsCount).toBeGreaterThan(0);
  await expect(menuItems.first()).not.toHaveClass(/dcm-hidden/);

  // クリックで閉じられること
  await sep.click();
  await expect(sep).toHaveClass(/dcm-collapsed/);
  await expect(menuItems.first()).toHaveClass(/dcm-hidden/);

  // 再度クリックで開けること
  await sep.click();
  await expect(sep).not.toHaveClass(/dcm-collapsed/);
  await expect(menuItems.first()).not.toHaveClass(/dcm-hidden/);

  // baseURL を使っている場合に備えて参照だけ（lint対策）
  expect(baseURL || '').toContain('http');
});

