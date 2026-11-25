import { test, expect } from '../../support/fixtures.js';

test('the public invoice is rate limited per IP', async ({ arrange, request }) => {
    arrange('public.reset-throttle');
    // A refused token costs the same throttle hit as a good one and skips the slow PDF render.
    const url = '/share/transactions/INV-NOBODY?token=nope';

    // throttle:10,1 on the route.
    for (let i = 0; i < 10; i++) {
        expect((await request.get(url)).status()).toBe(404);
    }
    expect((await request.get(url)).status()).toBe(429);

    // Leave an empty bucket for the next step.
    arrange('public.reset-throttle');
});
