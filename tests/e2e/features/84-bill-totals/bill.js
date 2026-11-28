import { test } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { openPos } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

// The steps set the E2E outlet's bill rules and the suite shares that outlet: leave it as "never saved".
export function resetBillRules() {
    test.afterEach(({ arrange }) => arrange('billsettings.reset', {}));
}

// Opens the POS on a cart of 2 x Rp 10.000 at an outlet with the given bill rules.
export async function openBill({ arrange, asRole, uniq }, rules, taxRate = 0) {
    const product = arrange('product', { title: uniq('bill'), stock: 10, tax_rate: taxRate });
    arrange('billsettings.reset', {});
    arrange('billsettings.set', rules);
    arrange('pos.open-shift', { cash: 100000 });
    arrange('pos.cart', { product_id: product.product_id, qty: 2 });
    const page = await asRole(cashier);
    await openPos(page);

    return page;
}

// The amount of a summary row, at checkout and on the receipt.
export const amount = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::span');
export const orderType = (page, name) => page.getByRole('button', { name, exact: true }).click();
