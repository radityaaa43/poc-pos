import { test, expect } from '../../support/fixtures.js';
import { PASSWORD, roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('POS API: pay later against the credit rules', () => {
    test('refuses with credit_limit_exceeded or credit_overdue and what is left of the limit', { tag: ['@REQ-007-AC-09'] }, async ({ arrange, uniq, request }) => {
        test.setTimeout(180_000);
        const { product_id } = arrange('product', { title: uniq('api-kredit'), stock: 50 });
        const owing = (limit, owed, due_days) => {
            const { customer_id } = arrange('pos.customer', { name: uniq('Api') });
            arrange('paylater.credit', { customer_id, limit });
            arrange('posops.receivable', { customer_id, total: owed, due_days });

            return customer_id;
        };
        arrange('paylater.setting', { enabled: 1 });
        const over = owing(500000, 300000, 14);
        const overdue = owing(500000, 100000, -3);
        arrange('pos.open-shift', { cash: 100000 });

        const { email } = roleOf(roles, 'cashier');
        const login = await request.post('/api/v1/auth/login', { data: { email, password: PASSWORD } });
        const headers = { Authorization: `Bearer ${(await login.json()).data.token}`, Accept: 'application/json' };
        const due_date = new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10);
        // A fresh cart of `qty` x Rp 10.000, sold to `customer_id` on pay later.
        const checkout = (customer_id, qty) => {
            arrange('posops.clear-carts');
            arrange('pos.cart', { product_id, qty });

            return request.post('/api/v1/pos/checkout', { headers, data: { payment_method: 'pay_later', customer_id, due_date } });
        };

        const limit = await checkout(over, 30);
        expect(limit.status()).toBe(422);
        expect(await limit.json()).toMatchObject({
            message: 'Melebihi batas piutang — sisa Rp 200.000',
            errors: { code: 'credit_limit_exceeded', remaining_limit: 200000 },
        });

        const late = await checkout(overdue, 10);
        expect(late.status()).toBe(422);
        expect(await late.json()).toMatchObject({
            message: 'Lunasi piutang yang lewat jatuh tempo dulu',
            errors: { code: 'credit_overdue', remaining_limit: 400000 },
        });
        for (const customer_id of [over, overdue]) {
            expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ sales: 1, receivables: 1 });
        }

        expect((await checkout(over, 20)).ok()).toBe(true); // exactly what was left
        expect(arrange('paylater.credit-state', { customer_id: over })).toMatchObject({ sales: 2, receivables: 2 });
    });
});
