import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { cashInput, finish, openPos, rupiah, total } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

async function sell({ asRole, arrange, uniq }, options = {}, language = 'id') {
    const title = uniq('Kopi');
    const menu = arrange('modifier-menu', { title, ...options });
    arrange('pos.open-shift', { cash: 100000 });
    const page = await asRole(cashier);
    // Only the browser's language: changing the shared user's saved language would affect other steps.
    await page.addInitScript((lng) => localStorage.setItem('i18nextLng', lng), language);
    await openPos(page);
    // Not support/pos.js cardButton: a promo badge sits inside the card button's name.
    const card = page.getByRole('button', { name: new RegExp(`^${title}`) });
    const panel = page.getByRole('dialog', { name: title });
    const add = panel.getByRole('button', { name: /^(Tambah|Add)$/ });
    const choose = async (names, note) => {
        await card.click();
        for (const name of names) await panel.getByLabel(new RegExp(`^${name}`)).check();
        if (note) await panel.getByRole('textbox').fill(note);
        await add.click();
        await expect(panel).toBeHidden();
    };

    return { ...menu, title, page, card, panel, add, choose };
}

const price = (panel) => panel.getByText('Harga porsi').locator('xpath=..');

test.describe('48-modifiers: selling on the POS', () => {
    test.describe.configure({ timeout: 120_000 });

    test('the panel picks defaults and prices the portion; the maximum locks and a missing required group disables Add', { tag: ['@REQ-008-AC-06', '@REQ-008-AC-07', '@REQ-008-AC-09'] }, async ({ asRole, arrange, uniq }) => {
        const { card, panel, add } = await sell({ asRole, arrange, uniq });

        await card.click();
        await expect(panel.getByLabel(/^Regular/)).toBeChecked();
        await expect(price(panel)).toContainText(rupiah(20000));
        await panel.getByLabel(/^Large/).check();
        await expect(price(panel)).toContainText(rupiah(25000));
        await expect(panel.getByLabel(/^Normal/)).toBeDisabled();
        await expect(panel.locator('label').filter({ hasText: 'Normal' })).toContainText('Habis');
        await expect(add).toBeDisabled();
        await expect(panel.getByRole('status')).toContainText('Gula');
        await panel.getByLabel(/^Less/).check();
        await expect(add).toBeEnabled();
        await expect(panel.getByLabel(/^Boba/)).toBeDisabled();

        await panel.getByLabel(/^Regular/).check();
        await panel.getByLabel(/^Jelly/).check();
        await expect(panel.getByLabel(/^Keju/)).toBeDisabled();
        await panel.getByLabel(/^Jelly/).uncheck();
        await expect(panel.getByLabel(/^Keju/)).toBeEnabled();
    });

    test('a menu whose required group is out cannot be added', { tag: ['@REQ-008-AC-09'] }, async ({ asRole, arrange, uniq }) => {
        const { card } = await sell({ asRole, arrange, uniq }, { sold_out: 1 });

        await expect(card).toBeDisabled();
        await expect(card).toContainText('Habis');
    });

    test('the cart shows options and note, and merges only identical lines', { tag: ['@REQ-008-AC-08', '@REQ-008-AC-10'] }, async ({ asRole, arrange, uniq }) => {
        const { page, choose } = await sell({ asRole, arrange, uniq });

        await choose(['Large', 'Less'], 'es sedikit');
        await expect(page.getByText('+ Large 5.000')).toBeVisible();
        await expect(page.getByText('Catatan: es sedikit')).toBeVisible();
        await expect(page.getByText(`${rupiah(25000)} × 1`)).toBeVisible();
        await choose(['Large', 'Less'], 'es sedikit');
        await expect(page.getByText(`${rupiah(25000)} × 2`)).toBeVisible();
        await choose(['Less']);
        await expect(page.getByText(`${rupiah(20000)} × 1`)).toBeVisible();
        await expect(page.getByText(`${rupiah(25000)} × 2`)).toBeVisible();
    });

    test('a promo discounts the full line; the receipt keeps the sold options and stock is taken', { tag: ['@REQ-008-AC-11', '@REQ-008-AC-12', '@REQ-008-AC-14'] }, async ({ asRole, arrange, uniq }) => {
        const { page, choose, product_id: product, milk_id: milk } = await sell({ asRole, arrange, uniq }, { percent: 10 });

        await choose(['Large', 'Less'], 'es sedikit');
        await expect(total(page)).toHaveText(rupiah(22500));
        await cashInput(page).fill('50000');
        await finish(page);

        await expect(page.getByText('+ Large 5.000').first()).toBeVisible();
        await expect(page.getByText('Catatan: es sedikit').first()).toBeVisible();
        expect(arrange('modifier-stock', { product: milk }).stock).toBe(8);

        arrange('modifier-change', { product, option: 'Large', rename: 'Jumbo' });
        await page.reload();
        await expect(page.getByText('+ Large 5.000').first()).toBeVisible();
        await expect(page.getByText('Jumbo')).toHaveCount(0);
    });

    test('checkout refuses an option switched off while it was in the cart', { tag: ['@REQ-008-AC-13'] }, async ({ asRole, arrange, uniq }) => {
        const { page, choose, title, product_id: product } = await sell({ asRole, arrange, uniq });

        await choose(['Large', 'Less']);
        arrange('modifier-change', { product, option: 'Large' });
        await cashInput(page).fill('50000');
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();

        await expect(page.getByText(`Opsi Large untuk ${title} tidak tersedia lagi.`)).toBeVisible();
        await expect(page.getByText('+ Large 5.000')).toBeVisible();
        expect(arrange('modifier-stock', { product }).stock).toBe(20);
    });

    test('the panel reads in English too', { tag: ['@REQ-008-AC-20'] }, async ({ asRole, arrange, uniq }) => {
        const { card, panel } = await sell({ asRole, arrange, uniq }, {}, 'en');

        await card.click();
        await expect(panel.getByText('Portion price')).toBeVisible();
        await expect(panel.getByText('Complete your choices: Gula')).toBeVisible();
        await expect(panel.getByText('Sold out').first()).toBeVisible();
    });
});
