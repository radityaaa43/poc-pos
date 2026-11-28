import { test, expect } from '../../support/fixtures.js';
import { PASSWORD } from '../../support/roles.js';

// CI serves the app with several PHP workers on MySQL, so the two requests really overlap.
test.describe('POS API: two cashiers, one credit limit', () => {
    test('only one of two sales that together pass the limit gets through', { tag: ['@REQ-007-AC-04'] }, async ({ arrange, uniq, request }) => {
        test.setTimeout(180_000);
        const cashiers = ['cashier@e2e.test', 'superadmin@e2e.test'];
        const { customer_id } = arrange('pos.customer', { name: uniq('Bersama') });
        arrange('paylater.setting', { enabled: 1 });
        arrange('paylater.credit', { customer_id, limit: 500000 });

        const headers = [];
        for (const email of cashiers) {
            // One product each, so only the customer is contended.
            const { product_id } = arrange('product', { title: uniq('bersama'), stock: 50 });
            arrange('pos.open-shift', { cashier: email, cash: 100000 });
            arrange('pos.cart', { cashier: email, product_id, qty: 30 });
            const login = await request.post('/api/v1/auth/login', { data: { email, password: PASSWORD } });
            headers.push({ Authorization: `Bearer ${(await login.json()).data.token}`, Accept: 'application/json' });
        }

        const due_date = new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10);
        const answers = await Promise.all(
            headers.map((auth) => request.post('/api/v1/pos/checkout', { headers: auth, data: { payment_method: 'pay_later', customer_id, due_date } })),
        );
        const refused = answers.filter((answer) => !answer.ok());

        expect(refused).toHaveLength(1);
        expect(refused[0].status()).toBe(422);
        expect((await refused[0].json()).errors).toEqual({ code: 'credit_limit_exceeded', remaining_limit: 200000 });
        expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ sales: 1, receivables: 1 });

        arrange('posops.no-shift', { cashier: 'superadmin@e2e.test' });
    });
});
