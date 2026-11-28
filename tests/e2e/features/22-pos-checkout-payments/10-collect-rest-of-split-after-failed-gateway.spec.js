import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('POS checkout: collect the rest of a split sale', () => {
    test.describe.configure({ timeout: 240_000 });

    // #530 BR-1: a split sale with a failed gateway part shows what is still owed on Belum Lunas, and pays it in cash.
    test('a split with a failed gateway part is collected in cash from Belum Lunas', { tag: ['@BR-1'] }, async ({ asRole, arrange }) => {
        arrange('pos.open-shift', { cash: 100000 });
        const split = arrange('posretry.split-failed-gateway');
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();

        // The row shows the amount still owed and links to the receipt instead of the pay dialog.
        const row = page.getByRole('listitem').filter({ hasText: split.invoice });
        await expect(row).toContainText(/Rp\s*15\.000/);
        await row.getByRole('link', { name: 'Bayar sisa' }).click();

        // The receipt names the rest and takes it with one method; the paid cash part is not asked again.
        await expect(page.getByText('Sisa pembayaran belum diterima.')).toBeVisible();
        await expect(page.getByText(/Sisa tagihan: Rp\s*15\.000/)).toBeVisible();
        await page.getByRole('button', { name: 'Bayar dengan cara lain' }).click();
        await page.getByLabel('Metode pembayaran').selectOption('cash');
        await page.getByLabel('Uang diterima').fill(String(split.owed));
        await page.getByRole('button', { name: 'Bayar', exact: true }).click();

        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
        expect(arrange('shiftclose.sale-state', { invoice: split.invoice })).toMatchObject({ payment_status: 'paid' });
    });
});
