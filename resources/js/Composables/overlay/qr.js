import { QRCodeStyling } from 'beautiful-qr-code';

export const QR_DEFAULTS = {
    foreground: '#000000',
    background: '#ffffff',
    backgroundEnabled: true,
    radius: 0.5,
    ecl: 'M',
};

// Renders a QR code with beautiful-qr-code and returns its inner markup and
// viewBox, cleaned up for nesting inside the overlay SVG: no XML
// declaration, <title> tooltip, ids or classes (several QR codes can share
// one document).
export async function renderQr({ data, foreground, background, backgroundEnabled, radius, ecl }) {
    const svg = await new QRCodeStyling({
        data,
        foregroundColor: foreground,
        backgroundColor: backgroundEnabled ? background : 'transparent',
        radius,
        padding: backgroundEnabled ? 2 : 0,
        errorCorrectionLevel: ecl,
    }).getSVG();

    const doc = new DOMParser().parseFromString(svg.replace(/^<\?xml[^>]*\?>/, ''), 'image/svg+xml');
    const root = doc.documentElement;
    root.querySelectorAll('title, desc').forEach(n => n.remove());
    root.querySelectorAll('[id], [class]').forEach(n => {
        n.removeAttribute('id');
        n.removeAttribute('class');
    });

    const serializer = new XMLSerializer();
    return {
        markup: [...root.childNodes].map(n => serializer.serializeToString(n)).join(''),
        viewBox: root.getAttribute('viewBox'),
    };
}

// Light validation for a typed-in QR target. Adds https:// when no scheme
// is given; accepts only http(s) URLs with a plausible host.
export function normalizeUrl(input) {
    const trimmed = (input ?? '').trim();
    if (!trimmed) return { url: null, error: 'empty' };

    const candidate = /^[a-z][a-z\d+.-]*:/i.test(trimmed) ? trimmed : `https://${trimmed}`;
    let url;
    try {
        url = new URL(candidate);
    } catch {
        return { url: null, error: 'invalid' };
    }
    if (!['http:', 'https:'].includes(url.protocol)) return { url: null, error: 'scheme' };
    if (!url.hostname.includes('.') && url.hostname !== 'localhost') return { url: null, error: 'invalid' };

    return { url: url.href, error: null };
}
