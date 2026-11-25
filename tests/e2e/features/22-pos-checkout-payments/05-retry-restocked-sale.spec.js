import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('POS checkout: a restocked sale is not retried', () => {
    test.describe.configure({ timeout: 240_000 });

    test('retrying a sale that was auto-expired and restocked is refused', { tag: ['@REQ-003-AC-19'] }, async ({ asRole, arrange }) => {
        arrange('pos.open-shift', { cash: 100000 });
        const sale = arrange('posretry.failed-sale', { qty: 2 });
        const page = await asRole(cashier);

        // The cashier has the receipt open while the expiry job returns the stock.
        await page.goto(`/dashboard/transactions/${sale.invoice}/print`);
        await expect(page.getByRole('button', { name: 'Coba lagi' })).toBeVisible();
        arrange('posretry.restock', { transaction_id: sale.transaction_id });

        await page.getByRole('button', { name: 'Coba lagi' }).click();

        await expect(page.getByRole('alert')).toContainText('Penjualan ini sudah dibatalkan otomatis dan stoknya dikembalikan');
        await expect(page.getByText('Lunas', { exact: true })).toHaveCount(0);
    });
});
