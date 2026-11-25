import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { code as newCode, field, roleOf } from '../../support/crm.js';

test.describe('customer vouchers: create', () => {
    test('a manager issues a voucher; empty and duplicate-code forms are refused', async ({ asRole, arrange, uniq }) => {
        const [customerName, voucherName, code] = [uniq('Penerima'), uniq('Voucher Mei'), newCode('E2E')];
        const { customer_id } = arrange('crm.customer', { name: customerName, member: 1 });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/customer-vouchers');
        await page.getByRole('link', { name: 'Buat Voucher' }).click();

        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText(/customer id field is required/i)).toBeVisible();
        await expect(page.getByText(/name field is required/i)).toBeVisible();
        await expect(page.getByText(/discount value field is required/i)).toBeVisible();

        await field(page, 'Pelanggan').selectOption(String(customer_id));
        await field(page, 'Kode Voucher').fill(code.toLowerCase());
        await field(page, 'Nama Voucher').fill(voucherName);
        await field(page, 'Nilai Diskon').fill('5000');
        await field(page, 'Minimum Belanja').fill('20000');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(page).toHaveURL(/customer-vouchers$/);
        await page.getByPlaceholder('Cari kode, voucher, pelanggan...').fill(code);
        const row = page.getByRole('row').filter({ hasText: code });
        await expect(row).toContainText(customerName);
        await expect(row).toContainText('Aktif');

        // The same code cannot be issued twice.
        await page.getByRole('link', { name: 'Buat Voucher' }).click();
        await field(page, 'Pelanggan').selectOption(String(customer_id));
        await field(page, 'Kode Voucher').fill(code);
        await field(page, 'Nama Voucher').fill(`${voucherName} dua`);
        await field(page, 'Nilai Diskon').fill('1000');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText(/code has already been taken/i)).toBeVisible();
    });
});
