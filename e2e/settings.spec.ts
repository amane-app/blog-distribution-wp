import { test, expect, type Page } from '@playwright/test';

async function login(page: Page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page).toHaveURL(/wp-admin/);
}

test('管理者は設定を保存できる', async ({ page }) => {
  await login(page);
  await page.goto('/wp-admin/options-general.php?page=amane-blog-dist');

  await expect(page.locator('input[name="amane_api_url"]')).toBeVisible();
  await page.fill('input[name="amane_api_token"]', 'amb_e2e_token');

  // Submit the form; WP will POST to options.php then redirect back to settings page
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.locator('p.submit input[type="submit"], input[type="submit"][name="submit"], #submit').first().click(),
  ]);

  // Navigate explicitly to the settings page to confirm the saved value persists
  await page.goto('/wp-admin/options-general.php?page=amane-blog-dist');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('input[name="amane_api_token"]')).toBeVisible();
  await expect(page.locator('input[name="amane_api_token"]')).toHaveValue('amb_e2e_token');
});
