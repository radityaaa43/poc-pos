import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// The report adds up the whole period, so each step takes a day of its own that no other step, test or rerun on the
// same database can share: a day in 2034-2037 (no other spec uses those years; TIMESTAMP columns end in 2038 on
// MySQL), chosen from the second the step runs at plus a counter for a second test in the same second. The day is
// recorded in the test annotations.
let taken = 0;
const freshDay = () => {
    const offset = (Math.floor(Date.now() / 1000) + taken++) % 1400;
    const day = new Date(Date.UTC(2034, 0, 1) + offset * 86_400_000).toISOString().slice(0, 10);
    test.info().annotations.push({ type: 'report day', description: day });

    return day;
};

async function openReport(page, day) {
    await page.goto('/dashboard/reports/kitchen-speed');
    await page.getByLabel('Tanggal Mulai').fill(day);
    await page.getByLabel('Tanggal Akhir').fill(day);
    await page.getByRole('button', { name: 'Terapkan' }).click();
}

const card = (page) => page.getByRole('region', { name: 'Kelengkapan data' });

test.describe('kitchen speed report: data completeness', () => {
    test('19 of 20 complete items show 95,0% without the below-target mark', { tag: ['@REQ-003-AC-26'] }, async ({ asRole, arrange }) => {
        const day = freshDay();
        arrange('kitchen.speed-completeness', { date: day, complete: 19, open: 1 });
        const page = await asRole(manager);

        await openReport(page, day);

        await expect(card(page)).toBeVisible();
        await expect(card(page)).toContainText('95,0%');
        await expect(card(page)).toContainText('19 dari 20 item dapur punya waktu lengkap sampai siap');
        await expect(page.getByText('Di bawah target 95%')).toHaveCount(0);
    });

    test('cancelled and not collected items are left out, and a share below 95 is marked in both languages', { tag: ['@REQ-003-AC-26'] }, async ({ asRole, arrange }) => {
        const day = freshDay();
        // 18 of 20 live items: counting the cancelled and not-collected ones would change both numbers.
        arrange('kitchen.speed-completeness', { date: day, complete: 18, open: 2, cancelled: 1, not_collected: 1 });
        const page = await asRole(manager);

        await openReport(page, day);

        await expect(card(page)).toContainText('90,0%');
        await expect(card(page)).toContainText('18 dari 20 item dapur punya waktu lengkap sampai siap');
        await expect(page.getByText('Di bawah target 95%')).toBeVisible();

        await page.evaluate(() => localStorage.setItem('i18nextLng', 'en'));
        await page.reload();

        await expect(page.getByRole('region', { name: 'Data completeness' })).toContainText('18 of 20 kitchen items have times up to ready');
        await expect(page.getByText('Below the 95% target')).toBeVisible();
    });
});
