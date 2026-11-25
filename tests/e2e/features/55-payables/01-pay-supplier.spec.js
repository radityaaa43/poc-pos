import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

// The figure under a label of the payable summary ("Total", "Terbayar", "Sisa").
const figure = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::p[1]');

async function pay(page, amount) {
    await page.getByRole('button', { name: 'Tambah Pembayaran' }).click();
    await page.getByRole('spinbutton').fill(String(amount));
    await page.getByRole('button', { name: 'Simpan Pembayaran' }).click();
}

test.describe('55-payables', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('pay-supplier: the debt from a receiving is paid in parts until it is settled', async ({ asRole, arrange, uniq }) => {
        const { material_id } = arrange('purchasing.material', { title: uniq('Garam'), purchased_by: 'outlet' });
        // 2 kg at Rp 250.000 received: the debt is Rp 500.000, due in 30 days.
        const order = arrange('purchasing.po', { material_id, qty: 2, receive: 2 });
        const page = await asRole(manager);

        // The list finds it by document number: unpaid, with the supplier and the amount.
        await page.goto('/dashboard/payables');
        await page.getByPlaceholder('Cari nomor dokumen').fill(order.gr_document_number);
        await page.getByRole('button', { name: 'Terapkan' }).click();
        const row = page.getByRole('link', { name: new RegExp(order.gr_document_number) }).first();
        await expect(row).toContainText(order.supplier);
        await expect(row).toContainText(/Rp\s*500\.000/);
        await expect(row).toContainText('Belum Lunas');
        await row.click();

        await expect(page).toHaveURL(new RegExp(`payables/${order.payable_id}$`));
        await expect(figure(page, 'Sisa')).toHaveText(/Rp\s*500\.000/);

        // Paying more than what is owed is refused.
        await pay(page, 600000);
        await expect(page.getByText('Nominal melebihi sisa hutang.')).toBeVisible();
        await expect(figure(page, 'Terbayar')).toHaveText(/Rp\s*0/);

        // A part payment makes it partial...
        await page.getByRole('spinbutton').fill('200000');
        await page.getByRole('button', { name: 'Simpan Pembayaran' }).click();
        await expect(figure(page, 'Terbayar')).toHaveText(/Rp\s*200\.000/);
        await expect(figure(page, 'Sisa')).toHaveText(/Rp\s*300\.000/);
        await expect(page.getByText('Parsial', { exact: true }).first()).toBeVisible();

        // ...and the rest settles it: no more payment button.
        await pay(page, 300000);
        await expect(figure(page, 'Sisa')).toHaveText(/Rp\s*0/);
        await expect(page.getByText('Lunas', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Tambah Pembayaran' })).toHaveCount(0);

        // The list follows: settled under Lunas, gone from Belum Lunas.
        await page.goto('/dashboard/payables');
        await page.getByPlaceholder('Cari nomor dokumen').fill(order.gr_document_number);
        await page.locator('select').filter({ hasText: 'Semua Status' }).selectOption('paid');
        await page.getByRole('button', { name: 'Terapkan' }).click();
        await expect(page.getByRole('link', { name: new RegExp(order.gr_document_number) }).first()).toContainText('Lunas');
        await page.locator('select').filter({ hasText: 'Semua Status' }).selectOption('unpaid');
        await page.getByRole('button', { name: 'Terapkan' }).click();
        await expect(page.getByText('Belum ada data hutang.').first()).toBeVisible();
    });
});
