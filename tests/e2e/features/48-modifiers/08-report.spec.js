import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { returnPortions, sellMenu } from '../../support/modifiers.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('48-modifiers: sales per option', () => {
    test.describe.configure({ timeout: 180_000 });

    test('quantity and revenue are net of returns, under the sold name, in both languages', { tag: ['@REQ-008-AC-19', '@REQ-008-AC-20'] }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Kopi');
        const option = uniq('Ukuran');
        const { product_id: product } = arrange('modifier-menu', { title });
        // A name of its own keeps this row apart from other steps' sales; the rename also prices it at 9.000.
        arrange('modifier-change', { product, option: 'Large', rename: option });
        arrange('pos.open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const page = await asRole(manager);
        const invoice = await sellMenu(page, title, { options: [option, 'Less'], portions: 2, amount: 58000 });
        await sellMenu(page, title, { options: [option, 'Less'], portions: 1, amount: 29000 });
        await returnPortions(page, invoice, 1);
        arrange('modifier-change', { product, option, rename: uniq('Baru') });

        await page.goto('/dashboard/reports/modifiers');
        const cells = page.getByRole('row').filter({ hasText: option }).getByRole('cell');
        await expect(cells).toHaveText(['Size', option, '2', /Rp\s*18\.000/]);
        await expect(page.getByRole('columnheader', { name: 'Omzet opsi (sebelum diskon)' })).toBeVisible();

        await page.getByLabel('Tanggal mulai').fill('2099-01-01');
        await page.getByRole('button', { name: 'Terapkan' }).click();
        await expect(page.getByText('Belum ada opsi terjual pada periode ini.')).toBeVisible();

        // Only the browser's language: changing the shared user's saved language would affect other steps.
        await page.addInitScript(() => localStorage.setItem('i18nextLng', 'en'));
        await page.goto('/dashboard/reports/modifiers');
        await expect(page.getByRole('heading', { name: 'Sales per Option' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Option revenue (before discounts)' })).toBeVisible();
    });
});
