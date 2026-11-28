import { confirmPassword, signIn } from '../../support/platform.js';
import { cashier, describeShiftClose, expect, test } from '../../support/shift-close.js';

const nonCash = (page) => page.getByText('Penjualan Non Tunai', { exact: true }).locator('xpath=following-sibling::p');

// #396 BR-7.
describeShiftClose(() => {
    test('an unpaid pay later sale is not non-cash; paid after a force close it counts in the taking shift and is marked', async ({ asRole, arrange, uniq, page }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const note = uniq('Bon');
        const bill = arrange('posops.sale', { guest: 1, note, qty: 2, left: 8, title: uniq('Utang') });
        const till = await asRole(cashier);
        await till.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(nonCash(till)).toHaveText(/Rp\s*0$/);

        await signIn(page, 'manager@e2e.test');
        await confirmPassword(page);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await page.getByRole('spinbutton').fill('100000');
        await page.getByLabel('Catatan tutup paksa').fill('Tamu belum bayar');
        await page.getByRole('button', { name: 'Tutup paksa' }).click();
        await expect(page.getByText('Force Closed', { exact: true })).toBeVisible();
        expect(arrange('shiftclose.state', { shift_id })).toMatchObject({ non_cash_sales_total: 0, unpaid_bills_total: 20000, cash_difference: -20000 });

        const { shift_id: next } = arrange('open-shift', { cash: 100000 });
        await till.goto('/dashboard/transactions');
        await till.getByRole('button', { name: /Belum Lunas/ }).click();
        await till.getByTestId('open-bills').getByRole('listitem').filter({ hasText: note }).getByRole('button', { name: 'Bayar' }).click();
        const dialog = till.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('20000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await till.waitForURL(new RegExp(`/transactions/${bill.invoice}/print`));

        expect(arrange('paylater.sale-state', { invoice: bill.invoice })).toMatchObject({ payment_status: 'paid', cashier_shift_id: next });
        expect(arrange('shiftclose.sale-state', { invoice: bill.invoice }).audit['payment.paid_after_close']).toBe(1);
        await till.goto(`/dashboard/transactions/history?invoice=${bill.invoice}`);
        await expect(till.getByRole('row').filter({ hasText: bill.invoice })).toContainText('Dibayar setelah shift ditutup');
    });
});
