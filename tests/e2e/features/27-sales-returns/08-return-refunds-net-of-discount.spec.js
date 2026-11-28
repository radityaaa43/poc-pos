import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

// The value cell of a "Preview Dampak" row.
const preview = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::span');

test.describe('27-sales-returns', () => {
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a return of a discounted sale refunds what was paid, not the gross price', async ({ asRole, arrange, uniq }) => {
        const { shift_id } = arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        // 2 x 10.000 with a 2.000 manual discount, PB1 10 % + 5 % service: paid 21.100. Returning one unit gives
        // 9.000 net + 500 service + 1.050 tax = 10.550.
        const sale = arrange('posops.sale', {
            cashier: 'manager@e2e.test', qty: 2, left: 8, customer: uniq('Budi'),
            discount: 2000, service_charge: 1000, tax_total: 2100, tax_kind: 'pb1', tax_rate: 10,
        });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await expect(page.getByText(`Invoice ${sale.invoice}`)).toBeVisible();

        await page.getByRole('spinbutton').fill('1');
        await page.getByPlaceholder('Alasan retur').fill('Kemasan rusak');

        // The discount shows as a deduction before service and tax; the total is what the customer paid.
        await expect(preview(page, 'Diskon dipotong')).toHaveText(/Rp\s*1\.000/);
        await expect(preview(page, 'Service charge dikembalikan')).toHaveText(/Rp\s*500/);
        await expect(preview(page, 'Pajak dikembalikan')).toHaveText(/Rp\s*1\.050/);
        await expect(preview(page, 'Refund')).toHaveText(/Rp\s*10\.550/);
        await expect(preview(page, 'Nominal retur')).toHaveText(/Rp\s*10\.550/);

        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await expect(page.getByText('Completed', { exact: true })).toBeVisible({ timeout: 30_000 });

        // The shift counted the net refund, service and tax included.
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(page.getByText('Refund Tunai', { exact: true }).locator('xpath=../..')).toContainText(/Rp\s*10\.550/);
    });
});
