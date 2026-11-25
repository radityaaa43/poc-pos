import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const byKey = (key) => roles.find((role) => role.key === key);

test.describe('50-raw-materials', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('list-per-warehouse: each material shows stock and average cost in every warehouse the user may use', {
        tag: ['@REQ-002-AC-20'],
    }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Kopi');
        arrange('purchasing.material', {
            title, purchased_by: 'central', min_stock: 2500,
            stock_central: 9950, avg_central: 265.5, stock_outlet: 3000, avg_outlet: 250,
        });
        const url = `/dashboard/raw-materials?search=${encodeURIComponent(title)}`;

        // Super admin works in both the central warehouse and the outlet's.
        const admin = await asRole(byKey('super-admin'));
        await admin.goto(url);
        await expect(admin.getByRole('columnheader', { name: /PUSAT/ })).toBeVisible();
        await expect(admin.getByRole('columnheader', { name: /WH-E2E/ })).toBeVisible();
        const adminRow = admin.getByRole('row', { name: new RegExp(title) });
        await expect(adminRow).toContainText('GRAM');
        await expect(adminRow).toContainText('Pusat');
        await expect(adminRow).toContainText('2.500');
        await expect(adminRow).toContainText(/9\.950\s*·\s*Rp\s*265,5/);
        await expect(adminRow).toContainText(/3\.000\s*·\s*Rp\s*250\b/);

        // The outlet manager only sees the outlet's warehouse.
        const manager = await asRole(byKey('manager'));
        await manager.goto(url);
        await expect(manager.getByRole('columnheader', { name: /WH-E2E/ })).toBeVisible();
        await expect(manager.getByRole('columnheader', { name: /PUSAT/ })).toHaveCount(0);
        const managerRow = manager.getByRole('row', { name: new RegExp(title) });
        await expect(managerRow).toContainText(/3\.000\s*·\s*Rp\s*250\b/);
        await expect(managerRow).not.toContainText('9.950');
    });
});
