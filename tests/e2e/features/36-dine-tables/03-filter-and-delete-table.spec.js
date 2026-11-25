import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('36-dine-tables', () => {
    test('filter by area and status keeps both filters; deleting a table removes it and its guest link', async ({ asRole, arrange, uniq }) => {
        const a = arrange('dine.table', { area: uniq('AreaA'), name: uniq('MA') });
        const b = arrange('dine.table', { area: uniq('AreaB'), name: uniq('MB'), active: '0' });
        const page = await asRole(manager);
        const areaFilter = page.getByRole('group', { name: 'Filter area meja' });
        const statusFilter = page.getByRole('group', { name: 'Filter status meja' });

        await page.goto('/dashboard/dine-tables');
        await page.getByRole('button', { name: 'Daftar' }).click();
        await expect(page.getByRole('row').filter({ hasText: a.table_name })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: b.table_name })).toBeVisible();

        // Area filter, then status filter on top of it.
        await areaFilter.getByRole('button', { name: b.area_name }).click();
        await expect(page).toHaveURL(new RegExp(`area_id=${b.area_id}`));
        await expect(page.getByRole('row').filter({ hasText: a.table_name })).toHaveCount(0);
        await expect(page.getByRole('row').filter({ hasText: b.table_name })).toContainText('Nonaktif');

        await statusFilter.getByRole('button', { name: 'Aktif', exact: true }).click();
        await expect(page).toHaveURL(/is_active=active/);
        await expect(page).toHaveURL(new RegExp(`area_id=${b.area_id}`));
        await expect(page.getByText('Tidak Ada Meja yang Cocok')).toBeVisible();

        await statusFilter.getByRole('button', { name: 'Nonaktif' }).click();
        await expect(page.getByRole('row').filter({ hasText: b.table_name })).toBeVisible();

        // Delete table A (native confirm).
        await areaFilter.getByRole('button', { name: 'Semua' }).click();
        await statusFilter.getByRole('button', { name: 'Semua status' }).click();
        page.once('dialog', (confirm) => confirm.accept());
        await page.getByRole('button', { name: `Hapus ${a.table_name}` }).click();
        await expect(page.getByRole('row').filter({ hasText: a.table_name })).toHaveCount(0);

        const guest = await page.context().newPage();
        expect((await guest.goto(`/dine/${a.token}`)).status()).toBe(404);
    });

    test('a table created without an area still shows in the list', async ({ asRole, uniq }) => {
        const name = uniq('MTA');
        const page = await asRole(manager);
        await page.goto('/dashboard/dine-tables');
        await page.getByRole('button', { name: 'Tambah Meja' }).first().click();
        const dialog = page.getByRole('dialog');
        await dialog.getByPlaceholder('Contoh: M1, Outdoor-1').fill(name);
        await dialog.locator('select').first().selectOption({ label: 'Tanpa Area' });
        await dialog.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('Meja berhasil ditambahkan.')).toBeVisible();

        await page.reload();
        await page.getByRole('button', { name: 'Daftar' }).click();
        await expect(page.getByRole('row').filter({ hasText: name })).toBeVisible();
    });
});
