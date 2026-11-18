// T3-1: decides what a scanned QR code may open. Pure, so it can be checked on its own.
//
// Only our own pickup links count: the path must be <prefix>TA-######, with a signature. The
// result is a path to open on *this* site (location.origin + path), never the scanned host,
// so a QR code for another site can't send the barista anywhere (no open redirect). The
// signature itself is checked by the server (signed:relative), which answers 403 if it's wrong.

const ORDER_NUMBER = /^TA-\d{6}$/;

/**
 * @param {string} text   what the QR code contains
 * @param {string} prefix the pickup route's path up to the order number, e.g. "/barista/pickup/"
 * @param {string} origin this site's origin, e.g. location.origin
 * @returns {string|null} "/barista/pickup/TA-123456?signature=…" to open here, or null to ignore
 */
export function pickupTarget(text, prefix, origin) {
    let url;

    try {
        url = new URL(String(text).trim(), origin);
    } catch {
        return null;
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return null;
    }

    if (!url.pathname.startsWith(prefix)) {
        return null;
    }

    const number = url.pathname.slice(prefix.length);

    if (!ORDER_NUMBER.test(number) || !url.searchParams.get('signature')) {
        return null;
    }

    return prefix + number + url.search;
}
