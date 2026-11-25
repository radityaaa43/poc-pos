import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

// An outlet-bought material, so the manager's outlet may order it.
const draftOrder = (arrange, status = 'draft') => {
    const { material_id } = arrange('purchasing.material', { purchased_by: 'outlet' });

    return arrange('purchasing.po', { material_id, status });
};

test.describe('52-purchase-orders', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('place-and-cancel: a draft is placed, then receiving opens; a cancelled order closes', async ({ asRole, arrange }) => {
        const { po_id, document_number } = draftOrder(arrange);
        const page = await asRole(manager);

        // Draft: it can be placed or cancelled, not received.
        await page.goto(`/dashboard/purchase-orders/${po_id}`);
        await expect(page.getByRole('heading', { name: document_number })).toBeVisible();
        await expect(page.getByText('Draft', { exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Terima Barang' })).toHaveCount(0);

        await page.getByRole('button', { name: 'Pesan ke Supplier' }).click();
        await expect(page.getByText('PO berhasil dipesan')).toBeVisible();
        await expect(page.getByText('Dipesan', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Pesan ke Supplier' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Terima Barang' })).toBeVisible();

        // Cancelling leads back to the list, where the order shows its new status.
        await page.getByRole('button', { name: 'Batalkan PO' }).click();
        await expect(page.getByText('PO dibatalkan')).toBeVisible();
        await expect(page.getByRole('row', { name: new RegExp(document_number) })).toContainText('Dibatalkan');

        await page.goto(`/dashboard/purchase-orders/${po_id}`);
        await expect(page.getByRole('button', { name: 'Batalkan PO' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Terima Barang' })).toHaveCount(0);
    });

    test('place-and-cancel: a cancelled order is not offered for receiving', async ({ asRole, arrange }) => {
        const { po_id, document_number } = draftOrder(arrange, 'ordered');
        const page = await asRole(manager);

        await page.goto(`/dashboard/purchase-orders/${po_id}`);
        await page.getByRole('button', { name: 'Batalkan PO' }).click();
        await expect(page.getByText('PO dibatalkan')).toBeVisible();

        await page.goto('/dashboard/goods-receivings/create');
        const options = await page.locator('select').filter({ hasText: 'Pilih PO yang sudah dipesan' }).locator('option').allTextContents();
        expect(options.some((text) => text.includes(document_number))).toBe(false);
    });
});
