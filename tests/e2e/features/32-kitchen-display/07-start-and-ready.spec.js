import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('32-kitchen-display', () => {
    test('start and ready one item, then mark a whole second order ready', { tag: ['@REQ-003-AC-08'] }, async ({ asRole, arrange, uniq }) => {
        const first = arrange('kitchen-sale', { prefix: uniq('Langkah'), flagged: 2 });
        const second = arrange('kitchen-sale', { prefix: uniq('Semua'), flagged: 2 });

        const page = await asRole(cashier);
        await page.goto('/dashboard/kitchen');

        // One item of the first order goes Diterima -> Sedang dibuat -> Siap; its sibling is left alone.
        const card = page.locator('article', { hasText: first.invoice });
        const item = card.getByRole('listitem').filter({ hasText: first.flagged[0] });
        const sibling = card.getByRole('listitem').filter({ hasText: first.flagged[1] });
        await expect(item).toContainText('Diterima');
        await item.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(item).toContainText('Sedang dibuat');
        await expect(item.getByRole('button', { name: 'Mulai buat', exact: true })).toHaveCount(0);
        await item.getByRole('button', { name: 'Siap', exact: true }).click();
        await expect(item).toContainText('Siap');
        await expect(item.getByRole('button')).toHaveCount(0);
        await expect(sibling).toContainText('Diterima');
        await expect(sibling.getByRole('button', { name: 'Mulai buat', exact: true })).toBeVisible();

        // Semua siap on another order: one item never started, one already preparing; both end up ready.
        const all = page.locator('article', { hasText: second.invoice });
        const started = all.getByRole('listitem').filter({ hasText: second.flagged[0] });
        await started.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(started).toContainText('Sedang dibuat');
        await all.getByRole('button', { name: 'Semua siap', exact: true }).click();
        await expect(all.getByRole('listitem').filter({ hasText: 'Siap' })).toHaveCount(2);
        await expect(all.getByRole('button')).toHaveCount(0);
        await expect(card).toContainText('Diterima'); // the first order is untouched by the second
    });
});
