import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('51-suppliers', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('cashier-role: a cashier keeps suppliers and payables but cannot reach purchasing documents', async ({ asRole, uniq }) => {
        const name = uniq('Toko Kasir');
        const page = await asRole(cashier);

        // Suppliers: allowed, including adding one.
        await page.goto('/dashboard/suppliers');
        await page.locator('form').getByRole('textbox').first().fill(name);
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText(name)).toBeVisible();

        // Payables: allowed.
        await page.goto('/dashboard/payables');
        await expect(page.getByRole('heading', { name: /Hutang/ }).first()).toBeVisible();

        // Purchase orders, goods receivings and supplier returns are not part of the cashier role.
        for (const path of ['purchase-orders', 'goods-receivings', 'supplier-returns', 'raw-materials']) {
            const response = await page.goto(`/dashboard/${path}`);
            expect(response.status(), path).toBe(403);
        }
    });
});
