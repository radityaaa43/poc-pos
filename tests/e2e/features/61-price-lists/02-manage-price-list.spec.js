import { test, expect } from '../../support/fixtures.js';
import { roles, PASSWORD } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('price lists: manage', () => {
    test('a manager confirms the password, creates a list, prices a product and deletes both', async ({ asRole, arrange, uniq }) => {
        const [title, name, slug] = [uniq('pl-produk'), uniq('Daftar'), uniq('slug')];
        arrange('crm.product', { title, price: 10000 });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/settings/price-lists');
        await page.getByRole('button', { name: 'Baru' }).click();
        await field(page, 'Nama').fill(name);
        await field(page, 'Slug').fill(slug);
        await field(page, 'Kelompok').selectOption({ label: 'Member' });

        // Saving is a sensitive action: the first attempt asks for the password again.
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/confirm-password/);
        await page.getByPlaceholder('Masukkan password Anda').fill('wrong-password');
        await page.getByRole('button', { name: 'Lanjutkan' }).click();
        await expect(page.getByText(/password is incorrect|provided password/i)).toBeVisible();
        await page.getByPlaceholder('Masukkan password Anda').fill(PASSWORD);
        await page.getByRole('button', { name: 'Lanjutkan' }).click();
        await expect(page).toHaveURL(/settings\/price-lists$/);

        // The confirmed session saves it on the second attempt.
        await page.getByRole('button', { name: 'Baru' }).click();
        await field(page, 'Nama').fill(name);
        await field(page, 'Slug').fill(slug);
        await field(page, 'Kelompok').selectOption({ label: 'Member' });
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        const entry = page.locator('div.p-4').filter({ hasText: name });
        await expect(entry).toContainText(`(${slug})`);
        await expect(entry).toContainText('Member');
        await expect(entry).toContainText('0 produk');

        // Price one product: the page asks for the price in a prompt.
        await entry.getByRole('link').click();
        await page.getByPlaceholder('Cari produk...').fill(title);
        page.once('dialog', (dialog) => dialog.accept('7500'));
        await page.getByRole('button', { name: new RegExp(title) }).click();
        const item = page.locator('div.p-4').filter({ hasText: title });
        await expect(item).toContainText('7.500');
        await expect(item).toContainText('Harga normal: 10.000');

        // Remove the item, then the list (both ask for a native confirm).
        page.once('dialog', (dialog) => dialog.accept());
        await item.getByRole('button').click();
        await expect(page.getByText('Belum ada item')).toBeVisible();
        await page.goto('/dashboard/settings/price-lists');
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('div.p-4').filter({ hasText: name }).getByRole('button').nth(1).click();
        await expect(page.getByText(name)).toHaveCount(0);
    });
});
