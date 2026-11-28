import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('29-receivables', () => {
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the list shows Batas and Terpakai % of each customer', { tag: ['@REQ-007-AC-07'] }, async ({ asRole, arrange, uniq }) => {
        const owing = (limit) => {
            const { customer_id } = arrange('pos.customer', { name: uniq('Batas') });
            arrange('paylater.credit', { customer_id, ...(limit === undefined ? {} : { limit }) });

            return arrange('posops.receivable', { customer_id, total: 800000 });
        };
        const near = owing(1000000);
        const unlimited = owing();
        const page = await asRole(manager);
        const row = (receivable) => page.getByRole('link').filter({ hasText: receivable.invoice });

        await page.goto(`/dashboard/receivables?invoice=${near.invoice}`);
        await expect(page.getByTitle('Terpakai (di semua outlet)')).toContainText('Terpakai %');
        await expect(row(near)).toContainText(/Rp\s*1\.000\.000/);
        await expect(row(near).getByRole('img', { name: /^Terpakai 80% dari batas Rp\s*1\.000\.000$/ })).toHaveText('80%');
        await expect(row(near)).toContainText('Mendekati batas (≥ 80%)');

        await page.goto(`/dashboard/receivables?invoice=${unlimited.invoice}`);
        await expect(row(unlimited)).toContainText('Tanpa batas');
        await expect(row(unlimited).getByRole('img')).toHaveCount(0);
    });
});
