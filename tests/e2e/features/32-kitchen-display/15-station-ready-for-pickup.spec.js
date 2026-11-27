import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

const pickup = (page) => page.getByRole('region', { name: /^(Siap diambil|Ready for pickup)/ });
const card = (scope, invoice) => scope.locator('article', { hasText: invoice });
const button = (scope, name) => scope.getByRole('button', { name, exact: true });
const chip = (page, name) => button(page.getByRole('group', { name: 'Stasiun' }), name);

// A new kitchen display, on Semua stasiun unless a station chip is given.
async function display(asRole, station) {
    const page = await asRole(role('cashier'));
    await page.goto('/dashboard/kitchen');
    if (station) await chip(page, station).click();
    return page;
}

// #361: a station screen moves an order whose items for that station are all ready under "Siap diambil".
test.describe('32-kitchen-display: ready for pickup on a station screen', () => {
    test.describe.configure({ timeout: 240_000 });

    test('Semua stasiun keeps a ready order in the grid', { tag: ['@361-BR-1', '@REQ-006-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        const sale = arrange('kitchen-sale', { prefix: uniq('Semua'), flagged: 1, station: uniq('Bar') });
        arrange('kitchen.set-item', { invoice: sale.invoice, status: 'ready' });

        const page = await display(asRole);
        await expect(card(page, sale.invoice)).toBeVisible();
        await expect(pickup(page)).toHaveCount(0);
    });

    test('an order moves aside when its station is ready, and stays while an item shown is unmade', { tag: ['@361-BR-2'] }, async ({ asRole, arrange, uniq }) => {
        const bar = uniq('Bar');
        const sale = arrange('kitchen-sale', { prefix: uniq('Sisa'), flagged: 2, station: bar });

        const page = await display(asRole, bar);
        await button(card(page, sale.invoice), 'Mulai buat').click();
        await button(card(page, sale.invoice), 'Siap').click();
        await expect(card(pickup(page), sale.invoice)).toBeVisible();

        // With Dapur shown too, its unmade line brings the order back to the working area.
        await chip(page, 'Dapur').click();
        await expect(card(page, sale.invoice).getByRole('listitem')).toHaveCount(2);
        await expect(card(pickup(page), sale.invoice)).toHaveCount(0);
    });

    test('10 longest waiting, expand, and the choice stays while the list updates', { tag: ['@361-BR-3', '@361-BR-4', '@361-BR-5'] }, async ({ asRole, arrange, uniq }) => {
        const station = uniq('Bar');
        const sell = () => arrange('kitchen-sale', { prefix: uniq('Ambil'), flagged: 1, station }).invoice;
        const ready = (invoice) => arrange('kitchen.set-item', { invoice, status: 'ready' });
        const sales = Array.from({ length: 11 }, sell);
        sales.slice(1).forEach(ready);
        // The first sale is ready last, a second later (ready_at has no fractions): it has waited the shortest.
        await new Promise((resolve) => setTimeout(resolve, 1100));
        ready(sales[0]);

        const page = await display(asRole, station);
        const cards = pickup(page).getByRole('article');
        await expect(page.getByRole('heading', { name: 'Siap diambil (11)', exact: true })).toBeVisible();
        await expect(cards).toHaveCount(10);
        await expect(cards.first()).toContainText(sales[1]);
        await expect(card(page, sales[0])).toHaveCount(0);
        await button(page, 'Lihat semua (11)').click();
        await expect(cards).toHaveCount(11);
        await expect(cards.last()).toContainText(sales[0]);

        // This flag lives in the page's JS state: a reload would wipe it.
        await page.evaluate(() => { window.keptOpen = true; });
        const added = sell();
        ready(added);
        await expect(cards).toHaveCount(12);
        await button(card(pickup(page), added), 'Serahkan semua').click();
        await expect(cards).toHaveCount(11);
        await button(page, 'Tampilkan lebih sedikit').click();
        await expect(cards).toHaveCount(10);
        expect(await page.evaluate(() => window.keptOpen)).toBe(true);

        await page.evaluate(() => localStorage.setItem('i18nextLng', 'en'));
        await page.reload();
        await expect(page.getByRole('heading', { name: 'Ready for pickup (11)', exact: true })).toBeVisible();
        await button(page, 'Show all (11)').click();
        await expect(button(page, 'Show less')).toBeVisible();
    });
});
