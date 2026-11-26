// Main path of slice 1: a student signs in with Google, links LinkedIn, finishes onboarding, fills the profile from
// a LinkedIn PDF; a CPDC officer makes them alumni and finds them in the tracker with their LinkedIn link.
// Needs the fake data from api/bin/seed.php (npm run test:e2e runs it).
import { expect, test, type Page } from '@playwright/test';
import { fileURLToPath } from 'node:url';

const PDF = fileURLToPath(new URL('../api/tests/fixtures/linkedin-profile.pdf', import.meta.url));

async function signIn(page: Page, provider: 'google' | 'linkedin', persona: string, mode = '') {
  await page.goto(`/api/auth/${provider}/start${mode}`);
  await page.getByRole('link', { name: new RegExp(`\\(${persona}\\)$`) }).click();
}

test('student onboarding, LinkedIn PDF import and the alumni tracker', async ({ page }) => {
  await signIn(page, 'google', 'student');
  await expect(page.getByRole('heading', { name: /Welcome/ })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Finish and open my profile' })).toBeDisabled();

  await page.getByRole('link', { name: 'Sign in with LinkedIn' }).click();
  await page.getByRole('link', { name: /\(student\)$/ }).click();
  await expect(page.getByText('LinkedIn is linked to your QIU account.')).toBeVisible();
  const follow = page.getByLabel('I follow Quest International University on LinkedIn.');
  await expect(follow).toBeDisabled(); // unlocks only after the QIU page was opened
  const qiuPage = page.waitForEvent('popup');
  await page.getByRole('link', { name: 'Open QIU on LinkedIn and click Follow' }).click();
  await (await qiuPage).close();
  await follow.check();
  await page.getByLabel('Profile link').fill('linkedin.com/in/fake-student');
  await page.getByLabel('I agree that CPDC keeps and uses my career profile as described.').check();
  await page.getByRole('button', { name: 'Finish and open my profile' }).click();

  await expect(page).toHaveURL(/\/profile$/);
  await expect(page.getByRole('heading', { name: 'Fake Student' })).toBeVisible();
  await page.locator('.drop input[type=file]').setInputFiles(PDF);
  await expect(page.getByText('Data Intern, Petronas')).toBeVisible();
  await page.getByRole('button', { name: 'Save to my profile' }).click();
  await expect(page.getByText('Your LinkedIn details are saved to your profile.')).toBeVisible();
  await expect(page.getByLabel('Headline')).toHaveValue('Data student at QIU');

  await page.getByRole('button', { name: 'Sign out' }).click();
  await signIn(page, 'google', 'superadmin');
  await page.getByRole('link', { name: 'People' }).click();
  await page.getByLabel('Search').fill('Fake Student');
  await page.getByRole('button', { name: 'Show' }).click();
  await page.getByRole('link', { name: 'View', exact: true }).first().click();
  await page.getByLabel('Graduated in').fill('2026');
  await page.getByRole('button', { name: 'Make alumni' }).click();
  await expect(page.getByText(/Now an alumnus\. They sign in with LinkedIn/)).toBeVisible();

  await page.getByRole('link', { name: 'Alumni tracker' }).click();
  const row = page.getByRole('row', { name: /Fake Student/ });
  await expect(row.getByRole('link', { name: /Fake Student/ })).toHaveAttribute('href', 'https://www.linkedin.com/in/fake-student');
});
