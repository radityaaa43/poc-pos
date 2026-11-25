import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

// The value cell of a "Preview Dampak" row.
const preview = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::span');

test.describe('27-sales-returns', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a manager returns a sold item for cash: stock, totals and shift cash follow', async ({ asRole, arrange, uniq }) => {
        const { shift_id } = arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const sale = arrange('posops.sale', { cashier: 'manager@e2e.test', qty: 1, left: 9, customer: uniq('Budi'), title: uniq('Teh') });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await expect(page.getByText(`Invoice ${sale.invoice}`)).toBeVisible();

        await page.getByRole('spinbutton').fill('1');
        await page.getByPlaceholder('Alasan retur').fill('Kemasan rusak');
        await expect(page.getByRole('checkbox')).toBeChecked();
        await expect(preview(page, 'Total qty retur')).toHaveText('1 item');
        await expect(preview(page, 'Stok kembali')).toHaveText('1 item');
        await expect(preview(page, 'Refund')).toHaveText(/Rp\s*10\.000/);
        await expect(preview(page, 'Nominal retur')).toHaveText(/Rp\s*10\.000/);

        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await expect(page.getByText('Draft', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await expect(page.getByText('Completed', { exact: true })).toBeVisible({ timeout: 30_000 });
        await expect(page.getByRole('button', { name: 'Selesaikan Retur' })).toHaveCount(0);

        // Stock came back: 9 left + 1 returned, recorded as a stock mutation.
        await page.goto(`/dashboard/stock-mutations?product_id=${sale.product_id}`);
        await expect(page.getByRole('row').filter({ hasText: 'Kemasan rusak' })).toContainText('9 → 10');
        await expect(page.getByRole('row').filter({ hasText: 'Kemasan rusak' }).getByText(sale.title)).toBeVisible();

        // The return is listed, and the cash refund is counted in the manager's shift.
        await page.goto(`/dashboard/sales-returns?invoice=${sale.invoice}`);
        await expect(page.getByRole('row').filter({ hasText: sale.invoice })).toContainText('Completed');
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(page.getByText('Refund Tunai', { exact: true }).locator('xpath=../..')).toContainText(/Rp\s*10\.000/);

        // Everything was returned: the history no longer offers a return.
        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await expect(page.getByText('Retur selesai').first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Retur', exact: true })).toHaveCount(0);
    });

    test('returning one of three items refunds one unit price', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const sale = arrange('posops.sale', { cashier: 'manager@e2e.test', qty: 3, left: 7, customer: uniq('Budi') });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await page.getByRole('spinbutton').fill('1');
        await page.getByPlaceholder('Alasan retur').fill('Kemasan rusak');
        await expect(preview(page, 'Refund')).toHaveText(/Rp\s*10\.000/);
        await expect(preview(page, 'Nominal retur')).toHaveText(/Rp\s*10\.000/);

        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await expect(page.getByText('Completed', { exact: true })).toBeVisible({ timeout: 30_000 });

        // Two items are still returnable.
        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await expect(page.getByRole('link', { name: 'Retur', exact: true })).toBeVisible();
    });
});
