import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf, searchCustomers } from '../../support/crm.js';

test.describe('customers: edit and delete', () => {
    test('a manager edits a customer with the saved region pre-selected, then deletes them', async ({ asRole, arrange, uniq }) => {
        const [name, newName] = [uniq('Pelanggan'), uniq('Diubah')];
        const regions = arrange('crm.regions');
        arrange('crm.customer', { name, region: 1 });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/customers');
        await searchCustomers(page, name);
        await page.getByRole('link', { name: 'Edit' }).click();

        // The four selects come back filled with the saved chain.
        await expect(field(page, 'Provinsi')).toHaveValue('11');
        await expect(field(page, 'Kota/Kabupaten')).toHaveValue('1101');
        await expect(field(page, 'Kecamatan')).toHaveValue('110101');
        await expect(field(page, 'Kelurahan')).toHaveValue(regions.village_code);

        await page.getByPlaceholder('Nama lengkap').fill(newName);
        await page.getByPlaceholder('Alamat lengkap').fill('Jl. Baru 9');
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page).toHaveURL(/dashboard\/customers$/);

        await searchCustomers(page, newName);
        await expect(page.getByText('Jl. Baru 9')).toBeVisible();

        await page.getByRole('button', { name: 'Hapus' }).click();
        await page.getByRole('button', { name: 'Ya, Hapus!' }).click();
        await expect(page.getByRole('heading', { name: newName })).toHaveCount(0);
    });
});
