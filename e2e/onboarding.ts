import { expect, type Page } from '@playwright/test';

/**
 * After a Google sign-in: finishes onboarding (link LinkedIn persona, follow tick, profile link, consent) when the
 * portal asks for it, and lands on /profile. Specs share one seeded database, so a persona may already be onboarded
 * by an earlier spec; then the portal opens the profile straight away.
 */
export async function onboardIfAsked(page: Page, linkedinPersona: string, profileUrl: string) {
  await page.waitForURL(/\/(onboarding|profile)$/);
  if (page.url().endsWith('/onboarding')) {
    await page.getByRole('link', { name: 'Sign in with LinkedIn' }).click();
    await page.getByRole('link', { name: new RegExp(`\\(${linkedinPersona}\\)$`) }).click();
    const qiuPage = page.waitForEvent('popup');
    await page.getByRole('link', { name: 'Open QIU on LinkedIn and click Follow' }).click();
    await (await qiuPage).close();
    await page.getByLabel('I follow Quest International University on LinkedIn.').check();
    await page.getByLabel('Profile link').fill(profileUrl);
    await page.getByLabel('I agree that CPDC keeps and uses my career profile as described.').check();
    await page.getByRole('button', { name: 'Finish and open my profile' }).click();
  }
  await expect(page).toHaveURL(/\/profile$/);
}
