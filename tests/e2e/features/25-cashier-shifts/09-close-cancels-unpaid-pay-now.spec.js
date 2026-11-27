import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('closing cancels the unpaid Pay now sale, puts its stock back and audits it; the paid sale is left alone', { tag: ['@REQ-003-AC-20'] }, async ({ asRole, arrange }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        // Two QRIS sales in that shift, their stock already taken: one still waiting (10 - 2), one paid (10 - 1).
        const waiting = arrange('posretry.failed-sale', { status: 'pending', qty: 2 });
        const paid = arrange('posretry.failed-sale', { status: 'paid', qty: 1 });
        expect(arrange('shiftclose.sale-state', { invoice: waiting.invoice })).toMatchObject({ payment_status: 'pending', product_stock: 8, warehouse_stock: 8, restocks: 0 });

        const page = await asRole(cashier);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();
        // An unpaid Pay now sale does not hold the close.
        await expect(page.getByRole('region', { name: 'Belum selesai' })).toHaveCount(0);
        await page.getByRole('spinbutton').fill('100000');
        await page.getByRole('button', { name: 'Finalisasi Closing' }).click();
        await expect(page.getByText('Shift Closed', { exact: true })).toBeVisible();
        await expect(page.getByText('Penjualan Non Tunai', { exact: true }).locator('xpath=following-sibling::p')).toHaveText(/Rp\s*10\.000$/);

        const cancelled = arrange('shiftclose.sale-state', { invoice: waiting.invoice });
        expect(cancelled).toMatchObject({ payment_status: 'failed', product_stock: 10, warehouse_stock: 10, restocks: 1 });
        expect(cancelled.audit['transaction.cancelled_on_shift_close']).toBe(1);
        expect(arrange('shiftclose.sale-state', { invoice: paid.invoice })).toMatchObject({ payment_status: 'paid', product_stock: 9, warehouse_stock: 9, restocks: 0 });
        expect(arrange('shiftclose.state', { shift_id })).toMatchObject({ status: 'closed', transactions_count: 1, non_cash_sales_total: 10000, cash_difference: 0 });
    });
});
