import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

// Bucket label on screen -> days to the due date of the receivable we add (negative = overdue).
const BUCKETS = { 'Belum Jatuh Tempo': 10, '1-30 Hari': -10, '31-60 Hari': -45, '61-90 Hari': -75, '90+ Hari': -120 };

// The page lists payables first, then receivables, with the same labels: the second match is receivables.
async function readBuckets(page) {
    await page.goto('/dashboard/aging');
    await expect(page.getByText('Aging Piutang Pelanggan')).toBeVisible();
    const result = {};
    for (const label of Object.keys(BUCKETS)) {
        const text = await page.getByText(label, { exact: true }).nth(1).locator('xpath=..').innerText();
        result[label] = {
            count: Number(/(\d+) nota/.exec(text)[1]),
            remaining: Number(/Rp\s*([\d.]+)/.exec(text)[1].replaceAll('.', '')),
        };
    }

    return result;
}

test.describe('29-receivables', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the aging page puts each receivable in its overdue bucket', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(manager);
        const before = await readBuckets(page);

        let amount = 10000;
        const added = {};
        for (const [label, dueDays] of Object.entries(BUCKETS)) {
            added[label] = amount;
            arrange('posops.receivable', { total: amount, due_days: dueDays, customer: uniq('Aging') });
            amount += 10000;
        }

        const after = await readBuckets(page);
        for (const label of Object.keys(BUCKETS)) {
            expect(after[label].count, label).toBe(before[label].count + 1);
            expect(after[label].remaining, label).toBe(before[label].remaining + added[label]);
        }
    });

    test('the receivables page lists the biggest debtor on its aging tab', async ({ asRole, arrange, uniq }) => {
        const { customer } = arrange('posops.receivable', { total: 900000000, customer: uniq('Juragan') });
        const page = await asRole(manager);

        await page.goto('/dashboard/receivables');
        await page.getByRole('button', { name: 'Aging', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Pelanggan Terbesar' })).toBeVisible();
        await expect(page.getByText(customer, { exact: true })).toBeVisible();
        await expect(page.getByText('Rp 900.000.000').first()).toBeVisible();
    });
});
