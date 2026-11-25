import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

const preview = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::span');

test.describe('27-sales-returns', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('returning a pay-later sale clears its receivable', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const sale = arrange('posops.sale', { cashier: 'manager@e2e.test', qty: 1, left: 9, method: 'pay_later', customer: uniq('Tono') });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await page.getByRole('spinbutton').fill('1');
        await page.getByPlaceholder('Alasan retur').fill('Salah kirim');

        await expect(preview(page, 'Piutang saat ini')).toHaveText(/Rp\s*10\.000/);
        await expect(preview(page, 'Piutang setelah retur')).toHaveText(/Rp\s*0/);
        // Nothing was paid yet, so nothing is handed back.
        await expect(preview(page, 'Refund')).toHaveText(/Rp\s*0/);

        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await expect(page.getByText('Completed', { exact: true })).toBeVisible({ timeout: 30_000 });

        // Side effect on the receivable: nothing is owed any more.
        await page.goto(`/dashboard/receivables/${sale.receivable_id}`);
        await expect(page.getByText('Total', { exact: true }).first().locator('xpath=following-sibling::p')).toHaveText(/Rp\s*0/);
        await expect(page.getByText('Sisa', { exact: true }).locator('xpath=following-sibling::p')).toHaveText(/Rp\s*0/);
    });

    // Bug #114: the return amount multiplies the line total by the returned qty (Rp 30.000 instead of Rp 10.000).
    test.fixme('returning one of three items of a pay-later sale lowers the receivable by one unit price', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const sale = arrange('posops.sale', { cashier: 'manager@e2e.test', qty: 3, left: 7, method: 'pay_later', customer: uniq('Tono') });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await page.getByRole('spinbutton').fill('1');
        await page.getByPlaceholder('Alasan retur').fill('Salah kirim');
        await expect(preview(page, 'Piutang setelah retur')).toHaveText(/Rp\s*20\.000/);

        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await page.goto(`/dashboard/receivables/${sale.receivable_id}`);
        await expect(page.getByText('Sisa', { exact: true }).locator('xpath=following-sibling::p')).toHaveText(/Rp\s*20\.000/);
    });
});
