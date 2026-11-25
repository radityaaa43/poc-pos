import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { code as newCode, roleOf } from '../../support/crm.js';

test.describe('customer vouchers: delete and roles', () => {
    test('a manager deletes a voucher; a cashier cannot open the voucher pages', async ({ asRole, arrange, uniq }) => {
        const code = newCode('DEL');
        const { customer_id } = arrange('crm.customer', { name: uniq('Penerima'), member: 1 });
        arrange('crm.voucher', { customer_id, code, name: uniq('Voucher Hapus') });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/customer-vouchers');
        await manager.getByPlaceholder('Cari kode, voucher, pelanggan...').fill(code);
        const row = manager.getByRole('row').filter({ hasText: code });
        await expect(row).toBeVisible();
        await row.getByRole('button').click();
        await manager.getByRole('button', { name: 'Ya, Hapus!' }).click();
        await expect(row).toHaveCount(0);

        const cashier = await asRole(roleOf(roles, 'cashier'));
        expect((await cashier.goto('/dashboard/customer-vouchers')).status()).toBe(403);
        expect((await cashier.goto('/dashboard/customer-vouchers/create')).status()).toBe(403);
    });
});
