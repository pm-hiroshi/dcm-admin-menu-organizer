// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  retries: 0,
  reporter: [
    ['list'],
    ['html', { open: 'never' }],
    // 成功/失敗の結果をファイルとして残す（スクショ以外のエビデンス用）
    ['json', { outputFile: './test-results/results.json' }],
  ],
  outputDir: './test-results',
  use: {
    baseURL: process.env.WP_BASE_URL || 'http://localhost:10110',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
});
