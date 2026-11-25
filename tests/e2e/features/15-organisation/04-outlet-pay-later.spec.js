import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { confirmPassword, signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

const cashier = roles.find((role) => role.key === 'cashier');

// REQ-003 AC-25 (outlet half): Bayar Belakangan is switched per outlet by someone with outlets-update, and the change is audited.
test.describe('outlet pay later setting', () => {
    test.setTimeout(240_000);

    test('@REQ-003-AC-25 an admin turns Bayar Belakangan on for an outlet and it is audited; a cashier cannot open the page', async ({ page, asRole, arrange }) => {
        arrange('paylater.setting', { enabled: 0 });
        const before = arrange('paylater.outlet-state', {});
        expect(before.enabled).toBe(false);
        const admin = arrange('platform.user', { role: 'super-admin' });

        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/settings/outlets');
        const row = page.locator('div.p-4').filter({ has: page.getByRole('heading', { name: 'Outlet E2E', level: 2 }) });
        await row.getByRole('button').first().click();
        await page.getByLabel('Bayar Belakangan').check();
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page.getByRole('heading', { name: 'Outlet E2E', level: 2 })).toBeVisible();

        const after = arrange('paylater.outlet-state', {});
        expect(after).toEqual({ enabled: true, audits: before.audits + 1 });

        // Saving again without a change writes no second audit row.
        await row.getByRole('button').first().click();
        await expect(page.getByLabel('Bayar Belakangan')).toBeChecked();
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page.getByRole('heading', { name: 'Outlet E2E', level: 2 })).toBeVisible();
        expect(arrange('paylater.outlet-state', {})).toEqual(after);

        // A cashier has no outlets-update.
        const cashierPage = await asRole(cashier);
        const response = await cashierPage.goto('/dashboard/settings/outlets');
        expect(response.status()).toBe(403);

        arrange('paylater.setting', { enabled: 0 }); // leave the outlet as the other steps expect it
    });
});
