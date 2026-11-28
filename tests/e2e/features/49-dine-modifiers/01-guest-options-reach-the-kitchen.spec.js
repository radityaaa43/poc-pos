import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addMenuWithOptions } from '../../support/dine-modifiers.js';

const manager = roles.find((role) => role.key === 'manager');
const cashier = roles.find((role) => role.key === 'cashier');

// REQ-008 J3/J4: the guest picks options and a note on the table-QR menu; staff accept a pay-later order, so the
// kitchen already lists the options (free ones included) and the note before anything is paid.
test.describe('49-dine-modifiers', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a guest picks options and a note on the QR menu and the kitchen shows them after staff accept', { tag: ['@REQ-008-AC-15', '@REQ-008-AC-17'] }, async ({ page, asRole, arrange, uniq }) => {
        const title = uniq('Kopi');
        arrange('modifier-menu', { title, needs_prep: 1 });
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const note = uniq('es sedikit');
        arrange('paylater.setting', { enabled: 1 });

        try {
            const cart = await addMenuWithOptions(page, { token, title, option: 'Large', note });
            await expect(cart.getByText('Large')).toBeVisible();
            await expect(cart.getByText(note)).toBeVisible();
            await cart.getByRole('button', { name: 'Pesan — Bayar Belakangan' }).click();
            await expect(page).toHaveURL(/\/dine-order\/[0-9a-f-]{36}$/);
        } finally {
            arrange('paylater.setting', { enabled: 0 });
        }

        arrange('open-shift', { cashier: manager.email, cash: 100000 });
        const managerPage = await asRole(manager);
        await managerPage.goto('/dashboard/dine-orders');
        const card = managerPage.locator('div.rounded-2xl', { hasText: table_name });
        managerPage.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card.getByRole('button', { name: 'Terima' })).toHaveCount(0);

        const kitchen = await asRole(cashier);
        await kitchen.goto('/dashboard/kitchen');
        const kitchenCard = kitchen.locator('article', { hasText: table_name });
        await expect(kitchenCard).toContainText(`1 × ${title}`);
        await expect(kitchenCard.getByText('+ Large')).toBeVisible();
        await expect(kitchenCard.getByText(note)).toBeVisible();
    });
});
