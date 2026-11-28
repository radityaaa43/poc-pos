import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

test.describe('48-modifiers: groups', () => {
    test('a manager creates a group; a broken minimum or maximum is refused with a message', { tag: ['@REQ-008-AC-01', '@REQ-008-AC-20'] }, async ({ asRole, uniq }) => {
        const page = await asRole(role('manager'));
        const name = uniq('Ukuran');
        const minimum = page.getByLabel('Minimum pilihan');
        const maximum = page.getByLabel('Maksimum pilihan');
        const save = page.getByRole('button', { name: 'Simpan', exact: true });

        await page.goto('/dashboard/settings/modifiers');
        await page.getByRole('button', { name: 'Tambah grup' }).click();
        await page.getByLabel('Nama grup').fill(name);
        await page.getByLabel('Nama opsi').fill('Regular');
        await minimum.fill('2');
        await save.click();
        await expect(page.getByText('Minimum pilihan tidak boleh lebih besar dari maksimum.')).toBeVisible();

        await minimum.fill('0');
        await maximum.fill('0');
        await save.click();
        await expect(page.getByText('Maksimum pilihan minimal 1.')).toBeVisible();

        await page.getByRole('radio', { name: 'Wajib' }).check();
        await maximum.fill('1');
        await save.click();
        await expect(page.getByText('Grup wajib harus punya minimum pilihan minimal 1.')).toBeVisible();

        await minimum.fill('1');
        await page.getByRole('checkbox', { name: 'Opsi default' }).check();
        await save.click();
        await expect(page.getByRole('listitem').filter({ hasText: name })).toContainText('Wajib · Pilih 1–1 · Opsi: 1 · Menu: 0');
    });

    test('the page reads in English too', { tag: ['@REQ-008-AC-20'] }, async ({ asRole }) => {
        const page = await asRole(role('manager'));
        // Only the browser's language: changing the shared user's saved language would affect other steps.
        await page.addInitScript(() => localStorage.setItem('i18nextLng', 'en'));

        await page.goto('/dashboard/settings/modifiers');
        await page.getByRole('button', { name: 'Add group' }).click();

        await expect(page.getByLabel('Maximum choices')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Add option' })).toBeVisible();
    });
});
