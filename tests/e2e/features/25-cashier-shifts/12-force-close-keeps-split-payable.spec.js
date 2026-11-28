import { confirmPassword, signIn } from '../../support/platform.js';
import { cashier, describeShiftClose, expect, test } from '../../support/shift-close.js';

const AFTER_CLOSE = 'Dibayar setelah shift ditutup';
const nonCash = (page) => page.getByText('Penjualan Non Tunai', { exact: true }).locator('xpath=following-sibling::p');

// #396 BR-2, BR-5, BR-6.
describeShiftClose(() => {
    test('a force close leaves the split payable, and its transfer confirmed later is marked paid after close', async ({ asRole, arrange, page }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const split = arrange('shiftclose.split-sale');
        // A fresh login, so the password confirmation never leaks into the manager's shared saved session.
        await signIn(page, 'manager@e2e.test');
        await confirmPassword(page);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(page.getByText('Split pembayaran yang belum lunas tetap bisa dibayar.')).toBeVisible();
        await page.getByRole('spinbutton').fill('105000');
        await page.getByLabel('Catatan tutup paksa').fill('Transfer masih ditunggu');
        await page.getByRole('button', { name: 'Tutup paksa' }).click();
        await expect(page.getByText('Force Closed', { exact: true })).toBeVisible();
        // Only the money received: the cash part is in the drawer, the waiting transfer is in no total.
        await expect(nonCash(page)).toHaveText(/Rp\s*0$/);
        expect(arrange('shiftclose.state', { shift_id })).toMatchObject({
            status: 'force_closed', expected_cash: 105000, cash_difference: 0, unpaid_bills_total: 0, non_cash_sales_total: 0,
            override: { partly_paid_splits: [{ invoice: split.invoice, owed: 15000 }], cancelled_pay_now: [] },
        });
        expect(arrange('shiftclose.sale-state', { invoice: split.invoice })).toMatchObject({ payment_status: 'pending', warehouse_stock: 8, restocks: 0 });

        await page.goto(`/dashboard/transactions/${split.invoice}/print`);
        await page.getByRole('button', { name: 'Konfirmasi Bayar' }).click();
        await page.getByRole('button', { name: 'Konfirmasi Lunas' }).click();
        await expect(page.getByRole('button', { name: 'Konfirmasi Bayar' })).toHaveCount(0);
        const sale = arrange('shiftclose.sale-state', { invoice: split.invoice });
        expect(sale.payment_status).toBe('paid');
        expect(sale.audit['payment.paid_after_close']).toBe(1);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(nonCash(page)).toHaveText(/Rp\s*0$/);

        // History lists a cashier's own sales, so the cashier reads the badge.
        const till = await asRole(cashier);
        await till.goto(`/dashboard/transactions/history?invoice=${split.invoice}`);
        await expect(till.getByRole('row').filter({ hasText: split.invoice })).toContainText(AFTER_CLOSE);

        // #531 BR-1: the report line is the money that arrived late, not the 20000 split. Its invoice filter keeps the
        // period to this sale.
        await page.goto(`/dashboard/reports/sales?invoice=${split.invoice}`);
        const line = page.getByTestId('paid-after-close-line');
        await expect(line).toContainText('1 transaksi');
        await expect(line).toContainText(/Rp\s*15\.000/);
        await expect(line).not.toContainText(/Rp\s*20\.000/);
    });
});
