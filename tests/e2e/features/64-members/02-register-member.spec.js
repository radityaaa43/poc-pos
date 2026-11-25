import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('members: register', () => {
    test('a manager registers a member with a region; empty forms are refused', async ({ asRole, arrange, uniq }) => {
        const name = uniq('Member Baru');
        const phone = `08${Math.floor(1e9 + Math.random() * 9e9)}`;
        const regions = arrange('crm.regions');

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/members');
        await page.getByRole('link', { name: 'Daftarkan Member' }).click();

        // Required fields and the region chain are reported.
        await page.getByRole('button', { name: 'Daftarkan Member' }).click();
        await expect(page.getByText(/name field is required/i)).toBeVisible();
        await expect(page.getByText(/province id field is required/i)).toBeVisible();

        await page.getByPlaceholder('Masukkan nama lengkap').fill(name);
        await page.getByPlaceholder('08xxxxxxxxxx').fill(phone);
        await field(page, 'Tier Member').selectOption({ label: 'Silver' });
        await field(page, 'Provinsi').selectOption({ label: regions.province });
        await field(page, 'Kota/Kabupaten').selectOption({ label: regions.regency });
        await field(page, 'Kecamatan').selectOption({ label: regions.district });
        await field(page, 'Kelurahan').selectOption({ label: regions.village });
        await page.getByPlaceholder('Alamat lengkap member').fill('Jl. Member 1');
        await page.getByRole('button', { name: 'Daftarkan Member' }).click();

        await expect(page).toHaveURL(/dashboard\/members$/);
        await page.getByPlaceholder('Cari nama member atau nomor anggota...').fill(name);
        const row = page.getByRole('row').filter({ hasText: name });
        await expect(row).toContainText('silver');
        await expect(row).toContainText('Aktif');
        await expect(row).toContainText(/MEM-/);
    });
});
