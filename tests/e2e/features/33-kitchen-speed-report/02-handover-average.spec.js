import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('kitchen speed report: payment to handover', () => {
    test('the card shows the average time from payment to the last item served, over fully served orders', { tag: ['@REQ-003-AC-16'] }, async ({ asRole, arrange }) => {
        // The card adds up the whole period, so the step takes a day of its own (a rerun on one database must not add to it).
        const day = `2031-${String(1 + Math.floor(Math.random() * 12)).padStart(2, '0')}-${String(5 + Math.floor(Math.random() * 20)).padStart(2, '0')}`;
        const item = arrange('kitchen.speed-items', { date: day });
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/kitchen-speed');
        await page.getByLabel('Tanggal Mulai').fill(item.date);
        await page.getByLabel('Tanggal Akhir').fill(item.date);
        await page.getByRole('button', { name: 'Terapkan' }).click();

        // Three served orders (6, 8 and 4 minutes after their own payment); the cancelled and the not collected
        // order are not counted.
        const card = page.getByRole('region', { name: 'Waktu bayar sampai diserahkan' });
        await expect(card).toContainText('7,0 mnt');
        await expect(card).toContainText('Jumlah pesanan: 3');
    });
});
