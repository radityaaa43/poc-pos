import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('28-discount-approval', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the server refuses a cashier who tries to decide a credit exception', { tag: ['@REQ-007-AC-06'] }, async ({ asRole, arrange }) => {
        arrange('pos.open-shift', { cash: 100000 });
        const { request_id, customer_id } = arrange('paylater.pending-credit-request');
        const page = await asRole(cashier);
        await page.goto('/dashboard/transactions');

        for (const action of ['approve', 'deny']) {
            // The session's own CSRF token, so the refusal is the permission's and not a missing token.
            const status = await page.evaluate(async (url) => {
                const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
                    body: JSON.stringify({ reason: 'Dicoba kasir' }),
                });

                return response.status;
            }, `/dashboard/discount-approvals/credit/${request_id}/${action}`);
            expect(status).toBe(403);
        }

        expect(arrange('paylater.credit-requests', { customer_id })).toMatchObject({ requests: [{ status: 'pending' }] });
    });
});
