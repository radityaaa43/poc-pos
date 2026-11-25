import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('customer segments: edit, delete and roles', () => {
    test('a manager renames then deletes a segment; a cashier has no access', async ({ asRole, arrange, uniq }) => {
        const [name, newName] = [uniq('Segmen Lama'), uniq('Segmen Baru')];
        const { segment_id } = arrange('crm.segment', { name });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/customer-segments');
        await manager.getByPlaceholder('Cari nama segment...').fill(name);
        await manager.getByRole('row').filter({ hasText: name }).locator('a[href*="/edit"]').click();
        await expect(field(manager, 'Nama Segment')).toHaveValue(name);
        await field(manager, 'Nama Segment').fill(newName);
        await manager.getByRole('button', { name: 'Simpan Segment' }).click();
        await expect(manager).toHaveURL(new RegExp(`customer-segments/${segment_id}$`));
        await expect(manager.getByRole('heading', { name: newName })).toBeVisible();

        const cashier = await asRole(roleOf(roles, 'cashier'));
        expect((await cashier.goto('/dashboard/customer-segments')).status()).toBe(403);

        await manager.goto('/dashboard/customer-segments');
        await manager.getByPlaceholder('Cari nama segment...').fill(newName);
        const row = manager.getByRole('row').filter({ hasText: newName });
        await row.locator('button').click();
        await manager.getByRole('button', { name: 'Ya, Hapus!' }).click();
        await expect(row).toHaveCount(0);
    });
});
