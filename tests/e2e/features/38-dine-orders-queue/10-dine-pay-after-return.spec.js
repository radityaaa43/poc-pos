import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

const preview = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::span');
const metric = (page, title) => page.getByText(title, { exact: true }).first().locator('xpath=../following-sibling::p');

// Issue #405: a return on an unpaid dine bill refunds nothing, so the counter asks only for what is still owed and the
// shift recap counts only that. Non-cash, split and gateway payments: tests/Feature/CashierShifts/RecapUnowedReturnTest.php.
test.describe('38-dine-orders-queue', () => {
    test.describe.configure({ timeout: 180_000 });
    test.use({ actionTimeout: 30_000 });

    test('a dine bill with an item returned while unpaid is paid for what it still owes, and the shift counts that', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Kerupuk'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 2 });
        const { shift_id } = arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card).toContainText('Menunggu Pembayaran');
        const { invoice } = arrange('dine.order-state', { access_token });

        // One of the two items is returned before anything is paid: nothing is handed back.
        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await page.getByRole('spinbutton').fill('1');
        await page.getByPlaceholder('Alasan retur').fill('Batal pesan');
        await expect(preview(page, 'Refund')).toHaveText(/Rp\s*0/);
        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        // Navigating away before the completion lands aborts it.
        await expect(page.getByText('Completed', { exact: true })).toBeVisible();

        // The counter asks for the one item left, 10000 of the 20000 bill.
        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        await page.getByRole('row').filter({ hasText: invoice }).getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText('Total tagihan').locator('xpath=following-sibling::span')).toHaveText(/Rp\s*10\.000/);
        await dialog.getByLabel('Uang diterima').fill('20000');
        await expect(dialog).toContainText(/Kembalian: Rp\s*10\.000/);
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${invoice}/print`));

        expect(arrange('dine.order-state', { access_token })).toMatchObject({ payment_status: 'paid', payment_method: 'cash', cash: 20000, change: 10000 });

        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(metric(page, 'Penjualan Tunai')).toHaveText(/^Rp\s*10\.000$/);
        await expect(metric(page, 'Expected Cash')).toHaveText(/^Rp\s*110\.000$/);
    });
});
