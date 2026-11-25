import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('54-supplier-returns', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('over-return: a return cannot exceed what is left of the receiving line', {
        tag: ['@REQ-002-AC-11'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Teh');
        const { material_id } = arrange('purchasing.material', { title, purchased_by: 'outlet' });
        const order = arrange('purchasing.po', { material_id, qty: 2, receive: 2 });
        const page = await asRole(manager);

        // A form opened while 2 kg of the receiving can still be returned...
        await page.goto('/dashboard/supplier-returns/create');
        await page.locator('select').filter({ hasText: 'Pilih Supplier' }).selectOption({ label: order.supplier });
        await page.locator('select').filter({ hasText: 'Tidak terkait GR' }).selectOption({ label: `${order.gr_document_number} (1 item)` });
        await page.getByRole('button', { name: new RegExp(`${title}.*Sisa 2 kg`) }).click();
        const qty = page.getByLabel(`Qty ${title}`);
        await expect(qty).toHaveAttribute('max', '2');

        // ...while another return of 1 kg is saved meanwhile (it counts as soon as it is a draft).
        arrange('purchasing.return', { gr_id: order.gr_id, qty: 1 });

        // Returning 2 kg now is refused, saying how much is left.
        await qty.fill('2');
        await page.getByRole('button', { name: 'Simpan Retur' }).click();
        await expect(page.getByText('Qty retur melebihi sisa yang bisa diretur (1).')).toBeVisible();
        await expect(page).toHaveURL(/supplier-returns\/create/);

        // A fresh form only offers the 1 kg left, and the field stops at it.
        await page.reload();
        await page.locator('select').filter({ hasText: 'Pilih Supplier' }).selectOption({ label: order.supplier });
        await page.locator('select').filter({ hasText: 'Tidak terkait GR' }).selectOption({ label: `${order.gr_document_number} (1 item)` });
        await page.getByRole('button', { name: new RegExp(`${title}.*Sisa 1 kg`) }).click();
        await expect(page.getByLabel(`Qty ${title}`)).toHaveAttribute('max', '1');
    });

    test('over-return: a fully returned line is no longer offered', {
        tag: ['@REQ-002-AC-11'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Madu');
        const { material_id } = arrange('purchasing.material', { title, purchased_by: 'outlet' });
        const order = arrange('purchasing.po', { material_id, qty: 2, receive: 2 });
        arrange('purchasing.return', { gr_id: order.gr_id, qty: 2, complete: 1 });
        const page = await asRole(manager);

        await page.goto('/dashboard/supplier-returns/create');
        await page.locator('select').filter({ hasText: 'Pilih Supplier' }).selectOption({ label: order.supplier });
        await page.locator('select').filter({ hasText: 'Tidak terkait GR' }).selectOption({ label: `${order.gr_document_number} (1 item)` });

        await expect(page.getByText(`Item dari GR ${order.gr_document_number}`)).toBeVisible();
        await expect(page.getByRole('button', { name: new RegExp(title) })).toHaveCount(0);
    });
});
