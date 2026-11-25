import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

// The figure under a label of the payable summary ("Total", "Terbayar", "Sisa").
const figure = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::p[1]');

test.describe('54-supplier-returns', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('return-from-receiving: choosing the supplier lists its receivings, and 1 kg of 2 kg goes back', {
        tag: ['@REQ-002-AC-09', '@REQ-002-AC-10'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Kopi');
        const { material_id } = arrange('purchasing.material', { title, purchased_by: 'outlet' });
        const mine = arrange('purchasing.po', { material_id, qty: 5, receive: 2 });
        // Another supplier's receiving must not be offered.
        const other = arrange('purchasing.po', { material_id, qty: 1, receive: 1 });
        const page = await asRole(manager);

        await page.goto('/dashboard/supplier-returns/create');
        const receivings = page.locator('select').filter({ hasText: 'Tidak terkait GR' });
        await expect(receivings).toBeDisabled();

        // AC-09: the supplier's receivings appear without typing any address.
        await page.locator('select').filter({ hasText: 'Pilih Supplier' }).selectOption({ label: mine.supplier });
        await expect(receivings).toBeEnabled({ timeout: 45_000 });
        await expect(receivings.locator('option', { hasText: mine.gr_document_number })).toHaveCount(1, { timeout: 45_000 });
        await expect(receivings.locator('option', { hasText: other.gr_document_number })).toHaveCount(0);
        await receivings.selectOption({ label: `${mine.gr_document_number} (1 item)` });

        // The line comes with its unit, what is left to return and its price per kg.
        await page.getByRole('button', { name: new RegExp(`${title}.*Sisa 2 kg.*Rp.250.000 / kg`) }).click();
        const qty = page.getByLabel(`Qty ${title}`);
        await expect(qty).toHaveValue('1');
        await page.getByPlaceholder('Alasan retur').fill('Kemasan rusak');
        await page.getByRole('button', { name: 'Simpan Retur' }).click();

        await expect(page).toHaveURL(/supplier-returns\/\d+$/);
        await expect(page.getByText('Draft', { exact: true }).first()).toBeVisible();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await expect(page.getByText('Retur supplier berhasil diselesaikan')).toBeVisible();

        // AC-10: the warehouse lost 1.000 g, the average cost stayed at Rp 250.
        await page.goto(`/dashboard/raw-materials?search=${encodeURIComponent(title)}`);
        await expect(page.getByRole('row', { name: new RegExp(title) })).toContainText(/2\.000\s*·\s*Rp\s*250(?!,)/);
        await page.goto('/dashboard/stock-mutations');
        await page.locator('select').filter({ hasText: 'Semua Produk' }).selectOption({ label: title });
        await expect(page.getByRole('row', { name: new RegExp(`${title}.* out 1000 3000 → 2000 Gudang E2E`) })).toBeVisible();

        // ...and the debt fell by 1 kg x Rp 250.000.
        await page.goto(`/dashboard/payables/${mine.payable_id}`);
        await expect(figure(page, 'Total')).toHaveText(/Rp\s*250\.000/);
    });

    test('return-from-receiving: a second receipt keeps the completed return out of the debt', {
        tag: ['@REQ-002-AC-08'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Susu');
        const { material_id } = arrange('purchasing.material', { title, purchased_by: 'outlet' });
        const order = arrange('purchasing.po', { material_id, qty: 5, receive: 2 });
        arrange('purchasing.return', { gr_id: order.gr_id, qty: 1, complete: 1 });
        const page = await asRole(manager);

        await page.goto('/dashboard/goods-receivings/create');
        await page.locator('select').filter({ hasText: 'Pilih PO yang sudah dipesan' })
            .selectOption({ label: `${order.document_number} - ${order.supplier}` });
        await page.getByRole('button', { name: 'Konfirmasi Penerimaan' }).click();
        await expect(page.getByText('Penerimaan barang berhasil dicatat')).toBeVisible();

        // 5 kg x Rp 250.000 received minus the 1 kg returned.
        await page.goto(`/dashboard/payables/${order.payable_id}`);
        await expect(figure(page, 'Total')).toHaveText(/Rp\s*1\.000\.000/);
    });
});
