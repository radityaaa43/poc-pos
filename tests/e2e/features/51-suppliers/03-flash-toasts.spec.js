import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('51-suppliers', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    // Bug #104: the flash prop is never shared, so the supplier page cannot show its confirmation toasts.
    test.fixme('flash-toasts: saving a supplier and a refused delete both tell the user', async ({ asRole, arrange, uniq }) => {
        const name = uniq('Toko Toast');
        const page = await asRole(manager);
        await page.goto('/dashboard/suppliers');

        await page.locator('form').getByRole('textbox').first().fill(name);
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('Supplier berhasil ditambahkan.')).toBeVisible();

        const { material_id } = arrange('purchasing.material', { purchased_by: 'outlet' });
        const { supplier } = arrange('purchasing.po', { material_id, receive: 1 });
        await page.reload();
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('div.p-4').filter({ hasText: supplier }).getByRole('button').last().click();
        await expect(page.getByText('Supplier memiliki hutang, tidak dapat dihapus.')).toBeVisible();
    });
});
