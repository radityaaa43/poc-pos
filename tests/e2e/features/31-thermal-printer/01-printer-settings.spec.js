import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// Waits for the POST itself: the toast is gone before a reload could race it.
const save = async (page) => {
    const saved = page.waitForResponse((response) => response.url().endsWith('/settings/printer') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Simpan Pengaturan' }).click();
    await saved;
};

test.describe('thermal printer: settings', () => {
    test('paper size and auto-print are saved and shown again after a reload', async ({ asRole }) => {
        test.setTimeout(120_000);
        const page = await asRole(manager);
        await page.goto('/dashboard/settings/printer');
        await expect(page.getByRole('heading', { name: 'Pengaturan Printer' })).toBeVisible();

        const paper = page.locator('form select');
        const autoPrint = page.getByLabel('Cetak otomatis setelah transaksi');
        await paper.selectOption('58mm');
        await autoPrint.check();
        await save(page);
        await expect(page.getByText('Pengaturan printer disimpan')).toBeVisible();

        await page.reload();
        await expect(paper).toHaveValue('58mm');
        await expect(autoPrint).toBeChecked();

        // Leave the shared outlet on its defaults for other steps.
        await paper.selectOption('80mm');
        await autoPrint.uncheck();
        await save(page);
        await page.reload();
        await expect(paper).toHaveValue('80mm');
        await expect(autoPrint).not.toBeChecked();
    });
});
