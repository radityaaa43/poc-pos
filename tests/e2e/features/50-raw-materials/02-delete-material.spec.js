import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

// The list is filtered by title so the row is always on the first page.
async function openList(asRole, title) {
    const page = await asRole(manager);
    await page.goto(`/dashboard/raw-materials?search=${encodeURIComponent(title)}`);
    await expect(page.getByRole('row', { name: new RegExp(title) })).toBeVisible();

    return page;
}

async function confirmDelete(page, title) {
    await page.getByRole('button', { name: `Hapus ${title}` }).click();
    await page.getByRole('button', { name: 'Ya, Hapus!' }).click();
}

test.describe('50-raw-materials', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('delete-material: refused while a recipe uses the material, and says why', {
        tag: ['@REQ-002-AC-03'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Susu');
        arrange('purchasing.material', { title, recipe: 1, stock_outlet: 1000, opening: 1 });
        const page = await openList(asRole, title);

        await confirmDelete(page, title);

        await expect(page.getByText('Bahan baku tidak bisa dihapus karena dipakai sebagai komponen resep.')).toBeVisible();
        await expect(page.getByRole('row', { name: new RegExp(title) })).toBeVisible();
    });

    test('delete-material: refused once the material was bought', {
        tag: ['@REQ-002-AC-03'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Gula');
        const { material_id } = arrange('purchasing.material', { title });
        arrange('purchasing.po', { material_id, receive: 2 });
        const page = await openList(asRole, title);

        await confirmDelete(page, title);

        await expect(page.getByText(/sudah punya riwayat stok \(pembelian, penjualan, transfer, atau opname\)/)).toBeVisible();
        await expect(page.getByRole('row', { name: new RegExp(title) })).toBeVisible();
    });

    test('delete-material: a material with only an opening stock can be deleted', {
        tag: ['@REQ-002-AC-03'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Teh');
        arrange('purchasing.material', { title, stock_outlet: 500, opening: 1 });
        const page = await openList(asRole, title);

        await confirmDelete(page, title);

        await expect(page.getByText('Bahan baku berhasil dihapus')).toBeVisible();
        await expect(page.getByRole('row', { name: new RegExp(title) })).toHaveCount(0);
    });
});
