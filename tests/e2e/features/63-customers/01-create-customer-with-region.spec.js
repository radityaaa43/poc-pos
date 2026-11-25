import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf, searchCustomers } from '../../support/crm.js';

test.describe('customers: create with the region selects', () => {
    test('a cashier registers a customer with a real region chain; invalid forms are refused', async ({ asRole, arrange, uniq }) => {
        const name = uniq('Pelanggan');
        const phone = `08${Math.floor(1e9 + Math.random() * 9e9)}`;
        const regions = arrange('crm.regions');
        const existing = arrange('crm.customer', { name: uniq('Sudah Ada') });

        const page = await asRole(roleOf(roles, 'cashier'));
        await page.goto('/dashboard/customers');
        await page.getByRole('link', { name: 'Tambah Pelanggan' }).first().click();
        await expect(page).toHaveURL(/customers\/create$/);

        // Nothing filled in: every required field is reported.
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText(/name field is required/i)).toBeVisible();
        await expect(page.getByText(/no telp field is required/i)).toBeVisible();
        await expect(page.getByText(/province id field is required/i)).toBeVisible();

        // Each region select stays disabled until its parent is chosen, then lists the children of that parent.
        await expect(field(page, 'Kota/Kabupaten')).toBeDisabled();
        await expect(field(page, 'Kelurahan')).toBeDisabled();
        await field(page, 'Provinsi').selectOption({ label: regions.province });
        await field(page, 'Kota/Kabupaten').selectOption({ label: regions.regency });
        await expect(field(page, 'Kecamatan')).toBeEnabled();
        await field(page, 'Kecamatan').selectOption({ label: regions.district });
        await field(page, 'Kelurahan').selectOption({ label: regions.village });
        await expect(field(page, 'Kelurahan')).toHaveValue(regions.village_code);

        // A phone number that belongs to someone else is refused.
        await page.getByPlaceholder('Masukkan nama lengkap').fill(name);
        await page.getByPlaceholder('08xxxxxxxxxx').fill(existing.phone);
        await page.getByPlaceholder('Alamat lengkap pelanggan').fill('Jl. Uji Wilayah 1');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText(/no telp has already been taken/i)).toBeVisible();

        await page.getByPlaceholder('08xxxxxxxxxx').fill(phone);
        // The failed submit reset nothing, but the region chain is the part under test: make sure it survived.
        await expect(field(page, 'Kelurahan')).toHaveValue(regions.village_code);
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(page).toHaveURL(/dashboard\/customers$/);
        await searchCustomers(page, name);
        await expect(page.getByText('Jl. Uji Wilayah 1')).toBeVisible();

        // The detail page opens the saved profile.
        await page.getByRole('link', { name }).click();
        await expect(page).toHaveURL(/customers\/\d+$/);
        await expect(page.getByRole('heading', { name })).toBeVisible();
    });
});
