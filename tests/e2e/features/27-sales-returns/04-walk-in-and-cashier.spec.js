import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');
const cashier = roles.find((role) => role.key === 'cashier');

const preview = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::span');

test.describe('27-sales-returns', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a sale without a customer can only be refunded in cash', async ({ asRole, arrange }) => {
        arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const sale = arrange('posops.sale', { cashier: 'manager@e2e.test', qty: 2, left: 8, customer: 'none' });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await expect(page.getByRole('combobox')).toBeDisabled();
        await expect(page.getByText('Transaksi tanpa pelanggan hanya dapat memakai refund tunai.')).toBeVisible();
    });

    test('a return can be settled as store credit for a customer', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const sale = arrange('posops.sale', { cashier: 'manager@e2e.test', qty: 1, left: 9, customer: uniq('Wati') });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await page.getByRole('spinbutton').fill('1');
        await page.getByPlaceholder('Alasan retur').fill('Tidak jadi');
        await page.getByRole('combobox').selectOption('store_credit');
        await expect(preview(page, 'Saldo toko')).toHaveText(/Rp\s*10\.000/);
        await expect(preview(page, 'Refund')).toHaveText(/Rp\s*0/);

        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await expect(page.getByText('Completed', { exact: true })).toBeVisible({ timeout: 30_000 });

        // Everything was returned: the history no longer offers a return for it.
        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await expect(page.getByText('Retur selesai').first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Retur', exact: true })).toHaveCount(0);
    });

    test('a cashier has no return button and no sales returns page', async ({ asRole, arrange }) => {
        arrange('open-shift', { cash: 100000 });
        const sale = arrange('posops.sale', { qty: 1, left: 9, customer: 'none' });
        const page = await asRole(cashier);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await expect(page.getByText(sale.invoice).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Retur', exact: true })).toHaveCount(0);

        const response = await page.goto('/dashboard/sales-returns');
        expect(response.status()).toBe(403);
    });
});
