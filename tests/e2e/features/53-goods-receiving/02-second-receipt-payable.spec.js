import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

// The figure under a label of the payable summary ("Total", "Terbayar", "Sisa").
const figure = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::p[1]');

test.describe('53-goods-receiving', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('second-receipt-payable: a second receipt recomputes the debt and keeps what was already paid', {
        tag: ['@REQ-002-AC-08'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Gula');
        const { material_id } = arrange('purchasing.material', { title, purchased_by: 'outlet' });
        // 5 kg at Rp 250.000, 2 kg received: the debt is Rp 500.000.
        const order = arrange('purchasing.po', { material_id, qty: 5, receive: 2 });
        const page = await asRole(manager);

        await page.goto(`/dashboard/payables/${order.payable_id}`);
        await expect(figure(page, 'Total')).toHaveText(/Rp\s*500\.000/);

        // Pay Rp 100.000 of it.
        await page.getByRole('button', { name: 'Tambah Pembayaran' }).click();
        await page.getByRole('spinbutton').fill('100000');
        await page.getByRole('button', { name: 'Simpan Pembayaran' }).click();
        await expect(figure(page, 'Terbayar')).toHaveText(/Rp\s*100\.000/);
        await expect(figure(page, 'Sisa')).toHaveText(/Rp\s*400\.000/);
        await expect(page.getByText('Parsial', { exact: true }).first()).toBeVisible();

        // The remaining 3 kg arrive.
        await page.goto('/dashboard/goods-receivings/create');
        await page.locator('select').filter({ hasText: 'Pilih PO yang sudah dipesan' })
            .selectOption({ label: `${order.document_number} - ${order.supplier}` });
        await expect(page.getByRole('spinbutton').first()).toHaveValue('3');
        await page.getByRole('button', { name: 'Konfirmasi Penerimaan' }).click();
        await expect(page.getByText('Penerimaan barang berhasil dicatat')).toBeVisible();

        // The debt covers all 5 kg now; the Rp 100.000 payment is still there and the status follows.
        await page.goto(`/dashboard/payables/${order.payable_id}`);
        await expect(figure(page, 'Total')).toHaveText(/Rp\s*1\.250\.000/);
        await expect(figure(page, 'Terbayar')).toHaveText(/Rp\s*100\.000/);
        await expect(figure(page, 'Sisa')).toHaveText(/Rp\s*1\.150\.000/);
        await expect(page.getByText('Parsial', { exact: true }).first()).toBeVisible();
        await expect(page.getByText(/• cash/)).toBeVisible();

        // The order is complete and links to the same debt.
        await page.goto(`/dashboard/purchase-orders/${order.po_id}`);
        await expect(page.getByText('Selesai', { exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Hutang Supplier' })).toBeVisible();
    });
});
