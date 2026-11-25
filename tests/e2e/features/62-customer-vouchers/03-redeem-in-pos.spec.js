import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, code, finishSale, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('customer vouchers: redeem at the till', () => {
    test('only a valid voucher of a member is offered, it cuts the total and is used up', async ({ asRole, arrange, uniq }) => {
        const [title, memberName, plainName] = [uniq('voucher-produk'), uniq('Member'), uniq('Biasa')];
        const [valid, expired, inactive, tooBig, others] = ['OK', 'EXP', 'OFF', 'BIG', 'OTH'].map(code);
        arrange('crm.pos-ready');
        arrange('crm.product', { title, price: 10000 });
        const member = arrange('crm.customer', { name: memberName, member: 1 });
        const plain = arrange('crm.customer', { name: plainName });
        arrange('crm.voucher', { customer_id: member.customer_id, code: valid, name: 'Voucher sah', value: 5000, min: 15000, expires: 'future' });
        arrange('crm.voucher', { customer_id: member.customer_id, code: expired, name: 'Voucher lewat', value: 5000, expires: 'past' });
        arrange('crm.voucher', { customer_id: member.customer_id, code: inactive, name: 'Voucher mati', value: 5000, active: 0 });
        arrange('crm.voucher', { customer_id: member.customer_id, code: tooBig, name: 'Voucher min besar', value: 5000, min: 500000 });
        arrange('crm.voucher', { customer_id: plain.customer_id, code: others, name: 'Voucher bukan member', value: 5000 });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await pickCustomer(page, plainName);
        await addProduct(page, title);
        await expect(page.getByText('Voucher Customer', { exact: true })).toHaveCount(0);

        // The member's voucher needs a Rp 15.000 basket: one unit is not enough.
        await page.getByRole('button', { name: new RegExp(plainName) }).first().click();
        await page.getByPlaceholder('Cari nama/telepon/nomor anggota...').fill(memberName);
        await page.getByRole('button', { name: new RegExp(memberName) }).click();
        await expect(page.getByText('Loyalty Member')).toBeVisible();
        await expect(page.getByText('Voucher Customer', { exact: true })).toHaveCount(0);

        await page.getByRole('button', { name: '+', exact: true }).click();
        const select = page.locator('select').filter({ has: page.getByRole('option', { name: 'Tanpa voucher' }) });
        await expect(select.getByRole('option')).toHaveCount(2);
        await expect(select.getByRole('option', { name: new RegExp(valid) })).toHaveCount(1);

        await select.selectOption({ label: `${valid} - Voucher sah` });
        await expect(cartTotal(page)).toHaveText(rupiah(15000));
        await expect(page.getByText('Voucher', { exact: true }).locator('xpath=following-sibling::span')).toHaveText(rupiah(5000));

        await finishSale(page);

        // The manager sees it consumed.
        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/customer-vouchers');
        const search = manager.getByPlaceholder('Cari kode, voucher, pelanggan...');
        await search.fill(valid);
        await expect(manager.getByRole('row').filter({ hasText: valid })).toContainText('Sudah Dipakai');
        await search.fill(expired);
        await expect(manager.getByRole('row').filter({ hasText: expired })).toContainText('Expired');
        await search.fill(inactive);
        await expect(manager.getByRole('row').filter({ hasText: inactive })).toContainText('Nonaktif');
    });
});
