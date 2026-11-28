import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

// REQ-009 AC-19: the list per date shows statuses; the phone number is on this permission-gated page.
test.describe('81-dine-reservations: list per date', () => {
    test('@REQ-009-AC-19 a manager sees each date its own reservations, with status and phone number', async ({ asRole, arrange, uniq }) => {
        const table = arrange('dine.table', { name: uniq('R') });
        const today = uniq('Fani');
        const later = uniq('Gita');
        arrange('dine.reservation', { table_id: table.table_id, name: today });
        const { date } = arrange('dine.reservation', { table_id: table.table_id, name: later, days: 40, phone: '0813 7777 8888' });

        const page = await asRole(role('manager'));
        const row = (name) => page.getByRole('row').filter({ hasText: name });

        await page.goto('/dashboard/dine-reservations');
        await expect(row(today)).toContainText('Dicatat');
        await expect(row(later)).toHaveCount(0);

        await page.getByLabel('Tanggal').fill(date);
        await expect(row(later)).toContainText('Dicatat');
        await expect(row(later).getByRole('link', { name: '0813 7777 8888' })).toHaveAttribute('href', 'tel:081377778888');
        await expect(row(today)).toHaveCount(0);
    });
});
