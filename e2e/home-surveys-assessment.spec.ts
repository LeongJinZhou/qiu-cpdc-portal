// Main path of slice B, on a desktop and a 390px phone: CPDC opens a survey round; a student lands on /home, takes
// the Student Characteristics Self-Assessment with written commitments and opens its print view, saves a survey
// draft, comes back to it and sends it with N/A on the Hostel; CPDC sees the response rate.
// Needs the fake data from api/bin/seed.php (npm run test:e2e runs it). Round titles carry a stamp, so reruns and the
// two screen sizes each answer their own round.
import { expect, test, type Page } from '@playwright/test';
import { onboardIfAsked } from './onboarding';

const SCREENS = [
  { name: 'desktop', viewport: { width: 1280, height: 900 } },
  { name: 'phone', viewport: { width: 390, height: 844 } },
];

async function signIn(page: Page, persona: string) {
  await page.goto('/api/auth/google/start');
  await page.getByRole('link', { name: new RegExp(`\\(${persona}\\)$`) }).click();
}

/** Opens a menu page; on a phone the menu is a drawer behind the Menu button. */
async function openMenu(page: Page, label: string) {
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible(); // the signed-in frame is there
  const menu = page.getByRole('button', { name: 'Menu' });
  if (await menu.isVisible()) await menu.click();
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: label, exact: true }).click();
}

/** Rates every item of survey step `step` (1-based, as shown): 4, or N/A where the item name is in `na`. */
async function rateStep(page: Page, step: number, na: string[] = []) {
  await expect(page.locator('.sv-progress-text')).toContainText(`Step ${step} of 9`);
  const items = page.locator('fieldset.sv-item');
  const count = await items.count();
  for (let i = 0; i < count; i++) {
    const item = items.nth(i);
    const legend = await item.locator('legend').innerText();
    const name = legend.replace(/^\d+\.\s*/, '').replace(/Not answered$/, '').trim();
    await item.getByRole('radio', { name: na.includes(name) ? /^N\/A/ : /^4 / }).check();
  }
}

for (const screen of SCREENS) {
  test.describe(screen.name, () => {
    test.use({ viewport: screen.viewport });

    test(`home, self-assessment with print view, survey draft and submit (${screen.name})`, async ({ page }) => {
      test.setTimeout(240_000); // one php-cgi per request locally, and 62 survey items
      const round = `Fake Survey ${screen.name} ${Date.now().toString(36)}`;

      // CPDC opens a round.
      await signIn(page, 'superadmin');
      await expect(page).toHaveURL(/\/home$/);
      await openMenu(page, 'Survey results');
      await page.getByRole('button', { name: 'New survey round' }).click();
      await page.getByLabel('Title').fill(round);
      await page.getByRole('button', { name: 'Create as draft' }).click();
      const row = page.getByRole('row', { name: new RegExp(round) });
      await row.getByRole('button', { name: 'Open' }).click();
      await expect(page.getByText(`${round} is open. Students see it now.`)).toBeVisible();
      await page.getByRole('button', { name: 'Sign out' }).click();

      // The student lands on home.
      await signIn(page, 'student-b');
      await onboardIfAsked(page, 'staff', 'linkedin.com/in/fake-other-student');
      await openMenu(page, 'Home');
      await expect(page.getByRole('heading', { level: 1, name: /^Welcome back/ })).toBeVisible();
      await expect(page.getByRole('region', { name: 'To do' })).toContainText('Answer the University and Programme Survey');

      // Self-assessment: nine ratings, two commitments, then the printable report.
      await openMenu(page, 'Assessments');
      const card = page.getByRole('article').filter({ hasText: 'Student Characteristics Self-Assessment' });
      await card.getByRole('link', { name: /^(Start|Take again)$/ }).click();
      const rows = page.locator('fieldset.as-likert-row');
      await expect(rows).toHaveCount(9);
      for (let i = 0; i < 9; i++) await rows.nth(i).getByRole('radio', { name: '4 Good' }).check();
      await page.getByLabel('How will you commit to improving your characteristics?').fill('Fake: join one club and present twice.');
      await page.getByLabel('How can QIU assist you on this journey?').fill('Fake: more mentoring sessions.');
      await page.getByLabel('Your semester (optional)').selectOption('3');
      await page.getByRole('button', { name: 'See my result' }).click();
      await expect(page.getByRole('heading', { level: 1, name: 'Good' })).toBeVisible();
      await page.getByRole('link', { name: 'Open printable report' }).click();
      const sheet = page.getByRole('article', { name: 'Self-assessment report' });
      await expect(sheet.getByRole('heading', { name: 'Student Characteristics Self-Assessment' })).toBeVisible();
      await expect(sheet).toContainText('Fake Other Student');
      await expect(sheet).toContainText('36 / 45 (80%)');
      await expect(sheet).toContainText('Fake: join one club and present twice.');
      await expect(page.getByRole('button', { name: 'Print or save as PDF' })).toBeEnabled();

      // Survey: about you and part of section 1, saved as a draft.
      await openMenu(page, 'Yearly survey');
      await page.getByRole('article').filter({ hasText: round }).getByRole('button', { name: 'Start' }).click();
      await page.getByLabel('Programme').fill('Bachelor of Other Studies');
      await page.getByLabel('Semester').selectOption('3');
      await page.getByRole('button', { name: 'Next' }).click();
      await expect(page.getByRole('heading', { name: 'Programme and Curriculum' })).toBeVisible();
      await page.locator('fieldset.sv-item').first().getByRole('radio', { name: /^5 / }).check();
      await page.getByRole('button', { name: 'Save draft' }).click();
      await expect(page.getByText('Draft saved')).toBeVisible();

      // Back later: the draft is still there.
      await page.reload();
      const mine = page.getByRole('article').filter({ hasText: round });
      await expect(mine.getByText('Draft saved')).toBeVisible();
      await mine.getByRole('button', { name: 'Continue' }).click();
      await expect(page.getByLabel('Programme')).toHaveValue('Bachelor of Other Studies');
      await page.getByRole('button', { name: 'Next' }).click();
      await expect(page.locator('fieldset.sv-item').first().getByRole('radio').nth(4)).toBeChecked();

      // Sending too early marks what is missing.
      for (let step = 3; step <= 9; step++) {
        await page.getByRole('button', { name: 'Next' }).click();
        await expect(page.locator('.sv-progress-text')).toContainText(`Step ${step} of 9`);
      }
      await page.getByRole('button', { name: 'Send my answers' }).click();
      await expect(page.getByText(/Programme and Curriculum: 9 items not answered yet/)).toBeVisible();
      await expect(page.getByText('Not answered').first()).toBeVisible();

      // Every section, N/A on the Hostel, then send.
      for (let step = 2; step <= 8; step++) {
        await rateStep(page, step, ['Hostel']);
        await page.getByRole('button', { name: 'Next' }).click();
      }
      await expect(page.getByRole('heading', { name: 'Check and send' })).toBeVisible();
      await page.getByRole('button', { name: 'Send my answers' }).click();
      await expect(page.getByRole('heading', { name: 'Thank you. Your answers are in.' })).toBeVisible();
      await expect(page.getByText(/Receipt number/)).toBeVisible();
      await page.getByRole('button', { name: 'Sign out' }).click();

      // CPDC sees the response rate.
      await signIn(page, 'superadmin');
      await openMenu(page, 'Survey results');
      await page.getByRole('button', { name: round }).click();
      await expect(page.getByRole('heading', { name: round })).toBeVisible();
      await expect(page.getByText('response rate', { exact: true })).toBeVisible();
      await expect(page.locator('.sv-rate').first()).toContainText('Faculty of Other Studies');
      await page.getByRole('button', { name: /^Support Services and Facilities/ }).click();
      await expect(page.getByRole('row', { name: /Hostel/ })).toContainText('1');
      await expect(page.getByRole('link', { name: 'CSV' })).toHaveAttribute('href', /\/api\/surveys\/manage\/\d+\/export$/);
    });
  });
}
