import { test, expect as baseExpect } from '../../support/fixtures.js';
import { confirmPassword, field, signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// An outlet made here is deleted again: every super-admin sees all outlets in the header picker.
test.describe('outlets', () => {
    test.setTimeout(240_000);

    test('an admin creates an outlet with its warehouse, renames it, and deletes it', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const code = uniq('o').toUpperCase().slice(0, 12);
        const name = uniq('Outlet');
        const row = (title) => page.locator('div.p-4').filter({ has: page.getByRole('heading', { name: title, level: 2 }) });

        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/settings/outlets');

        // The central warehouse outlet has no delete button.
        await expect(row('Gudang Pusat').getByRole('button')).toHaveCount(1);

        await page.getByRole('button', { name: 'Tambah Outlet' }).click();
        await page.getByRole('button', { name: 'Buat Outlet' }).click();
        await expect(page.getByText('The name field is required.')).toBeVisible();
        await expect(page.getByText('The code field is required.')).toBeVisible();
        await expect(page.getByText('The warehouse name field is required.')).toBeVisible();

        // The code E2E is taken.
        await field(page, 'Nama Outlet').fill(name);
        await field(page, 'Kode Outlet').fill('E2E');
        await field(page, 'Nama Gudang').fill(`Gudang ${name}`);
        await field(page, 'Kode Gudang').fill(`WH-${code}`);
        await page.getByRole('button', { name: 'Buat Outlet' }).click();
        await expect(page.getByText('The code has already been taken.')).toBeVisible();

        await field(page, 'Kode Outlet').fill(code);
        await page.getByRole('button', { name: 'Buat Outlet' }).click();
        await expect(row(name).getByText(code, { exact: true })).toBeVisible();
        await expect(row(name).getByText(`Gudang ${name}`)).toBeVisible();
        await expect(row(name).getByText('Penjualan aktif')).toBeVisible();

        // Rename it, and take it off sales.
        await row(name).getByRole('button').first().click();
        await field(page, 'Nama Outlet').fill(`${name} baru`);
        await page.getByLabel('Outlet melayani penjualan').uncheck();
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(row(`${name} baru`).getByText('Gudang pusat')).toBeVisible();

        // A fresh outlet has no history: delete it.
        page.once('dialog', (dialog) => dialog.accept());
        await row(`${name} baru`).getByRole('button').last().click();
        await expect(page.getByRole('heading', { name: `${name} baru`, level: 2 })).toBeHidden();
    });

    test('each outlet keeps its own settings when the outlet is switched in the header', async ({ page, arrange }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const picker = page.getByRole('banner').getByRole('combobox');
        const paper = () => page.getByRole('combobox').last();
        const savePrinter = async () => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/settings/printer') && response.request().method() === 'POST');
            await page.getByRole('button', { name: 'Simpan Pengaturan' }).click();
            await saved;
        };
        // Switch outlet, then reload: the page keeps the old form values until then (bug #151).
        const switchTo = async (label) => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/dashboard/outlet') && response.request().method() === 'POST');
            await page.waitForLoadState('networkidle');
            await picker.selectOption({ label });
            await saved;
            await page.waitForLoadState('networkidle');
            await page.reload();
            await expect(picker.locator('option:checked')).toHaveText(label);
        };

        await signIn(page, admin.email);
        await page.goto('/dashboard/settings/printer');
        await expect(picker.locator('option:checked')).toHaveText('E2E - Outlet E2E');
        await expect(paper()).toHaveValue('80mm');

        // PUSAT gets its own paper size.
        await switchTo('PUSAT - Gudang Pusat');
        await paper().selectOption('58mm');
        await savePrinter();

        // The E2E outlet still has 80 mm, PUSAT still 58 mm.
        await switchTo('E2E - Outlet E2E');
        await expect(paper()).toHaveValue('80mm');
        await switchTo('PUSAT - Gudang Pusat');
        await expect(paper()).toHaveValue('58mm');

        // Put PUSAT back as it was.
        await paper().selectOption('80mm');
        await savePrinter();
    });

    test('the settings page shows the new outlet right after the switch, without a reload', async ({ page, arrange }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const picker = page.getByRole('banner').getByRole('combobox');

        const paper = () => page.getByRole('combobox').last();
        // Wait for each request and the page it redirects back to: acting before they land would save to the wrong outlet.
        const printerPage = () => page.waitForResponse((response) => response.url().endsWith('/settings/printer') && response.request().method() === 'GET');
        const switchTo = async (label) => {
            const switched = page.waitForResponse((response) => response.url().endsWith('/dashboard/outlet') && response.request().method() === 'POST');
            const loaded = printerPage();
            await picker.selectOption({ label });
            await switched;
            await loaded;
        };
        const savePrinter = async () => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/settings/printer') && response.request().method() === 'POST');
            const loaded = printerPage();
            await page.getByRole('button', { name: 'Simpan Pengaturan' }).click();
            await saved;
            await loaded;
        };

        await signIn(page, admin.email);
        await page.goto('/dashboard/settings/printer');
        await page.waitForLoadState('networkidle');
        await switchTo('PUSAT - Gudang Pusat');
        await paper().selectOption('58mm');
        await savePrinter();

        // No reload: the page already shows the E2E outlet's own paper size.
        await switchTo('E2E - Outlet E2E');
        await expect(paper()).toHaveValue('80mm');

        // Put PUSAT back as it was.
        await switchTo('PUSAT - Gudang Pusat');
        await paper().selectOption('80mm');
        await savePrinter();
    });
});
