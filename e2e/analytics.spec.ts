import { expect, test, type Page } from '@playwright/test';

// Slice C: CPDC opens the analytics dashboard, filters it, downloads a report and drills into one alumnus's 360 view,
// at desktop and phone width. Data comes from the seed (fake interns, students, alumni and an open survey round).
async function signInAsSuperadmin(page: Page) {
  await page.goto('/login');
  await page.getByRole('link', { name: 'Sign in with QIU Google' }).click();
  await page.getByRole('link', { name: /\(superadmin\)$/ }).click();
  await expect(page).toHaveURL(/\/home$/); // the menu is a closed drawer on phones
}

for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
  test(`CPDC reads analytics and a person's 360 view at ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await signInAsSuperadmin(page);
    await page.goto('/analytics');
    await expect(page.getByRole('heading', { level: 1, name: 'Analytics' })).toBeVisible();
    const strip = page.getByRole('region', { name: 'Key numbers' });
    await expect(strip.getByText('Active students')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Internships' })).toBeVisible();
    await expect(page.getByText('Fake Data Sdn Bhd')).toBeVisible();

    // Filter to alumni only: no students are counted.
    await page.getByLabel('People').selectOption('alumni');
    await page.getByRole('button', { name: 'Show' }).click();
    await expect(strip.locator('.kpi').filter({ hasText: 'Active students' }).locator('.kpi-value')).toHaveText('0');

    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Alumni outcomes' }).click();
    expect((await download).suggestedFilename()).toMatch(/^alumni-outcomes-\d{4}-\d{2}-\d{2}\.csv$/);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    expect(overflow, 'no horizontal page scroll').toBe(false);

    // Drill into an alumnus without LinkedIn: the 360 view shows their readiness and employment history.
    await page.getByRole('link', { name: 'Fake Graduate Four' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Fake Graduate Four' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Career readiness' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Employment history' })).toBeVisible();
    await expect(page.getByText('Never confirmed.')).toBeVisible();
  });
}
