import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('22-pos-checkout-payments', () => {
    test('Tambah pesanan on a receipt is disabled offline and says why', { tag: ['@REQ-003-AC-21'] }, async ({ asRole, arrange, uniq }) => {
        const sale = arrange('kitchen-sale', { prefix: uniq('Luring'), flagged: 1 });
        const page = await asRole(cashier);
        await page.goto(`/dashboard/transactions/${sale.invoice}/print`);
        const button = page.getByRole('button', { name: 'Tambah pesanan' });
        await expect(button).toBeEnabled({ timeout: 45_000 });

        await page.context().setOffline(true);

        await expect(button).toBeDisabled();
        await expect(page.getByText('Pesanan tambahan tidak tersedia saat offline')).toBeVisible();
        await page.context().setOffline(false);
    });
});
