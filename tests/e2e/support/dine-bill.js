// POST /dine-order/{token}/pay the way the guest page's axios sends it: JSON, with the XSRF token of the page's session.
// The page of any order must be open first (it sets the session and the XSRF cookie). Returns { status, body }.
export function postPay(page, token, body = {}) {
    return page.evaluate(async ({ token, body }) => {
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
        const response = await fetch(`/dine-order/${token}/pay`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
            body: JSON.stringify(body),
        });

        return { status: response.status, body: await response.json().catch(() => null) };
    }, { token, body });
}
