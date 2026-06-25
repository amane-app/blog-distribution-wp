import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';

test('同期フックでフェイク記事から下書きが生成される', async ({ page }) => {
  // トークン設定 → cron フック実行（フェイク SDK が記事を返す）
  execSync('npx wp-env run cli wp option update amane_api_token amb_e2e_token', { stdio: 'inherit', timeout: 60_000 });

  // Windows と Linux 両対応: PHP eval の引用符をダブルクォートで統一
  execSync(
    'npx wp-env run cli wp eval "do_action(\'amane_sync_articles\');"',
    {
      stdio: 'inherit',
      // Windows では shell: true でシェル経由実行が必要な場合がある
      shell: process.platform === 'win32',
      timeout: 60_000,
    },
  );

  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');

  await page.goto('/wp-admin/edit.php?post_status=draft&post_type=post');
  // Use the row-title link which is the canonical visible title in the posts list table
  await expect(page.locator('a.row-title', { hasText: 'E2E Sample Article' }).first()).toBeVisible();
});
