import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, finishSale, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('members: points earned on a sale', () => {
    test('a sale earns one point per Rp 10.000 and the member page shows balance, totals and history', async ({ asRole, arrange, uniq }) => {
        const [title, name] = [uniq('poin-produk'), uniq('Member')];
        arrange('crm.pos-ready');
        arrange('crm.product', { title, price: 25000 });
        const { customer_id } = arrange('crm.customer', { name, member: 1 });

        const cashier = await asRole(roleOf(roles, 'cashier'));
        await openPos(cashier);
        await pickCustomer(cashier, name);
        await addProduct(cashier, title);
        await expect(cartTotal(cashier)).toHaveText(rupiah(25000));
        await finishSale(cashier);

        // Rp 25.000 earns floor(25.000 / 10.000) = 2 points.
        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/members');
        await manager.getByPlaceholder('Cari nama member atau nomor anggota...').fill(name);
        const row = manager.getByRole('row').filter({ hasText: name });
        await expect(row.getByRole('cell').nth(2)).toHaveText('2');
        await expect(row.getByRole('cell').nth(3)).toHaveText(rupiah(25000));
        await expect(row.getByRole('cell').nth(4)).toHaveText('1');

        await manager.goto(`/dashboard/members/${customer_id}`);
        await expect(manager.getByText('Saldo Poin').locator('xpath=following-sibling::p')).toHaveText('2 poin');
        await expect(manager.getByText('+2 poin')).toBeVisible();
    });

    test('redeeming points lowers the total and the balance after the sale is old minus redeemed plus earned', async ({ asRole, arrange, uniq }) => {
        const [title, name] = [uniq('redeem-produk'), uniq('Member')];
        arrange('crm.pos-ready');
        arrange('crm.product', { title, price: 30000 });
        const { customer_id } = arrange('crm.customer', { name, member: 1, points: 50 });

        const cashier = await asRole(roleOf(roles, 'cashier'));
        await openPos(cashier);
        await pickCustomer(cashier, name);
        await addProduct(cashier, title);

        // The balance comes from the pricing preview; 30 points at Rp 100 each take Rp 3.000 off.
        const redeem = cashier.getByPlaceholder('Maks 50 poin');
        await redeem.fill('30');
        await expect(cartTotal(cashier)).toHaveText(rupiah(27000));
        await expect(cashier.getByText('Redeem Poin', { exact: true }).locator('xpath=following-sibling::span')).toHaveText(rupiah(3000));
        await finishSale(cashier);

        // 50 - 30 redeemed + floor(27.000 / 10.000) = 22.
        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto(`/dashboard/members/${customer_id}`);
        await expect(manager.getByText('Saldo Poin').locator('xpath=following-sibling::p')).toHaveText('22 poin');
        await expect(manager.getByText('-30 poin')).toBeVisible();
        await expect(manager.getByText('+2 poin')).toBeVisible();
    });
});
