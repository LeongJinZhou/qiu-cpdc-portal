import { expect, test, type Page } from '@playwright/test';
import { onboardIfAsked } from './onboarding';

// Every role opens every page in its menu: no API call may fail with a server error and no page may show the
// generic "Something went wrong" message.
// The superadmin sees every CPDC page; a student (the persona the other specs use) and a seeded alumna (LinkedIn
// sign-in) see the rest.
const ROLES: { name: string; provider: 'google' | 'linkedin'; persona: string; onboardAs?: string }[] = [
  { name: 'superadmin', provider: 'google', persona: 'superadmin' },
  { name: 'student', provider: 'google', persona: 'student-b', onboardAs: 'staff' },
  { name: 'alumna', provider: 'linkedin', persona: 'alumni' },
];

async function signIn(page: Page, role: (typeof ROLES)[number]) {
  await page.goto('/login');
  const button = role.provider === 'google' ? 'Sign in with QIU Google' : 'Sign in with LinkedIn';
  await page.getByRole('link', { name: button }).click();
  await page.getByRole('link', { name: new RegExp(`\\(${role.persona}\\)$`) }).click();
  if (role.onboardAs) await onboardIfAsked(page, role.onboardAs, 'linkedin.com/in/fake-other-student');
  await expect(page.getByRole('navigation', { name: 'Main' })).toBeVisible();
}

for (const role of ROLES) {
  test(`${role.name} opens every page without a server error`, async ({ page }) => {
    test.setTimeout(240_000); // up to 20 pages per role
    const failures: string[] = [];
    page.on('response', (res) => {
      if (res.url().includes('/api/') && res.status() >= 500) failures.push(`${res.status()} ${new URL(res.url()).pathname}`);
    });
    await signIn(page, role);

    const menu = page.getByRole('navigation', { name: 'Main' }).getByRole('link');
    const paths = await menu.evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''));
    expect(paths.length).toBeGreaterThan(0);
    for (const path of paths) {
      await page.goto(path);
      await page.waitForLoadState('networkidle');
      await expect(page.getByRole('heading', { level: 1 }).first(), path).toBeVisible();
      if (await page.getByText('Something went wrong').count()) failures.push(`page ${path} shows "Something went wrong"`);
    }
    expect(failures).toEqual([]);
  });
}
