import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');
const opname = roles.find((role) => role.key === 'stock-opname');

test.describe('32-kitchen-display', () => {
    test('a role that neither prepares nor hands over is refused and sees no menu entry', async ({ asRole }) => {
        // The cashier prepares and hands over: its menu entry exists, so the other role's missing one means something.
        const allowed = await asRole(cashier);
        await allowed.goto('/dashboard/kitchen');
        await expect(allowed.getByRole('link', { name: 'Layar Dapur', exact: true })).toBeVisible();

        const page = await asRole(opname);
        await page.goto(opname.landing);
        await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Layar Dapur', exact: true })).toHaveCount(0);

        const response = await page.goto('/dashboard/kitchen');
        expect(response.status()).toBe(403);
    });
});
