import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

const option = (page, number) => page.getByRole('group', { name: `Opsi ${number}`, exact: true });

test.describe('48-modifiers: options', () => {
    test('options save with price, recipe and one default; broken ones are refused', { tag: ['@REQ-008-AC-02'] }, async ({ asRole, arrange, uniq }) => {
        const ingredient = arrange('product', { title: uniq('Susu') }).title;
        const page = await asRole(role('manager'));
        const name = uniq('Ukuran');
        const save = page.getByRole('button', { name: 'Simpan', exact: true });
        const [first, second] = [option(page, 1), option(page, 2)];

        await page.goto('/dashboard/settings/modifiers');
        await page.getByRole('button', { name: 'Tambah grup' }).click();
        await page.getByLabel('Nama grup').fill(name);
        await page.getByRole('radio', { name: 'Wajib' }).check();
        await page.getByLabel('Minimum pilihan').fill('1');
        await first.getByLabel('Nama opsi').fill('Regular');
        await page.getByRole('button', { name: 'Tambah opsi' }).click();
        await second.getByLabel('Nama opsi').fill('regular');
        await second.getByLabel('Tambahan harga (Rp)').fill('-1');
        await save.click();
        await expect(page.getByText('Tambahan harga tidak boleh negatif.')).toBeVisible();
        await expect(page.getByText('Nama opsi tidak boleh sama dalam satu grup.').first()).toBeVisible();
        await expect(page.getByText('Grup wajib harus punya satu opsi default.')).toBeVisible();

        await second.getByLabel('Nama opsi').fill('Large');
        await second.getByLabel('Tambahan harga (Rp)').fill('5000');
        // A group has one default: ticking the second takes it from the first.
        await first.getByRole('checkbox', { name: 'Opsi default' }).check();
        await second.getByRole('checkbox', { name: 'Opsi default' }).check();
        await expect(first.getByRole('checkbox', { name: 'Opsi default' })).not.toBeChecked();
        await second.getByRole('button', { name: 'Tambah bahan' }).click();
        await second.getByLabel('Bahan', { exact: true }).selectOption({ label: ingredient });
        await second.getByLabel(/^Jumlah/).fill('50');
        await save.click();
        await expect(page.getByRole('listitem').filter({ hasText: name })).toContainText('Opsi: 2');

        await page.getByRole('button', { name: `Ubah grup ${name}` }).click();
        await expect(second.getByLabel('Tambahan harga (Rp)')).toHaveValue('5000');
        await expect(second.getByRole('checkbox', { name: 'Opsi default' })).toBeChecked();
        await expect(second.getByLabel(/^Jumlah/)).toHaveValue('50');
    });
});
