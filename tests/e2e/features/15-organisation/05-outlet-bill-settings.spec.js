import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { confirmPassword, signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

const cashier = roles.find((role) => role.key === 'cashier');

// REQ-010 J1: the owner sets an outlet's bill rules in the Tagihan section of the outlet form.
test.describe('outlet bill settings', () => {
    test.setTimeout(240_000);

    // Every step starts and leaves the outlet as "never saved": other steps share the database.
    test.beforeEach(({ arrange }) => arrange('billsettings.reset', {}));
    test.afterEach(({ arrange }) => arrange('billsettings.reset', {}));

    // Signs in a new super-admin and returns the form controls of the E2E outlet.
    const openOutlets = async (page, arrange) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/settings/outlets');
        const row = page.locator('div.p-4').filter({ has: page.getByRole('heading', { name: 'Outlet E2E', level: 2 }) });
        const save = page.getByRole('button', { name: 'Simpan Perubahan' });

        return {
            admin,
            edit: async () => {
                await row.getByRole('button').first().click();
                await expect(page.getByRole('heading', { name: 'Tagihan', level: 3 })).toBeVisible();
            },
            save,
            // The form closes once the server has accepted it.
            saveAndClose: async () => {
                await save.click();
                await expect(save).toBeHidden();
            },
            service: page.getByLabel('Service charge (%)'),
            ppn: page.getByRole('radio', { name: /^PPN/ }),
            pb1: page.getByRole('radio', { name: /^PB1/ }),
            pb1Rate: page.getByLabel('Tarif PB1 (%)'),
            rounding: page.getByLabel('Pembulatan tunai'),
        };
    };

    test('@REQ-010-AC-03 a never-saved outlet has no service charge, PPN and no rounding, and saving it stores nothing', async ({ page, arrange }) => {
        const before = arrange('billsettings.state', {});
        expect(before.rows).toBe(0);
        const form = await openOutlets(page, arrange);

        await form.edit();
        await expect(form.service).toHaveValue('0');
        await expect(form.ppn).toBeChecked();
        await expect(form.pb1Rate).toBeDisabled();
        await expect(form.rounding).toHaveValue('0');
        await expect(form.rounding.locator('option:checked')).toHaveText('Tanpa');
        await form.saveAndClose();

        const after = arrange('billsettings.state', {});
        expect(after.rows).toBe(0);
        expect(after.audits).toBe(before.audits);
    });

    test('@REQ-010-AC-01 an admin sets the bill rules and sees them again; 101% is refused; a cashier cannot open the page', async ({ page, asRole, arrange }) => {
        const form = await openOutlets(page, arrange);

        await form.edit();
        await form.service.fill('101');
        await form.save.click();
        await expect(page.getByText('Service charge harus 0 sampai 100, paling banyak 2 angka desimal.')).toBeVisible();
        await expect(form.service).toHaveAttribute('aria-invalid', 'true');
        expect(arrange('billsettings.state', {}).rows).toBe(0);

        await form.service.fill('5');
        await form.pb1.check();
        await form.pb1Rate.fill('10.5');
        await form.rounding.selectOption('500');
        await form.saveAndClose();

        await page.reload();
        await form.edit();
        await expect(form.service).toHaveValue('5');
        await expect(form.pb1).toBeChecked();
        await expect(form.pb1Rate).toHaveValue('10.5');
        await expect(form.rounding).toHaveValue('500');

        // A cashier has no outlets-update.
        const cashierPage = await asRole(cashier);
        const response = await cashierPage.goto('/dashboard/settings/outlets');
        expect(response.status()).toBe(403);
    });

    test('@REQ-010-AC-04 a change is audited once with who, when, outlet, old and new; saving again adds nothing', async ({ page, arrange }) => {
        const before = arrange('billsettings.state', {});
        const form = await openOutlets(page, arrange);

        await form.edit();
        await form.service.fill('5');
        await form.rounding.selectOption('100');
        await form.saveAndClose();

        const after = arrange('billsettings.state', {});
        expect(after.audits).toBe(before.audits + 1);
        expect(after.latest).toMatchObject({ user: form.admin.email, outlet: 'Outlet E2E', at: expect.any(String) });
        expect(after.latest.before).toEqual({ service_charge_rate: 0, cash_rounding_unit: 0 });
        expect(after.latest.after).toEqual({ service_charge_rate: 5, cash_rounding_unit: 100 });

        await form.edit();
        await expect(form.service).toHaveValue('5');
        await form.saveAndClose();
        expect(arrange('billsettings.state', {}).audits).toBe(after.audits);
    });
});
