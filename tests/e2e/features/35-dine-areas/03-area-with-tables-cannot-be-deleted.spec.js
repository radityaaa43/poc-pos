import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

async function tryDeleteAreaWithTable(page, arrange, uniq) {
    const { area_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
    await page.goto('/dashboard/dine-areas');
    const row = page.getByRole('row').filter({ hasText: area_name });
    await expect(row).toContainText('1 meja');

    page.once('dialog', (confirm) => confirm.accept());
    await row.locator('button').last().click();

    return { area_name, row };
}

test.describe('35-dine-areas', () => {
    test('an area that still has tables is not deleted', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(manager);
        const { area_name } = await tryDeleteAreaWithTable(page, arrange, uniq);

        await page.reload();
        await expect(page.getByRole('row').filter({ hasText: area_name })).toContainText('1 meja');
    });

    test('the user is told why the area stays, not that it was deleted', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(manager);
        await tryDeleteAreaWithTable(page, arrange, uniq);

        await expect(page.getByText('Area memiliki meja. Hapus atau pindahkan meja terlebih dahulu.')).toBeVisible();
        await expect(page.getByText('Area berhasil dihapus.')).toHaveCount(0);
    });
});
