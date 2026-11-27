import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

test.describe('48-modifiers: attach to a menu', () => {
    test('groups attach to a menu in the chosen order, never to a raw material', { tag: ['@REQ-008-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        const menu = arrange('modifier-menu', { title: uniq('Kopi') });
        const size = arrange('modifier-group', { name: uniq('Ukuran') }).name;
        const sugar = arrange('modifier-group', { name: uniq('Gula') }).name;
        const page = await asRole(role('manager'));
        const picker = page.getByLabel('Pasang grup modifier');
        const attached = page.getByRole('listitem').filter({ hasText: /\((Wajib|Opsional)\)/ });
        const edit = `/dashboard/products/${menu.product_id}/edit`;

        await page.goto(edit);
        await picker.selectOption({ label: size });
        await picker.selectOption({ label: sugar });
        await page.getByRole('button', { name: `Naikkan ${sugar}` }).click();
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page.getByText('Produk berhasil diperbarui')).toBeVisible();

        await page.goto(edit);
        await expect(attached).toContainText([sugar, size]);

        await page.getByLabel('Bahan baku (tidak dijual di kasir)').check();
        await expect(picker).toHaveCount(0);
    });
});
