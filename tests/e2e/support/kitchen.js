import { expect } from '@playwright/test';
import { confirmPassword, signIn } from './platform.js';
import { roles } from './roles.js';
import { asText, sentToPrinter } from './webusb.js';

const manager = roles.find((role) => role.key === 'manager');

// A manager confirms that a pending bank transfer arrived, in a fresh browser context: the password step-up must
// not leak into the shared saved session of the manager role. Returns once the receipt says "Lunas".
export async function confirmTransfer(browser, invoice) {
    const context = await browser.newContext({ locale: 'id-ID', serviceWorkers: 'block' });
    const page = await context.newPage();
    await signIn(page, manager.email);
    await confirmPassword(page);
    await page.goto(`/dashboard/transactions/${invoice}/print`);
    await page.getByRole('button', { name: 'Konfirmasi Bayar' }).click();
    await page.getByRole('button', { name: 'Konfirmasi Lunas' }).click();
    await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    await context.close();
}

// Names this device as a print station on the kitchen display and switches Cetak otomatis on, with the fake
// WebUSB printer (call fakePrinter(page) first). Returns the settings panel. Needs an open shift in the outlet.
export async function setUpPrintStation(page, name) {
    await page.goto('/dashboard/kitchen');
    await page.getByRole('button', { name: 'Pengaturan printer dapur' }).click();
    const panel = page.getByRole('region', { name: 'Pengaturan printer dapur' });
    await panel.getByLabel('Nama perangkat cetak').fill(name);
    await panel.getByRole('button', { name: 'Simpan' }).click();
    await expect(panel.getByText(name, { exact: true })).toBeVisible();
    await panel.getByRole('button', { name: 'Hubungkan printer' }).click();
    await expect(panel.getByRole('button', { name: 'Putuskan' })).toBeVisible();
    await panel.getByRole('switch', { name: 'Cetak otomatis' }).click();
    await expect(panel.getByRole('status')).toHaveText('Printer siap');

    return panel;
}

// The first thing sent to the fake printer whose text has `needle` (the ticket's number), as bytes; undefined until it arrives.
export async function sentWith(page, needle) {
    return (await sentToPrinter(page)).find((bytes) => asText(bytes).includes(needle));
}
