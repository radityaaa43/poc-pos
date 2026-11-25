import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('CRM campaigns: edit, delete and roles', () => {
    test('a manager renames and deletes a campaign; a cashier cannot open campaigns', async ({ asRole, arrange, uniq }) => {
        const [name, newName] = [uniq('Lama'), uniq('Baru')];
        const { campaign_id } = arrange('crm.campaign', { name, status: 'draft' });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/crm-campaigns');
        await manager.getByRole('row').filter({ hasText: name }).locator('a[href*="/edit"]').click();
        await expect(field(manager, 'Nama Campaign')).toHaveValue(name);
        await field(manager, 'Nama Campaign').fill(newName);
        await manager.getByRole('button', { name: 'Simpan Campaign' }).click();
        await expect(manager).toHaveURL(new RegExp(`crm-campaigns/${campaign_id}$`));
        await expect(manager.getByRole('heading', { name: newName })).toBeVisible();

        const cashier = await asRole(roleOf(roles, 'cashier'));
        expect((await cashier.goto('/dashboard/crm-campaigns')).status()).toBe(403);
        expect((await cashier.goto(`/dashboard/crm-campaigns/${campaign_id}`)).status()).toBe(403);

        await manager.goto('/dashboard/crm-campaigns');
        const row = manager.getByRole('row').filter({ hasText: newName });
        await row.locator('button').click();
        await manager.getByRole('button', { name: 'Ya, Hapus!' }).click();
        await expect(row).toHaveCount(0);
    });
});
