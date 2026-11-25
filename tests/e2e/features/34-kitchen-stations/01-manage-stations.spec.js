import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

const stationRow = (page, text) => page.getByRole('listitem').filter({ hasText: text });

// REQ-006 AC-01: the shop's stations are managed on one settings page. Dapur is renamed in PHPUnit only: the default
// station is shared by every spec on this database, so no step here renames or deletes it.
test.describe('34-kitchen-stations: manage stations', () => {
    test('@REQ-006-AC-01 a manager adds a station, a duplicate name is refused, then renames and deletes it', async ({ asRole, uniq }) => {
        const page = await asRole(role('manager'));
        const name = uniq('Bar');
        const renamed = `${name}-baru`;

        await page.goto('/dashboard/settings/kitchen-stations');
        await page.getByRole('button', { name: 'Tambah stasiun' }).click();
        await page.getByLabel('Nama stasiun').fill(name);
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(stationRow(page, name)).toBeVisible();

        // The same name in another case is a duplicate: the message sits under the field and nothing is saved.
        await page.getByRole('button', { name: 'Tambah stasiun' }).click();
        await page.getByLabel('Nama stasiun').fill(name.toUpperCase());
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('Stasiun dapur dengan nama ini sudah ada.')).toBeVisible();
        await page.getByRole('button', { name: 'Batal' }).click();
        await expect(stationRow(page, name)).toHaveCount(1);

        await page.getByRole('button', { name: `Ubah nama ${name}` }).click();
        await page.getByLabel('Nama stasiun').fill(renamed);
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(stationRow(page, renamed)).toBeVisible();
        await expect(page.getByRole('button', { name: `Ubah nama ${name}`, exact: true })).toHaveCount(0);

        await page.getByRole('button', { name: `Hapus stasiun ${renamed}` }).click();
        await page.locator('.swal2-popup').getByRole('button', { name: 'Hapus', exact: true }).click();
        await expect(stationRow(page, renamed)).toHaveCount(0);
    });

    test('@REQ-006-AC-01 Dapur has a rename button and no delete button', async ({ asRole }) => {
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/settings/kitchen-stations');
        const dapur = page.getByRole('listitem').filter({ has: page.getByText('Bawaan', { exact: true }) });

        await expect(dapur).toContainText('Dapur');
        await expect(dapur.getByRole('button', { name: /^Ubah nama / })).toBeVisible();
        await expect(dapur.getByRole('button', { name: /^Hapus stasiun / })).toHaveCount(0);
    });
});
