import { QRCodeStyling } from 'beautiful-qr-code';
import { prepareSvgImport } from './importSvg.js';

export const QR_DEFAULTS = {
    foreground: '#000000',
    background: '#ffffff',
    backgroundEnabled: true,
    radius: 0.5,
    symbol: null,
    symbolColor: null, // null = same as the code's color
};

// Center symbols offered in the QR dialog: every SVG in ./qr-symbols/,
// keyed by filename (adding one is just dropping a file in; give it an
// `overlay_editor.qr_symbol_<id>` label in the locale files).
//
// Brand symbols listed in SYMBOL_HOSTS are only offered when the code's
// URL is on one of that network's domains (or a subdomain of one); every
// other symbol is always offered.
const SYMBOL_HOSTS = {
    facebook: ['facebook.com', 'fb.com', 'fb.me'],
    instagram: ['instagram.com', 'instagr.am'],
    tiktok: ['tiktok.com'],
    whatsapp: ['whatsapp.com', 'wa.me'],
    youtube: ['youtube.com', 'youtu.be'],
};

const symbolFiles = import.meta.glob('./qr-symbols/*.svg', { query: '?raw', import: 'default', eager: true });
export const QR_SYMBOLS = Object.entries(symbolFiles)
    .map(([path, svg]) => {
        const id = path.split('/').pop().replace(/\.svg$/, '');
        return { id, svg, hosts: SYMBOL_HOSTS[id] ?? null };
    })
    .sort((a, b) => a.id.localeCompare(b.id));

function onDomain(host, domain) {
    return host === domain || host.endsWith(`.${domain}`);
}

// The symbols to offer for a URL: brand symbols matching its host first,
// then the always-available ones.
export function symbolsForUrl(url) {
    let host = '';
    try {
        host = new URL(url).hostname.toLowerCase();
    } catch {
        // No valid URL yet: only the general symbols apply.
    }
    const matches = s => s.hosts.some(d => onDomain(host, d));

    return [
        ...QR_SYMBOLS.filter(s => s.hosts && host && matches(s)),
        ...QR_SYMBOLS.filter(s => !s.hosts),
    ];
}

// Error correction is chosen for the user: H (30% recovery) when a center
// symbol blanks the middle of the code, otherwise M (15%), which keeps the
// code less dense so it scans from further across the room.
export function errorCorrectionFor(symbol) {
    return symbol ? 'H' : 'M';
}

// Renders a QR code with beautiful-qr-code and returns its inner markup and
// viewBox, cleaned up for nesting inside the overlay SVG: no XML
// declaration, <title> tooltip, ids or classes (several QR codes can share
// one document).
export async function renderQr({ data, foreground, background, backgroundEnabled, radius, symbol, symbolColor }) {
    const symbolSvg = symbol ? QR_SYMBOLS.find(s => s.id === symbol)?.svg : null;
    const padding = backgroundEnabled ? 2 : 0;
    const svg = await new QRCodeStyling({
        data,
        foregroundColor: foreground,
        backgroundColor: backgroundEnabled ? background : 'transparent',
        radius,
        padding,
        errorCorrectionLevel: errorCorrectionFor(symbolSvg),
        // Clears the middle third of the modules; the library's own logo is
        // a raster <image>, so the symbol is drawn in as vector art below.
        hasLogo: !!symbolSvg,
    }).getSVG();

    const doc = new DOMParser().parseFromString(svg.replace(/^<\?xml[^>]*\?>/, ''), 'image/svg+xml');
    const root = doc.documentElement;
    root.querySelectorAll('title, desc').forEach(n => n.remove());
    root.querySelectorAll('[id], [class]').forEach(n => {
        n.removeAttribute('id');
        n.removeAttribute('class');
    });

    const serializer = new XMLSerializer();
    let markup = [...root.childNodes].map(n => serializer.serializeToString(n)).join('');
    const viewBox = root.getAttribute('viewBox');

    if (symbolSvg) {
        markup += symbolMarkup(symbolSvg, viewBox, 2 * padding, symbolColor || foreground);
    }

    return { markup, viewBox };
}

// Places a symbol in the area the library cleared: modules
// [round(n/3), round(2n/3)) in each direction, 2 viewBox units per module,
// inset by half a module so it doesn't touch the surrounding modules.
function symbolMarkup(symbolSvg, viewBox, paddingUnits, color) {
    const width = Number(viewBox.split(/[\s,]+/)[2]);
    const modules = width / 2 - paddingUnits;
    const start = Math.round(modules / 3) * 2 + 1;
    const size = Math.round(modules * 2 / 3) * 2 - 1 - start;
    // Random prefix: a code made in an earlier session may already use a
    // sequential one in the same overlay file.
    const prefix = `qrs-${Math.random().toString(36).slice(2, 8)}`;
    const { markup, viewBox: symbolViewBox } = prepareSvgImport(symbolSvg, prefix, { color });

    return `<svg x="${start}" y="${start}" width="${size}" height="${size}" viewBox="${symbolViewBox}" `
        + `preserveAspectRatio="xMidYMid meet">${markup}</svg>`;
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

    return { ...cleanUrl(url), error: null };
}

// Tracking parameters that never change what a page shows, so a QR code is
// shorter (and scans more easily) without them. utm_* is deliberately kept
// in general: a church may add it on purpose to count scans.
const TRACKING_PARAMS = new Set([
    'fbclid', 'gclid', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'twclid', 'ttclid', 'yclid',
    'igsh', 'igshid', 'mibextid', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi',
]);

// Share/tracking parameters that are only noise on a particular site (the
// same names could mean something real elsewhere).
const SITE_TRACKING_PARAMS = [
    { domains: ['youtube.com', 'youtu.be'], test: name => ['si', 'feature', 'pp'].includes(name) },
    { domains: ['tiktok.com'], test: name => ['_t', '_r', 'is_from_webapp', 'sender_device'].includes(name) || name.startsWith('share_') },
    { domains: ['instagram.com'], test: (name, value) => name === 'utm_source' && value.startsWith('ig_') },
    { domains: ['twitter.com', 'x.com'], test: name => ['s', 't'].includes(name) },
];

// Removes tracking parameters; returns the cleaned URL and what was removed.
export function cleanUrl(input) {
    const url = new URL(input);
    const host = url.hostname.toLowerCase();
    const rules = SITE_TRACKING_PARAMS.filter(r => r.domains.some(d => onDomain(host, d)));

    const removed = [...url.searchParams].filter(([name, value]) =>
        TRACKING_PARAMS.has(name.toLowerCase()) || rules.some(r => r.test(name, value)),
    ).map(([name]) => name);

    // Only rebuild when something goes, so untouched URLs keep their exact encoding.
    if (removed.length) removed.forEach(name => url.searchParams.delete(name));

    return { url: url.href, removed: [...new Set(removed)] };
}
