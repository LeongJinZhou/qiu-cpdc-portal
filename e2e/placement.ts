import { expect, type Page } from '@playwright/test';

/** Days from today as YYYY-MM-DD (UTC; enough for internship dates). */
export function dayFromToday(days: number): string {
  return new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
}

/**
 * Fills and submits the placement form on /logbook (first time: "Submit placement"). The batch fills itself from the
 * start date.
 */
export async function submitPlacement(page: Page, p: { company: string; supervisorEmail: string; start: string; end: string }) {
  await page.getByLabel('Company', { exact: true }).fill(p.company);
  await page.getByLabel('Nature of business').selectOption('Information and communication');
  await page.getByLabel('Address line 1').fill('1 Jalan Fake');
  await page.getByLabel('Postcode').fill('30000');
  await page.getByLabel('State', { exact: true }).fill('Perak');
  await page.getByLabel('Starts').fill(p.start);
  await page.getByLabel('Ends').fill(p.end);
  await expect(page.getByLabel('Batch year')).not.toHaveValue('');
  await page.getByLabel('Monthly allowance (RM)').fill('800');
  await page.getByLabel('Roles and responsibilities').fill('Build dashboards and test the data pipeline.');
  await page.getByLabel('No HR personnel at this company').check();
  await page.getByLabel("Supervisor's name").fill('Fake Supervisor');
  await page.getByLabel("Supervisor's work email").fill(p.supervisorEmail);
  await page.getByLabel("Supervisor's phone").fill('012-345 6789');
  await page.getByRole('button', { name: 'Submit placement' }).click();
}
