import { test, expect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';

// One arrange per test: its own outlet, warehouse and user, so this spec never shares opname or waste rows.
async function openVariance(page, arrange, uniq) {
    const arranged = arrange('opname.variance', { email: `${uniq('opname')}@e2e.test` });

    await login(page, arranged.email);
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { waitUntil: 'commit', timeout: 60_000 });
    await page.goto('/dashboard/stock-opnames');

    const section = page.getByRole('region', { name: 'Selisih opname per bulan' });
    await expect(section).toBeVisible();

    // 12 month rows, oldest first, plus the header; monthsAgo 4 is the 8th table row.
    const rows = section.getByRole('row');
    await expect(rows).toHaveCount(13);

    return { arranged, section, rows, row: (monthsAgo) => rows.nth(12 - monthsAgo) };
}

test.describe('86-opname-variance', () => {
    test('lists the last 12 months of absolute variance per finalized opname', { tag: ['@REQ-011-BR-1'] }, async ({ page, arrange, uniq }) => {
        const { row } = await openVariance(page, arrange, uniq);

        const fourAgo = row(4);
        await expect(fourAgo.getByRole('cell').nth(1)).toHaveText('Sebelum');
        await expect(fourAgo.getByRole('cell').nth(2)).toHaveText('1');
        await expect(fourAgo.getByRole('cell').nth(3)).toContainText(/Rp\s?2\.000/);
        await expect(fourAgo.getByRole('cell').nth(4)).toHaveText('1');
        await expect(fourAgo).toContainText('Estimasi');

        await expect(row(3).getByRole('cell').nth(3)).toContainText(/Rp\s?3\.000/);
        await expect(row(2).getByRole('cell').nth(3)).toContainText(/Rp\s?5\.000/);

        const oneAgo = row(1);
        await expect(oneAgo.getByRole('cell').nth(2)).toHaveText('1');
        await expect(oneAgo.getByRole('cell').nth(3)).toContainText(/Rp\s?0/);
        await expect(oneAgo.getByRole('cell').nth(4)).toHaveText('0');

        // A month with no finalized opname reads "-", not zero.
        const empty = row(0);
        await expect(empty.getByRole('cell').nth(2)).toHaveText('0');
        await expect(empty.getByRole('cell').nth(3)).toHaveText('-');
        await expect(empty.getByRole('cell').nth(4)).toHaveText('-');
    });

    test('marks the month waste recording started and splits before from after', { tag: ['@REQ-011-BR-2'] }, async ({ page, arrange, uniq }) => {
        const { arranged, row } = await openVariance(page, arrange, uniq);
        const start_months_ago = arranged.start_months_ago;

        const start = row(start_months_ago);
        await expect(start.getByRole('cell').nth(1)).toHaveText('Sesudah');
        await expect(start).toContainText('Pencatatan waste dimulai');

        // Months older than the start are before; newer ones are after.
        await expect(row(start_months_ago + 1).getByRole('cell').nth(1)).toHaveText('Sebelum');
        await expect(row(start_months_ago - 1).getByRole('cell').nth(1)).toHaveText('Sesudah');

        // The window's oldest month is before and its newest is after.
        await expect(row(11).getByRole('cell').nth(1)).toHaveText('Sebelum');
        await expect(row(0).getByRole('cell').nth(1)).toHaveText('Sesudah');
    });
});
