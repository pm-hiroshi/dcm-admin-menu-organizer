// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * DCM Admin Menu Organizer E2E
 *
 * - 設定画面の表示
 * - 「デフォルト状態のメニュー順序を挿入」confirm → キャンセル
 * - アコーディオン（セパレータークリックで折りたたみ + localStorage永続化）
 * - 「未指定のメニューを非表示にする」ON→保存→OFFに戻す
 *
 * 実行前に環境変数で上書き可能:
 * - WP_BASE_URL (default: http://localhost:10110)
 * - WP_ADMIN_USER (default: cursor)
 * - WP_ADMIN_PASS (default: cursor)
 */

test('管理メニュー並び替え: 主要フロー', async ({ page, baseURL }, testInfo) => {
  const user = process.env.WP_ADMIN_USER || 'cursor';
  const pass = process.env.WP_ADMIN_PASS || 'cursor';

  const settingsPath = '/wp-admin/options-general.php?page=dcm-menu-organizer';
  const dashboardPath = '/wp-admin/index.php';

  async function attachJson(name, data) {
    await testInfo.attach(name, {
      body: Buffer.from(JSON.stringify(data, null, 2), 'utf-8'),
      contentType: 'application/json',
    });
  }

  async function screenshot(name) {
    // test-results 配下に残る（HTMLレポートからも確認可能）
    const path = testInfo.outputPath(name);
    await page.screenshot({ path, fullPage: true });
    await testInfo.attach(name, { path, contentType: 'image/png' });
  }

  async function loginIfNeeded() {
    // settingsページへ行き、ログイン画面ならログインして戻る
    await page.goto(settingsPath);
    if (page.url().includes('/wp-login.php')) {
      await page.getByRole('textbox', { name: 'ユーザー名またはメールアドレス' }).fill(user);
      await page.getByRole('textbox', { name: 'パスワード' }).fill(pass);
      await page.getByRole('button', { name: 'ログイン' }).click();
      // ログイン成功なら wp-admin 側へ遷移するはず。失敗時はエラー表示のまま。
      try {
        await page.waitForURL(/\/wp-admin\//, { timeout: 30_000 });
      } catch (e) {
        const loginError = await page.locator('#login_error').textContent().catch(() => null);
        throw new Error(
          [
            'ログイン後も wp-login.php のままです（ログイン失敗の可能性）。',
            loginError ? `login_error: ${loginError.trim()}` : 'login_error: (none)',
          ].join('\n')
        );
      }
      await page.goto(settingsPath);
      await expect(page).toHaveURL(new RegExp('options-general\\.php\\?page=dcm-menu-organizer'));
    }
  }

  await test.step('設定画面: 表示 & 要素確認', async () => {
    await loginIfNeeded();

    await expect(page).toHaveURL(new RegExp('options-general\\.php\\?page=dcm-menu-organizer'));

    await expect(page.locator('#dcm_admin_menu_order')).toBeVisible();
    await expect(page.locator('#import-current-menu')).toBeVisible();
    await expect(page.locator('#dcm_admin_menu_accordion_enabled')).toBeVisible();
    await expect(page.locator('#dcm_admin_menu_hide_unspecified')).toBeVisible();
    await expect(page.getByRole('button', { name: '設定を保存' })).toBeVisible();

    await attachJson('step-01-settings-elements.json', {
      url: page.url(),
      hasTextarea: await page.locator('#dcm_admin_menu_order').count(),
      hasImportButton: await page.locator('#import-current-menu').count(),
      hasAccordionCheckbox: await page.locator('#dcm_admin_menu_accordion_enabled').count(),
      hasHideUnspecifiedCheckbox: await page.locator('#dcm_admin_menu_hide_unspecified').count(),
      hasSaveButton: await page.getByRole('button', { name: '設定を保存' }).count(),
    });

    await screenshot('01-settings-page.png');
  });

  await test.step('ダッシュボード: セパレーター表示 & アコーディオン動作', async () => {
    // 既存状態に左右されないよう、まず localStorage をリセット
    await page.goto(dashboardPath);
    await page.evaluate(() => localStorage.removeItem('dcm_accordion_state'));
    await page.reload();

    await expect(page.locator('#separator-group-1')).toBeVisible();

    const sepInfo = await page.evaluate(() => {
      const el = document.getElementById('separator-group-1');
      if (!el) {
        return null;
      }
      return {
        className: el.className,
        afterContent: getComputedStyle(el, '::after').content,
      };
    });

    expect(sepInfo).not.toBeNull();
    expect(sepInfo.className).toContain('dcm-accordion-separator');
    // CSS ::after にテキストが出る想定（例: "入稿関連1"）
    expect(sepInfo.afterContent).not.toBe('none');

    // 折りたたみ前: ダッシュボード項目が見える
    const dashboardLi = page.locator('#menu-dashboard');
    await expect(dashboardLi).toBeVisible();

    await screenshot('02-dashboard-before.png');

    // セパレーターをクリック → グループが折りたたまれ、配下が非表示になる
    await page.locator('#separator-group-1').click();
    await expect(page.locator('#separator-group-1')).toHaveClass(/dcm-collapsed/);
    await expect(dashboardLi).toHaveClass(/dcm-hidden/);

    await attachJson('step-02-accordion-collapsed.json', {
      separatorId: 'separator-group-1',
      sepInfo,
      dashboardHidden: await dashboardLi.evaluate((el) => el.classList.contains('dcm-hidden')),
    });

    await screenshot('03-dashboard-collapsed.png');

    // リロードしても維持される（localStorage）
    await page.reload();
    await expect(page.locator('#separator-group-1')).toHaveClass(/dcm-collapsed/);
    await expect(dashboardLi).toHaveClass(/dcm-hidden/);

    const stored = await page.evaluate(() => localStorage.getItem('dcm_accordion_state'));
    expect(stored || '').toContain('separator-group-1');

    await attachJson('step-03-accordion-persisted.json', {
      stored,
    });

    await screenshot('04-dashboard-reload-persisted.png');
  });

  await test.step('設定画面: インポートconfirm→キャンセル（textarea不変）', async () => {
    await page.goto(settingsPath);

    const textarea = page.locator('#dcm_admin_menu_order');
    const before = await textarea.inputValue();

    page.once('dialog', async (dialog) => {
      // 既に値があるので confirm が出る想定 → キャンセル
      await dialog.dismiss();
    });

    await page.locator('#import-current-menu').click();

    const after = await textarea.inputValue();
    expect(after).toBe(before);

    await attachJson('step-04-import-cancelled.json', {
      beforeLength: before.length,
      afterLength: after.length,
      unchanged: after === before,
    });

    await screenshot('05-settings-import-cancelled.png');
  });

  await test.step('設定画面: 未指定メニュー非表示 ON→保存→OFFに戻す', async () => {
    await page.goto(settingsPath);

    const checkbox = page.locator('#dcm_admin_menu_hide_unspecified');

    // ON
    if (!(await checkbox.isChecked())) {
      await checkbox.check();
    }
    await page.getByRole('button', { name: '設定を保存' }).click();
    await expect(page.getByText('設定を保存しました。')).toBeVisible();
    await expect(checkbox).toBeChecked();

    await attachJson('step-05-hide-unspecified-enabled.json', {
      checked: await checkbox.isChecked(),
    });

    await screenshot('06-hide-unspecified-enabled.png');

    // OFF（元に戻す）
    await checkbox.uncheck();
    await page.getByRole('button', { name: '設定を保存' }).click();
    await expect(page.getByText('設定を保存しました。')).toBeVisible();
    await expect(checkbox).not.toBeChecked();

    await attachJson('step-06-hide-unspecified-reverted.json', {
      checked: await checkbox.isChecked(),
    });

    await screenshot('07-hide-unspecified-reverted.png');
  });

  // baseURL を使っている場合に備えて、参照だけしておく（lint対策）
  expect(baseURL || '').toContain('http');
});
