import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const admin = roles.find((role) => role.key === 'super-admin');

// Drafts a transfer of `qty` grams of the material from the central warehouse to the outlet's, and opens it.
async function draftTransfer(page, title, qty) {
    await page.goto('/dashboard/stock-transfers/create');
    await page.locator('select').filter({ hasText: 'Pilih Gudang' }).first().selectOption({ label: 'PUSAT — Gudang Pusat' });
    await page.locator('select').filter({ hasText: 'Pilih Gudang' }).last().selectOption({ label: 'WH-E2E — Gudang E2E' });
    await page.getByPlaceholder('Cari produk untuk ditambahkan...').fill(title);
    await page.getByRole('button', { name: new RegExp(title) }).click();
    await page.getByRole('spinbutton').fill(String(qty));
    await page.getByRole('button', { name: 'Simpan Draft' }).click();
    await expect(page).toHaveURL(/stock-transfers\/\d+$/);
}

// The stock-and-cost cell of one warehouse column on the Bahan Baku list.
async function stockCell(page, title, warehouse) {
    await page.goto(`/dashboard/raw-materials?search=${encodeURIComponent(title)}`);
    await expect(page.getByRole('row', { name: new RegExp(title) })).toBeVisible();
    const column = (await page.getByRole('columnheader').filter({ hasText: /Stok · Harga pokok/ }).evaluateAll(
        (heads, code) => heads.findIndex((head) => head.textContent.startsWith(code)), warehouse,
    ));

    return page.getByRole('row', { name: new RegExp(title) }).getByRole('cell').nth(6 + column);
}

test.describe('56-transfer-cost', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('merge-cost: a received transfer blends the central cost into the outlet average', {
        tag: ['@REQ-002-AC-12'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Kopi');
        // Central holds 10.000 g at Rp 265; the outlet 1.000 g at Rp 250.
        arrange('purchasing.material', { title, stock_central: 10000, avg_central: 265, stock_outlet: 1000, avg_outlet: 250 });
        const page = await asRole(admin);

        await draftTransfer(page, title, 2000);
        page.on('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'Kirim Barang' }).click();
        await expect(page.getByText('In Transit', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Terima Barang' }).click();
        await expect(page.getByText('Selesai', { exact: true }).first()).toBeVisible();

        // Outlet: (1.000 x 250 + 2.000 x 265) / 3.000 = Rp 260. Central: 8.000 g, still Rp 265.
        await expect(await stockCell(page, title, 'WH-E2E')).toContainText(/3\.000\s*·\s*Rp\s*260(?!,)/);
        await expect(await stockCell(page, title, 'PUSAT')).toContainText(/8\.000\s*·\s*Rp\s*265(?!,)/);
    });

    test('merge-cost: cancelling a transfer in transit returns the stock at the cost it left with', {
        tag: ['@REQ-002-AC-12'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Susu');
        const { material_id } = arrange('purchasing.material', { title, stock_central: 10000, avg_central: 265 });
        const page = await asRole(admin);

        await draftTransfer(page, title, 2000);
        page.on('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'Kirim Barang' }).click();
        await expect(page.getByText('In Transit', { exact: true })).toBeVisible();

        // While it travels the central warehouse buys 2 kg at Rp 300 per gram: 8.000 g at 265 + 2.000 g at 300 = Rp 272.
        arrange('purchasing.po', { material_id, qty: 2, price: 300000, receive: 2 });
        await expect(await stockCell(page, title, 'PUSAT')).toContainText(/10\.000\s*·\s*Rp\s*272(?!,)/);

        // Cancelling brings the 2.000 g back at Rp 265: (10.000 x 272 + 2.000 x 265) / 12.000 = Rp 270,83.
        await page.goBack();
        await page.getByRole('button', { name: 'Batalkan & Kembalikan' }).click();
        await expect(page.getByText('Batal', { exact: true })).toBeVisible();
        await expect(await stockCell(page, title, 'PUSAT')).toContainText(/12\.000\s*·\s*Rp\s*270,83/);
    });
});
