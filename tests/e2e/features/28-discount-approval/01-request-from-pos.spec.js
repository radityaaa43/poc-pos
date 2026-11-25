import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

test.describe('28-discount-approval', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a cashier discount over the limit waits for approval and shows up for the manager', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        arrange('posops.clear-carts');
        const { title } = arrange('product', { title: uniq('Susu'), stock: 20 });
        const { customer } = arrange('posops.customer', { name: uniq('Rina') });
        arrange('posops.discount-threshold', { value: 5000 });

        try {
            const page = await asRole(cashier);
            await page.goto('/dashboard/transactions');
            await page.getByPlaceholder(/Cari produk/).fill(title);
            await page.getByRole('button', { name: title }).click();
            await expect(page.getByText('1 item', { exact: true })).toBeVisible();

            await page.getByRole('button', { name: /Pilih pelanggan/ }).click();
            await page.getByPlaceholder(/Cari nama/).fill(customer);
            await page.getByRole('button', { name: customer }).click();

            // Rp 6.000 off a Rp 10.000 item is above the Rp 5.000 limit.
            await page.getByText('Diskon Manual (Rp)').locator('xpath=following-sibling::div//input').fill('6000');
            await expect(page.getByRole('button', { name: 'Menghitung Promo...' })).toHaveCount(0);
            await page.getByRole('button', { name: 'Uang pas' }).click();
            await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
            await expect(page).toHaveURL(/\/transactions\/TRX-.*\/print/);

            // The manager sees the request with the cashier, customer and discount.
            const managerPage = await asRole(manager);
            await managerPage.goto('/dashboard/discount-approvals');
            const card = managerPage.locator('div').filter({ hasText: `Pelanggan: ${customer}` })
                .filter({ has: managerPage.getByRole('button', { name: 'Setujui' }) }).last();
            await expect(card).toContainText('Kasir: E2E Cashier');
            await expect(card).toContainText(/Diskon:\s*Rp\s*6\.000/);
            await expect(card).toContainText(/Total:\s*Rp\s*4\.000/);
        } finally {
            arrange('posops.discount-threshold', { value: 0 });
        }
    });
});
