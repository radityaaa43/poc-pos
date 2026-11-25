import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('customers: phone number', () => {
    test('the saved phone number is shown as typed', async ({ asRole, arrange, uniq }) => {
        const [name, phone] = [uniq('Pelanggan'), `08${Math.floor(1e9 + Math.random() * 9e9)}`];
        const regions = arrange('crm.regions');

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/customers/create');
        await page.getByPlaceholder('Masukkan nama lengkap').fill(name);
        await page.getByPlaceholder('08xxxxxxxxxx').fill(phone);
        await field(page, 'Provinsi').selectOption({ label: regions.province });
        await field(page, 'Kota/Kabupaten').selectOption({ label: regions.regency });
        await field(page, 'Kecamatan').selectOption({ label: regions.district });
        await field(page, 'Kelurahan').selectOption({ label: regions.village });
        await page.getByPlaceholder('Alamat lengkap pelanggan').fill('Jl. Nol 0');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/dashboard\/customers$/);

        await page.getByPlaceholder('Cari pelanggan...').fill(name);
        await page.getByPlaceholder('Cari pelanggan...').press('Enter');
        await expect(page.getByText(phone)).toBeVisible();
    });
});
