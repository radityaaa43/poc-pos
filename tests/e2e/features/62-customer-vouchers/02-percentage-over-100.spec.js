import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('customer vouchers: percentage limit', () => {
    test('a percentage voucher above 100 is refused', async ({ asRole, arrange, uniq }) => {
        const { customer_id } = arrange('crm.customer', { name: uniq('Penerima'), member: 1 });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/customer-vouchers/create');
        await field(page, 'Pelanggan').selectOption(String(customer_id));
        await field(page, 'Nama Voucher').fill(uniq('Voucher Persen'));
        await field(page, 'Tipe Diskon').selectOption({ label: 'Persentase (%)' });
        await field(page, 'Nilai Diskon').fill('150');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(page.getByText(/must not be greater than 100/i)).toBeVisible();
        await expect(page).toHaveURL(/customer-vouchers\/create$/);
    });
});
