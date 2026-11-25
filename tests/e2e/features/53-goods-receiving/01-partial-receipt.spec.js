import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { warehouseCell } from '../../support/inventory.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const admin = roles.find((role) => role.key === 'super-admin');

// Receives `qty` of the single line of an order on the Terima Barang page.
async function receive(page, order, qty) {
    await page.goto('/dashboard/goods-receivings/create');
    await page.locator('select').filter({ hasText: 'Pilih PO yang sudah dipesan' }).selectOption({ label: `${order.document_number} - ${order.supplier}` });
    const qtyInput = page.getByRole('spinbutton').first();
    await qtyInput.fill(String(qty));
    await page.getByRole('button', { name: 'Konfirmasi Penerimaan' }).click();
    await expect(page.getByText('Penerimaan barang berhasil dicatat')).toBeVisible();
}

test.describe('53-goods-receiving', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('partial-receipt: receiving 2 kg of a 5 kg order adds 2.000 g and leaves 3 kg open', {
        tag: ['@REQ-002-AC-05'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Susu');
        const { material_id } = arrange('purchasing.material', { title });
        const order = arrange('purchasing.po', { material_id, qty: 5 });
        const page = await asRole(admin);

        await page.goto('/dashboard/goods-receivings/create');
        await page.locator('select').filter({ hasText: 'Pilih PO yang sudah dipesan' }).selectOption({ label: `${order.document_number} - ${order.supplier}` });
        // Everything outstanding is proposed; the receiver types what actually arrived.
        await expect(page.getByRole('spinbutton').first()).toHaveValue('5');
        await page.getByRole('spinbutton').first().fill('2');
        await expect(page.getByText('= 2.000 g')).toBeVisible();
        await page.getByRole('button', { name: 'Konfirmasi Penerimaan' }).click();
        await expect(page.getByText('Penerimaan barang berhasil dicatat')).toBeVisible();

        // The order is partly received: 2 of 5 kg, 3 kg left.
        await page.goto(`/dashboard/purchase-orders/${order.po_id}`);
        await expect(page.getByText('Sebagian Diterima', { exact: true })).toBeVisible();
        const line = page.getByRole('row', { name: new RegExp(title) });
        await expect(line).toContainText('5 kg');
        await expect(line.getByRole('cell').nth(2)).toHaveText('2 kg');
        await expect(line.getByRole('cell').nth(3)).toHaveText('3 kg');

        // The central warehouse holds 2.000 g.
        await page.goto(`/dashboard/raw-materials?search=${encodeURIComponent(title)}`);
        await expect(await warehouseCell(page, page.getByRole('row', { name: new RegExp(title) }), /PUSAT/)).toContainText(/2\.000\s*·/);

        // The stock history records 2.000 g in, the global stock going 0 -> 2000.
        await page.goto('/dashboard/stock-mutations');
        await page.locator('select').filter({ hasText: 'Semua Produk' }).selectOption({ label: title });
        await expect(page.getByRole('row', { name: new RegExp(`${title}.* in 2000 0 → 2000 Gudang Pusat`) })).toBeVisible();
    });

    test('partial-receipt: 5 kg at Rp 280.000 on 4.950 g at Rp 250 gives Rp 265,08 per gram in that warehouse only', {
        tag: ['@REQ-002-AC-06', '@REQ-002-AC-07'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Kopi');
        const { material_id } = arrange('purchasing.material', {
            title, stock_central: 4950, stock_outlet: 3000,
        });
        const order = arrange('purchasing.po', { material_id, qty: 5, price: 280000 });
        const page = await asRole(admin);

        await receive(page, order, 5);

        // (4.950 x 250 + 5.000 x 280) / 9.950 = 265,0754: the central warehouse only.
        await page.goto(`/dashboard/raw-materials?search=${encodeURIComponent(title)}`);
        const row = page.getByRole('row', { name: new RegExp(title) });
        await expect(await warehouseCell(page, row, /PUSAT/)).toContainText(/9\.950\s*·\s*Rp\s*265,08/);
        await expect(await warehouseCell(page, row, /WH-E2E/)).toContainText(/3\.000\s*·\s*Rp\s*250(?!,)/);

        // AC-07: the new price is the purchase unit's price and the reference price per gram.
        await row.getByRole('link').first().click();
        await expect(page.getByLabel('Harga per satuan beli')).toHaveValue('280000');
        await expect(page.getByText(/Harga acuan: Rp\s*280 per GRAM/)).toBeVisible();

        // ...and the next order proposes it.
        await page.goto('/dashboard/purchase-orders/create');
        await page.getByPlaceholder('Cari produk untuk ditambahkan...').fill(title);
        await page.getByRole('button', { name: new RegExp(title) }).click();
        await expect(page.getByRole('row', { name: new RegExp(title) }).getByRole('spinbutton').nth(1)).toHaveValue('280000');
    });

    test('partial-receipt: only open orders can be received and the quantity cannot pass what is left', async ({ asRole, arrange }) => {
        const { material_id } = arrange('purchasing.material', {});
        const draft = arrange('purchasing.po', { material_id, status: 'draft' });
        const open = arrange('purchasing.po', { material_id, qty: 5 });
        const page = await asRole(admin);

        await page.goto('/dashboard/goods-receivings/create');
        const options = await page.locator('select').filter({ hasText: 'Pilih PO yang sudah dipesan' }).locator('option').allTextContents();
        expect(options.some((text) => text.includes(draft.document_number))).toBe(false);

        await page.locator('select').filter({ hasText: 'Pilih PO yang sudah dipesan' }).selectOption({ label: `${open.document_number} - ${open.supplier}` });
        const qty = page.getByRole('spinbutton').first();
        await qty.fill('9');
        await expect(qty).toHaveValue('5');
    });
});
