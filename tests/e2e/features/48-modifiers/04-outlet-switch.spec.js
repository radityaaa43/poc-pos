import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

test.describe('48-modifiers: per-outlet switch', () => {
    // The POS and the QR menu read the catalog this step queries; their screens come with the next REQ-008 tickets.
    test('an option switched off at one outlet is not offered there, and stays on elsewhere', { tag: ['@REQ-008-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        const outlet = arrange('modifier-outlet');
        const product = arrange('modifier-menu', { title: uniq('Teh') }).product_id;
        const group = arrange('modifier-group', { name: uniq('Ukuran'), product }).name;
        const page = await asRole(role('manager'));
        const edit = page.getByRole('button', { name: `Ubah grup ${group}` });
        const large = page.getByRole('group', { name: 'Opsi 2', exact: true }).getByRole('switch', { name: outlet.name });

        await page.goto('/dashboard/settings/modifiers');
        await edit.click();
        await expect(large).toBeChecked();
        await large.click();
        await expect(large).not.toBeChecked();
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(edit).toBeVisible();
        expect(arrange('modifier-catalog', { product, outlet: outlet.code }).options).toEqual(['Regular']);
        expect(arrange('modifier-catalog', { product, outlet: 'E2E' }).options).toEqual(['Regular', 'Large']);
        await edit.click();
        await expect(large).not.toBeChecked();
    });
});
