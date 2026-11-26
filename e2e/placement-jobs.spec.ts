// Main path of slice A, on a desk and on a 390px phone: a student submits their placement (or, on the second run,
// changes it with a reason) and answers the satisfaction survey; CPDC posts a job with the new details; the student
// filters jobs, applies from the job's own Apply button and sees the application; CPDC finds the placement, its
// change history and the satisfaction per company on /internships and downloads both CSV exports.
// Fake people only: the "graduate" persona is an onboarded student without an internship in api/bin/seed.php.
import { expect, test, type Page } from '@playwright/test';
import { dayFromToday, submitPlacement } from './placement';

const run = Date.now().toString(36);
const VIEWPORTS = [
  { name: 'desktop', size: { width: 1280, height: 900 } },
  { name: 'phone', size: { width: 390, height: 844 } },
];

async function signIn(page: Page, persona: string) {
  await page.goto('/api/auth/google/start');
  await page.getByRole('link', { name: new RegExp(`\\(${persona}\\)$`) }).click();
  await expect(page).toHaveURL(/\/profile$/);
}

async function signOut(page: Page) {
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/login$/);
}

async function noSidewaysScroll(page: Page, where: string) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, `${where} scrolls sideways`).toBeLessThanOrEqual(0);
}

for (const viewport of VIEWPORTS) {
  test(`placement, survey, jobs and CPDC placements (${viewport.name})`, async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize(viewport.size);
    const employer = `Fake Placement Co ${run} ${viewport.name}`;
    const job = `Fake Data Intern ${run} ${viewport.name}`;

    // Student: the placement form (first run) or a change with a reason (second run), then the survey.
    await signIn(page, 'graduate');
    await page.goto('/logbook');
    await expect(page.getByRole('heading', { name: 'Internship logbook' })).toBeVisible();
    if (await page.getByRole('button', { name: 'Submit placement' }).isVisible()) {
      await submitPlacement(page, {
        company: 'Fake Placement Sdn Bhd',
        supervisorEmail: `fake.supervisor.${run}@company.example`,
        start: dayFromToday(-30),
        end: dayFromToday(60),
      });
      await expect(page.getByRole('button', { name: 'Log a week' })).toBeVisible();
      await page.getByRole('tab', { name: 'Placement' }).click();
    } else {
      await page.getByRole('tab', { name: 'Placement' }).click();
    }
    await noSidewaysScroll(page, 'placement form');
    await page.getByLabel('Monthly allowance (RM)').fill(viewport.name === 'desktop' ? '950' : '1000');
    await page.getByLabel('Reason').selectOption('allowance_role_update');
    await page.getByLabel('Remarks (optional)').fill('Raise after probation.');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('region', { name: 'Changes you made' }).getByText('Allowance or role update').first()).toBeVisible();

    await page.getByRole('tab', { name: 'Survey' }).click();
    await page.getByRole('group', { name: /Would you recommend QIU juniors/ }).getByText('Yes').click();
    await page.getByText('Very good').click();
    await page.getByRole('group', { name: /offered a job/ }).getByText('No').click();
    await page.getByLabel('Feedback (optional)').fill('Kind mentors and real work.');
    await page.getByRole('button', { name: /(Send|Save) my answers/ }).click();
    await expect(page.getByText('Thank you. Your answers are saved')).toBeVisible();
    await noSidewaysScroll(page, 'survey');
    await signOut(page);

    // CPDC: an employer and a job with the new details.
    await signIn(page, 'superadmin');
    await page.goto('/employers');
    await page.getByRole('tab', { name: 'Employers' }).click();
    await page.getByRole('button', { name: 'Add employer' }).click();
    await page.getByLabel('Name', { exact: true }).fill(employer);
    await page.getByRole('button', { name: 'Add employer' }).click();
    await expect(page.getByText(`${employer} is saved.`)).toBeVisible();
    await page.getByRole('tab', { name: 'Jobs' }).click();
    await page.getByRole('button', { name: 'Post a job' }).click();
    await page.getByLabel('Employer', { exact: true }).selectOption({ label: employer });
    await page.getByLabel('Job title').fill(job);
    await page.getByLabel('Location').fill('Ipoh');
    await page.getByLabel('Closing date').fill('2030-12-31');
    await page.getByLabel('Description').fill('Clean data and build dashboards.');
    await page.getByLabel('Specializations').fill(`Data Science ${run}, Computer Science`);
    await page.getByLabel('Salary from (RM)').fill('1000');
    await page.getByLabel('Salary to (RM)').fill('1500');
    await page.getByLabel('Paid').selectOption('monthly');
    await page.getByLabel('State', { exact: true }).fill('Perak');
    await page.getByLabel('Employer contact email').fill('hr@company.example');
    await page.getByRole('button', { name: 'Post job' }).click();
    await expect(page.getByText(`${job} is saved.`)).toBeVisible();
    await signOut(page);

    // Student: filter by specialization and state, open the job, apply from its Apply button.
    await signIn(page, 'graduate');
    await page.goto('/jobs');
    await page.getByLabel('Specialization').selectOption(`Data Science ${run}`);
    await page.getByLabel('Location (state)').selectOption('Perak');
    await page.getByRole('button', { name: 'Show' }).click();
    await page.getByRole('button', { name: new RegExp(job) }).click();
    await expect(page.getByRole('article').getByText('RM 1,000 - 1,500 per month')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Contact employer' })).toHaveAttribute('href', /^mailto:hr@company\.example/);
    await page.getByRole('link', { name: 'Apply', exact: true }).click();
    await expect(page.getByLabel('Note to CPDC')).toBeFocused();
    await page.getByLabel('Note to CPDC').fill('I can start next month.');
    await page.getByRole('button', { name: 'Send application' }).click();
    await expect(page.getByRole('heading', { name: /Your application: Sent/ })).toBeVisible();
    await noSidewaysScroll(page, 'job detail');
    await page.goto('/jobs');
    await page.getByRole('tab', { name: 'My applications' }).click();
    await expect(page.getByRole('row', { name: new RegExp(job) }).getByText('31 Dec 2030')).toBeVisible();
    await signOut(page);

    // CPDC: the placement, its change history, satisfaction per company, both exports.
    await signIn(page, 'superadmin');
    await page.goto('/internships');
    await page.getByRole('tab', { name: 'Placements' }).click();
    await page.getByLabel('Search').fill('Fake Graduate');
    await page.getByRole('button', { name: 'Show' }).click();
    await page.getByRole('button', { name: /Change history of Fake Graduate/ }).click();
    await expect(page.getByRole('heading', { name: 'Fake Graduate at Fake Placement Sdn Bhd' })).toBeVisible();
    await expect(page.getByText('Raise after probation.').first()).toBeVisible();
    await noSidewaysScroll(page, 'CPDC placements');
    const visits = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Industry Visit CSV' }).click();
    expect((await visits).suggestedFilename()).toMatch(/^industry-visit-.*\.csv$/);

    await page.getByRole('tab', { name: 'Satisfaction' }).click();
    await expect(page.getByRole('row', { name: /Fake Placement Sdn Bhd/ })).toBeVisible();
    await noSidewaysScroll(page, 'CPDC satisfaction');
    const survey = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Industry Satisfaction CSV' }).click();
    expect((await survey).suggestedFilename()).toMatch(/^industry-satisfaction-.*\.csv$/);
  });
}
