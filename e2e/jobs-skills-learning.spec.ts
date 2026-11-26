// Main path of slice 2b: CPDC adds an employer and posts a job with skills; a graduate applies with a note and CV,
// compares their skills with the job and logs a certificate; CPDC shortlists the application and verifies the
// certificate; the graduate sees both. Needs the fake data from api/bin/seed.php (npm run test:e2e runs it).
import { expect, test, type Page } from '@playwright/test';
import { fileURLToPath } from 'node:url';

const PDF = fileURLToPath(new URL('../api/tests/fixtures/linkedin-profile.pdf', import.meta.url));
// seed.php does not clear slice 2b tables: unique names keep reruns apart.
const run = Date.now().toString(36);
const EMPLOYER = `Fake Analytics ${run}`;
const JOB = `Data Analyst Intern ${run}`;
const COURSE = `Fake SQL Basics ${run}`;

async function signIn(page: Page, provider: 'google' | 'linkedin', persona: string, mode = '') {
  await page.goto(`/api/auth/${provider}/start${mode}`);
  await page.getByRole('link', { name: new RegExp(`\\(${persona}\\)$`) }).click();
  await expect(page).toHaveURL(/\/home$/); // the first menu page since slice B
}

async function signOut(page: Page) {
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/login$/);
}

test('jobs, applications, skills gap and learning checks', async ({ page }) => {
  // CPDC: employer, then a job that asks for Data analysis at level 4.
  await signIn(page, 'google', 'superadmin');
  await page.getByRole('link', { name: 'Employers' }).click();
  await page.getByRole('tab', { name: 'Employers' }).click();
  await page.getByRole('button', { name: 'Add employer' }).click();
  await page.getByLabel('Name', { exact: true }).fill(EMPLOYER);
  await page.getByLabel('Industry').fill('Data and analytics');
  await page.getByRole('button', { name: 'Add employer' }).click();
  await expect(page.getByText(`${EMPLOYER} is saved.`)).toBeVisible();

  await page.getByRole('tab', { name: 'Jobs' }).click();
  await page.getByRole('button', { name: 'Post a job' }).click();
  await page.getByLabel('Employer', { exact: true }).selectOption({ label: EMPLOYER });
  await page.getByLabel('Job title').fill(JOB);
  await page.getByLabel('City or Remote').fill('Ipoh');
  await page.getByLabel('Closing date').fill('2030-12-31');
  await page.getByLabel('Description').fill('Clean data and build dashboards with the analytics team.');
  await page.getByLabel('Data analysis').selectOption('4');
  await page.getByRole('button', { name: 'Post job' }).click();
  await expect(page.getByText(`${JOB} is saved.`)).toBeVisible();
  await signOut(page);

  // Graduate: find the job, apply with a note and a CV.
  await signIn(page, 'linkedin', 'alumni', '?mode=signin');
  await page.getByRole('link', { name: 'Jobs' }).click();
  await page.getByLabel('Search').fill(run);
  await page.getByRole('button', { name: 'Show' }).click();
  await page.getByRole('button', { name: new RegExp(JOB) }).click();
  await page.getByLabel('Note to CPDC').fill('I use SQL every day and can start in January.');
  await page.locator('.pick input[type=file]').setInputFiles(PDF);
  await expect(page.getByText('linkedin-profile.pdf')).toBeVisible();
  await page.getByRole('button', { name: 'Send application' }).click();
  await expect(page.getByRole('heading', { name: /Your application: Sent/ })).toBeVisible();
  await expect(page.getByText(/with linkedin-profile\.pdf/)).toBeVisible();

  // Skills: rate Data analysis 2 and see the gap against this job.
  await page.getByRole('button', { name: 'Compare with my skills' }).click();
  await expect(page).toHaveURL(/\/skills\?job=\d+$/);
  await page.getByRole('radiogroup', { name: 'Data analysis level' }).getByText('2', { exact: true }).click();
  await page.getByRole('button', { name: 'Save my rating' }).click();
  await expect(page.getByText('Your rating is saved.', { exact: false })).toBeVisible();
  await expect(page.getByText('2 levels to go')).toBeVisible();

  // Learning: a certificate with a link.
  await page.getByRole('link', { name: 'Learning' }).click();
  await page.getByRole('button', { name: 'Add a course or certificate' }).click();
  await page.getByLabel('Name').fill(COURSE);
  await page.getByLabel('Provider').fill('Fake Academy');
  await page.getByLabel('Finished on').fill('2026-10-15');
  await page.getByLabel('Link to the certificate').fill('https://example.test/cert/1');
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(page.getByText(`${COURSE} is added. CPDC will check it.`)).toBeVisible();
  await signOut(page);

  // CPDC: shortlist the application, verify the certificate.
  await signIn(page, 'google', 'superadmin');
  await page.getByRole('link', { name: 'Employers' }).click();
  await page.getByRole('row', { name: new RegExp(JOB) }).getByRole('button', { name: 'Applicants' }).click();
  await page.getByRole('button', { name: /Fake Alumna/ }).click();
  await expect(page.getByText('I use SQL every day and can start in January.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'linkedin-profile.pdf' })).toBeVisible();
  await page.getByLabel('Status').last().selectOption('shortlisted');
  await expect(page.getByText('Status set to Shortlisted.', { exact: false })).toBeVisible();

  await page.getByRole('link', { name: 'Learning checks' }).click();
  await page.getByLabel('Search').fill(run);
  await page.getByRole('button', { name: 'Show' }).click();
  await page.getByRole('button', { name: 'Verify' }).click();
  await expect(page.getByText(`${COURSE} for Fake Alumna is verified.`)).toBeVisible();
  await signOut(page);

  // Graduate: sees only their own status, and the verified certificate.
  await signIn(page, 'linkedin', 'alumni', '?mode=signin');
  await page.getByRole('link', { name: 'Jobs' }).click();
  await page.getByRole('tab', { name: 'My applications' }).click();
  await expect(page.getByRole('row', { name: new RegExp(JOB) }).getByText('Shortlisted')).toBeVisible();
  await page.getByRole('link', { name: 'Learning' }).click();
  await expect(page.getByRole('listitem').filter({ hasText: COURSE }).getByText('Verified')).toBeVisible();
});
