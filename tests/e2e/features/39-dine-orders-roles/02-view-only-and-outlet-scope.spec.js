import { test, expect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');
const superAdmin = roles.find((role) => role.key === 'super-admin');

test.describe('39-dine-orders-roles', () => {
    test('a user who may only view orders sees them without Terima and Tolak', async ({ page, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Soto'), stock: 5 });
        arrange('dine.order', { token, product_id, qty: 1 });
        const { email } = arrange('dine.user', { email: `${uniq('viewer')}@e2e.test`, permissions: 'dine-orders-access' });

        await login(page, email);
        await page.waitForURL((url) => !url.pathname.endsWith('/login'), { waitUntil: 'commit', timeout: 60_000 });
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        await expect(card).toContainText('Menunggu');
        await expect(page.getByRole('button', { name: 'Terima' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Tolak' })).toHaveCount(0);
    });

    test('a user who may only view tables cannot add, edit or delete, but can open the QR', async ({ page, arrange, uniq }) => {
        const { table_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { email } = arrange('dine.user', { email: `${uniq('viewer')}@e2e.test`, permissions: 'dine-tables-access' });

        await login(page, email);
        await page.waitForURL((url) => !url.pathname.endsWith('/login'), { waitUntil: 'commit', timeout: 60_000 });
        await page.goto('/dashboard/dine-tables');
        await page.getByRole('button', { name: 'Daftar' }).click();
        await expect(page.getByRole('row').filter({ hasText: table_name })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Tambah Meja' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: `Edit ${table_name}` })).toHaveCount(0);
        await expect(page.getByRole('button', { name: `Hapus ${table_name}` })).toHaveCount(0);
        await expect(page.getByRole('button', { name: `Buka QR ${table_name}` })).toBeVisible();

        // Areas page: the list opens, but "Tambah Area" is for dine-tables-create only.
        await page.goto('/dashboard/dine-areas');
        await expect(page.getByText(/area terdaftar/)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Tambah Area' })).toHaveCount(0);
    });

    test('orders of another outlet are hidden from a manager of this outlet, but not from the super admin', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('MP'), outlet: 'PUSAT' });
        const { product_id } = arrange('product', { title: uniq('Rawon'), stock: 5 });
        arrange('dine.order', { token, product_id, qty: 1 });

        const managerPage = await asRole(manager);
        await managerPage.goto('/dashboard/dine-orders');
        await expect(managerPage.getByText(/\d+ pesanan/)).toBeVisible();
        await expect(managerPage.locator('div.rounded-2xl', { hasText: table_name })).toHaveCount(0);

        const adminPage = await asRole(superAdmin);
        await adminPage.goto('/dashboard/dine-orders');
        await expect(adminPage.locator('div.rounded-2xl', { hasText: table_name })).toContainText('Menunggu');
    });
});
