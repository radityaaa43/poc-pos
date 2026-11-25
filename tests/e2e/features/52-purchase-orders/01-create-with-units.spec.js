import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

// Adds a product line on the Buat PO page and returns the table row of that line.
async function addLine(page, title) {
    await page.getByPlaceholder('Cari produk untuk ditambahkan...').fill(title);
    await page.getByRole('button', { name: new RegExp(title) }).click();

    return page.getByRole('row', { name: new RegExp(title) });
}

test.describe('52-purchase-orders', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('create-with-units: 5 kg of a material is ordered at the kg price and shows its gram equivalent', {
        tag: ['@REQ-002-AC-04'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Kopi');
        const { name } = arrange('purchasing.supplier', { name: uniq('Pemasok') });
        arrange('purchasing.material', { title, purchased_by: 'outlet' });
        const page = await asRole(manager);

        await page.goto('/dashboard/purchase-orders/create');
        await page.locator('select').filter({ hasText: 'Pilih Supplier' }).selectOption({ label: name });
        await page.locator('select').filter({ hasText: 'Pilih Gudang' }).selectOption({ label: 'WH-E2E — Gudang E2E' });
        const line = await addLine(page, title);

        // The default purchase unit and its price come with the product.
        const unit = line.getByLabel(`Satuan ${title}`);
        await expect(unit.locator('option:checked')).toHaveText('kg');
        const [qty, price] = [line.getByRole('spinbutton').nth(0), line.getByRole('spinbutton').nth(1)];
        await expect(price).toHaveValue('250000');
        await expect(line).toContainText('= 1.000 g');

        await qty.fill('5');
        await expect(line).toContainText('= 5.000 g');
        await expect(page.getByRole('cell', { name: /Rp\s*1\.250\.000/ }).first()).toBeVisible();

        // Another unit means another price: the gram has its own, Rp 250.
        await unit.selectOption({ label: 'g' });
        await expect(price).toHaveValue('250');
        await expect(line).not.toContainText('= ');
        await unit.selectOption({ label: 'kg' });
        await expect(price).toHaveValue('250000');

        await page.getByRole('button', { name: 'Simpan PO' }).click();

        // The saved order keeps the unit, the price and the conversion the server read from the product.
        await expect(page).toHaveURL(/purchase-orders\/\d+$/);
        const saved = page.getByRole('row', { name: new RegExp(title) });
        await expect(saved).toContainText('5 kg');
        await expect(saved).toContainText('= 5.000 g');
        await expect(saved).toContainText(/Rp\s*250\.000\s*\/ kg/);
        await expect(page.getByText('Draft', { exact: true })).toBeVisible();
    });

    test('create-with-units: a product bought in cartons defaults to its buy price x 24', {
        tag: ['@REQ-002-AC-17'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Air mineral');
        arrange('purchasing.carton-product', { title });
        const page = await asRole(manager);

        await page.goto('/dashboard/purchase-orders/create');
        const line = await addLine(page, title);
        const price = line.getByRole('spinbutton').nth(1);
        await expect(price).toHaveValue('5000');

        await line.getByLabel(`Satuan ${title}`).selectOption({ label: 'karton' });

        await expect(price).toHaveValue('120000');
        await expect(line).toContainText('= 24 pcs');
    });

    test('create-with-units: an order without items is refused', async ({ asRole }) => {
        const page = await asRole(manager);

        await page.goto('/dashboard/purchase-orders/create');
        await page.getByRole('button', { name: 'Simpan PO' }).click();

        await expect(page.getByText('Tambahkan minimal satu item.')).toBeVisible();
        await expect(page).toHaveURL(/purchase-orders\/create/);
    });
});
