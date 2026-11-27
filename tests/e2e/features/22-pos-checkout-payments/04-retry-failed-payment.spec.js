import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('POS checkout: retry a failed payment', () => {
    test.describe.configure({ timeout: 240_000 });

    for (const status of ['failed', 'expired']) {
        test(`a ${status} QRIS sale is paid in cash from its receipt`, { tag: ['@REQ-003-AC-19'] }, async ({ asRole, arrange }) => {
            arrange('pos.open-shift', { cash: 100000 });
            const sale = arrange('posretry.failed-sale', { qty: 2, status });
            const page = await asRole(cashier);

            await page.goto(`/dashboard/transactions/${sale.invoice}/print`);

            // The sale shows the failure and offers both ways out.
            await expect(page.getByRole('button', { name: 'Coba lagi' })).toBeVisible();
            await page.getByRole('button', { name: 'Bayar dengan cara lain' }).click();

            // Pay another way: cash.
            await page.getByLabel('Metode pembayaran').selectOption('cash');
            await page.getByLabel('Uang diterima').fill(String(sale.total));
            await page.getByRole('button', { name: 'Bayar', exact: true }).click();

            await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Coba lagi' })).toHaveCount(0);
        });
    }
});
