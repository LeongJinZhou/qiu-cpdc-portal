// Main path of slice 2a: a student logs an internship week, the industry supervisor approves it from the emailed
// link without signing in, the student publishes a final-year project; CPDC writes and publishes a self-assessment,
// sees the intern's progress and the project in the talent feed; the student takes the assessment and sees the
// result on their profile. Fake people only (api/bin/seed.php); mail goes to storage/logs/mail.log (log driver).
import { expect, test, type Browser, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { onboardIfAsked } from './onboarding';
import { dayFromToday, submitPlacement } from './placement';

const MAIL_LOG = fileURLToPath(new URL('../storage/logs/mail.log', import.meta.url));
const SUPERVISOR = `fake.supervisor.${Date.now()}@company.example`;
const ASSESSMENT = `Fake interview readiness ${Date.now()}`;
// A real 1x1 PNG for the project picture.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==', 'base64');

async function signIn(page: Page, persona: string) {
  await page.goto('/api/auth/google/start');
  await page.getByRole('link', { name: new RegExp(`\\(${persona}\\)$`) }).click();
}

/** The review link from the last email to the supervisor, as a path on this site. */
function reviewPath(): string {
  const lines = readFileSync(MAIL_LOG, 'utf8').trim().split('\n').map((l) => JSON.parse(l) as { to: string; text: string });
  const mail = lines.filter((m) => m.to === SUPERVISOR).pop();
  const url = /https?:\/\/\S+\/review\/[A-Za-z0-9_-]{43}/.exec(mail?.text ?? '')?.[0];
  if (!url) throw new Error('no review link mailed');
  return new URL(url).pathname;
}

async function supervisorApproves(browser: Browser, path: string) {
  const context = await browser.newContext(); // no session: the supervisor has no account
  const page = await context.newPage();
  await page.goto(path);
  await expect(page.getByRole('heading', { name: /^Week of/ })).toBeVisible();
  await expect(page.getByText('Logbook of Fake Other Student')).toBeVisible();
  await page.getByRole('button', { name: 'Approve this week' }).click();
  await expect(page.getByRole('heading', { name: 'Week approved' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'This week is already reviewed' })).toBeVisible();
  await context.close();
}

test('logbook with supervisor approval, project showcase and a self-assessment', async ({ page, browser }) => {
  test.setTimeout(90_000);
  // A student finishes onboarding (LinkedIn persona "staff" is free in the seeded data).
  await signIn(page, 'student-b');
  await onboardIfAsked(page, 'staff', 'linkedin.com/in/fake-other-student');

  // Internship and one week, sent to the supervisor.
  await page.getByRole('link', { name: 'Logbook' }).click();
  // Slice A: the internship is now the placement form.
  await submitPlacement(page, {
    company: 'Fake Engineering Sdn Bhd',
    supervisorEmail: SUPERVISOR,
    start: dayFromToday(-14),
    end: dayFromToday(60),
  });
  await page.getByRole('button', { name: 'Log a week' }).click();
  await page.getByLabel('What you did').fill('Built the data import and fixed two bugs in the report.');
  await page.getByLabel('What you learned').fill('How code review works in a team.');
  await page.getByRole('button', { name: 'Send to Fake Supervisor' }).click();
  await expect(page.getByText('Sent to Fake Supervisor.')).toBeVisible();
  await expect(page.getByText('With supervisor', { exact: true })).toBeVisible();

  await supervisorApproves(browser, reviewPath());
  await page.reload();
  await expect(page.locator('.lb-week').getByText('Approved')).toBeVisible();

  // The final-year project, published with a picture.
  await page.getByRole('link', { name: 'My project' }).click();
  await page.getByLabel('Title').fill('Fake Crop Disease Detector');
  await page.getByLabel('Summary').fill('A phone app that spots leaf disease from a photo, trained on fake data.');
  await page.getByLabel('Technologies').fill('Python, TensorFlow');
  await page.getByRole('button', { name: 'Save and publish' }).click();
  await expect(page.getByText('Published. Staff and CPDC can find it in the talent feed.')).toBeVisible();
  await page.locator('#sc-file').setInputFiles({ name: 'shot.png', mimeType: 'image/png', buffer: PNG });
  await expect(page.getByRole('img', { name: 'Your project picture' })).toBeVisible();

  // CPDC: a self-assessment, the intern's progress, the talent feed.
  await page.getByRole('button', { name: 'Sign out' }).click();
  await signIn(page, 'superadmin');
  await page.getByRole('link', { name: 'Assessment builder' }).click();
  await page.getByRole('link', { name: 'New assessment' }).click();
  await page.getByLabel('Title').fill(ASSESSMENT);
  await page.getByLabel('Question', { exact: true }).fill('Have you practised common interview questions?');
  await page.getByLabel('Answer 1', { exact: true }).fill('Not yet');
  await page.getByLabel('Answer 2', { exact: true }).fill('Often');
  await page.getByRole('button', { name: 'Create draft' }).click();
  await expect(page.getByText('Saved.')).toBeVisible();
  await page.getByRole('button', { name: 'Publish' }).click();
  await expect(page.getByText('Published. Students and alumni can take it now.')).toBeVisible();

  await page.getByRole('link', { name: 'Internships' }).click();
  const intern = page.getByRole('row', { name: /Fake Other Student/ });
  await expect(intern).toContainText('Fake Engineering Sdn Bhd');
  await expect(intern).toContainText('1/3');
  await page.getByRole('link', { name: 'Talent feed' }).click();
  await page.getByRole('button', { name: /^TensorFlow/ }).click();
  await expect(page.getByRole('link', { name: 'Fake Crop Disease Detector' })).toBeVisible();

  // The student takes it; the server's result shows on the profile.
  await page.getByRole('button', { name: 'Sign out' }).click();
  await signIn(page, 'student-b');
  await page.getByRole('link', { name: 'Assessments' }).click();
  await page.getByRole('article').filter({ hasText: ASSESSMENT }).getByRole('link', { name: 'Start' }).click();
  await page.getByLabel('Often').check();
  await page.getByRole('button', { name: 'See my result' }).click();
  await expect(page.getByRole('status', { name: 'Your result' })).toContainText('100%');
  await page.getByRole('link', { name: 'Open my profile' }).click();
  await expect(page.getByRole('region', { name: 'Self-assessments' })).toContainText(ASSESSMENT);
});
