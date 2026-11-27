import { test, expect } from '../../support/fixtures.js';

// REQ-006 AC-04: a kitchen item stores its product's station when it enters the kitchen; moving the product later never moves the item.
test.describe('34-kitchen-stations: item keeps its station', () => {
    test('@REQ-006-AC-04 a product moved to another station after the sale leaves its kitchen item where it was', async ({ arrange, uniq }) => {
        const bar = uniq('Bar');
        const sale = arrange('kitchen-sale', { prefix: uniq('Kopi'), flagged: 1, station: bar }); // a real paid sale: intake gives the item the Bar station
        expect(arrange('kitchen.item-stations', { invoice: sale.invoice })).toEqual({ station_names: [bar] });

        // The product moves to a new station (the same write the product form does); the item already exists.
        const [productId] = arrange('kitchen.items', { invoice: sale.invoice }).product_ids;
        arrange('kitchen.station', { name: uniq('Panggang'), products: productId });

        expect(arrange('kitchen.item-stations', { invoice: sale.invoice })).toEqual({ station_names: [bar] });
    });
});
