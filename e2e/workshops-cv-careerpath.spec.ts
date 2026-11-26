// Main path of slice 3a: CPDC publishes a workshop and a career path; a student RSVPs, builds and saves a CV and
// ticks a step; CPDC checks the student in on the list and finds the attendance CSV.
// Needs the fake data from api/bin/seed.php (npm run test:e2e runs it). Titles carry a stamp: workshops and paths
// of earlier runs stay in the local database.
import { expect, test, type Page } from '@playwright/test';
import { onboardIfAsked } from './onboarding';

async function signIn(page: Page, provider: 'google' | 'linkedin', persona: string, mode = '') {
  await page.goto(`/api/auth/${provider}/start${mode}`);
  await page.getByRole('link', { name: new RegExp(`\\(${persona}\\)$`) }).click();
}

/** Tomorrow at hh:mm Malaysia time, as a datetime-local value. */
function tomorrowAt(hhmm: string): string {
  const my = new Date(Date.now() + 8 * 3600_000 + 86400_000);
  return `${my.toISOString().slice(0, 10)}T${hhmm}`;
}

test('workshop RSVP and check-in, CV builder and career path', async ({ page }) => {
  const stamp = Date.now().toString(36);
  const title = `CV clinic ${stamp}`;
  const programme = `Bachelor of Fake E2E ${stamp}`;

  await signIn(page, 'google', 'superadmin');
  await page.getByRole('link', { name: 'Workshops', exact: true }).click();
  await page.getByRole('button', { name: 'New workshop' }).click();
  await page.getByLabel('Title').fill(title);
  await page.getByLabel('Starts (Malaysia time)').fill(tomorrowAt('10:00'));
  await page.getByLabel('Ends (Malaysia time)').fill(tomorrowAt('12:00'));
  await page.getByLabel('Venue', { exact: true }).fill('Fake Hall 1');
  await page.getByLabel('Seats').fill('1');
  await page.getByRole('button', { name: 'Publish workshop' }).click();
  await expect(page.getByText(`"${title}" is published.`)).toBeVisible();

  await page.getByRole('link', { name: 'Career path' }).click();
  await page.getByRole('button', { name: 'New path' }).click();
  await page.getByLabel('Programme').fill(programme);
  await page.getByLabel('Target role').fill('Data Analyst');
  await page.getByLabel('Skills the role needs').fill('SQL');
  await page.getByLabel('Skills the role needs').press('Enter');
  await page.getByLabel('Step 1', { exact: true }).fill('Finish a data internship');
  await page.getByRole('button', { name: 'Publish path' }).click();
  await expect(page.getByRole('heading', { name: 'Data Analyst' })).toBeVisible();
  await page.getByRole('button', { name: 'Sign out' }).click();

  // A student: onboarding first (slice 1), then the slice 3a screens. Not the persona "student": the slice 1 spec
  // makes that one alumni. The fake LinkedIn persona "staff" is free for this student to link.
  await signIn(page, 'google', 'student-b');
  await onboardIfAsked(page, 'staff', 'linkedin.com/in/fake-other-student');

  await page.getByRole('link', { name: 'Workshops', exact: true }).click();
  const card = page.getByRole('article', { name: title });
  await card.getByRole('button', { name: 'RSVP' }).click();
  await expect(card.getByText('You have a seat. See you there.')).toBeVisible();
  await expect(card.getByText('0 of 1 seats left')).toHaveCount(0);
  await expect(card.getByText(/^Full/)).toBeVisible();

  await page.getByRole('link', { name: 'CV builder' }).click();
  await page.getByLabel('Headline').fill('Data student at QIU');
  await page.getByRole('button', { name: /^Extra sections/ }).click(); // a folded section since slice B
  await page.getByRole('button', { name: 'Projects' }).click();
  await page.getByLabel('Heading').fill('Fake attendance dashboard');
  await page.getByRole('button', { name: 'Save CV' }).click();
  await expect(page.getByText('Your CV is saved.')).toBeVisible();
  await page.reload();
  const sheet = page.getByRole('article', { name: 'CV preview' });
  await expect(sheet.getByText('Fake attendance dashboard')).toBeVisible();
  await expect(sheet.getByText('Data student at QIU')).toBeVisible();

  await page.getByRole('link', { name: 'Career path' }).click();
  await page.getByLabel('Programme').selectOption(programme);
  const step = page.getByRole('checkbox', { name: /Finish a data internship/ });
  await step.check();
  await expect(page.getByText('1 of 1 steps done')).toBeVisible();
  await page.reload();
  await page.getByLabel('Programme').selectOption(programme);
  await expect(page.getByRole('checkbox', { name: /Finish a data internship/ })).toBeChecked();
  await page.getByRole('button', { name: 'Sign out' }).click();

  // CPDC at the door.
  await signIn(page, 'google', 'superadmin');
  await page.getByRole('link', { name: 'Workshops', exact: true }).click();
  await page.getByRole('article', { name: title }).getByRole('button', { name: 'Check-in list' }).click();
  await expect(page.getByText('Check-in code')).toBeVisible();
  await page.getByRole('checkbox', { name: 'Fake Other Student is here' }).check();
  await expect(page.getByText('1 of 1 with a seat are here')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Attendance CSV' })).toHaveAttribute('href', /\/api\/workshops\/\d+\/export$/);
});
