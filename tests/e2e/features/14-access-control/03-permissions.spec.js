import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('permissions', () => {
    test.setTimeout(240_000);

    test('the permission list can be paged and searched', async ({ page, arrange }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });

        await signIn(page, admin.email);
        await page.goto('/dashboard/permissions');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Hak Akses', level: 1 })).toBeVisible();
        await expect(page.getByText(/^\d+ hak akses terdaftar$/)).toBeVisible();

        // The next page shows other permissions.
        await expect(page.getByText(/^Halaman 1 dari \d+$/)).toBeVisible();
        await page.getByRole('navigation', { name: 'Pagination' }).getByText('2', { exact: true }).click();
        await expect(page.getByText(/^Halaman 2 dari \d+$/)).toBeVisible();

        // Search narrows the list to matching permissions.
        await page.getByPlaceholder('Cari hak akses...').fill('products-export');
        await page.getByPlaceholder('Cari hak akses...').press('Enter');
        await expect(page.getByText('products-export', { exact: true })).toBeVisible();
        await expect(page.getByText('users-access', { exact: true })).toBeHidden();
    });

    // Bug #139: the picker sends ?per_page, the controller ignores it (always 7 rows).
    test.fixme('the page-size picker changes how many permissions a page shows', async ({ page, arrange }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });

        await signIn(page, admin.email);
        await page.goto('/dashboard/permissions');
        const total = Number((await page.getByText(/^\d+ hak akses terdaftar$/).innerText()).match(/\d+/)[0]);

        await page.getByLabel('Jumlah data per halaman').selectOption('50');
        await expect(page.getByText(`Halaman 1 dari ${Math.ceil(total / 50)}`)).toBeVisible();
    });
});
