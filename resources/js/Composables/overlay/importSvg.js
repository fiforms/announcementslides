import DOMPurify from 'dompurify';

// Turns arbitrary SVG text (an existing overlay, or a file the user picks)
// into safe markup that can be nested inside the compiled overlay as one
// fixed layer. The server sanitizes again on save; this pass protects the
// editor page itself, since the markup is inlined into its DOM.
//
// Nesting several SVGs in one document means their ids and class names
// share a namespace, so both are prefixed per layer (and references to them
// rewritten). Element-type selectors in an imported <style> can still reach
// other layers; editors like Inkscape use inline styles, so that's rare.

const SAFE_HREF = /^(#|data:image\/(png|jpe?g|gif)[;,])/i;

export function prepareSvgImport(svgText, prefix) {
    const body = DOMPurify.sanitize(svgText, {
        USE_PROFILES: { svg: true, svgFilters: true },
        FORBID_TAGS: ['foreignObject', 'a'],
        RETURN_DOM: true,
    });
    const root = body.querySelector('svg');
    if (!root) throw new Error('not-svg');

    root.querySelectorAll('metadata, title, desc').forEach(n => n.remove());

    for (const node of root.querySelectorAll('*')) {
        for (const name of ['href', 'xlink:href']) {
            const value = node.getAttribute(name);
            if (value !== null && !SAFE_HREF.test(value.trim())) node.removeAttribute(name);
        }
    }

    const ids = new Map();
    root.querySelectorAll('[id]').forEach(node => {
        const renamed = `${prefix}-${node.id}`;
        ids.set(node.id, renamed);
        node.id = renamed;
    });
    const classes = new Set();
    root.querySelectorAll('[class]').forEach(node => {
        const names = node.getAttribute('class').split(/\s+/).filter(Boolean);
        names.forEach(c => classes.add(c));
        node.setAttribute('class', names.map(c => `${prefix}-${c}`).join(' '));
    });

    const rewriteRefs = text => text
        .replace(/url\(\s*(['"]?)#([^)'"]+)\1\s*\)/g, (m, q, id) => ids.has(id) ? `url(#${ids.get(id)})` : m);

    for (const node of root.querySelectorAll('*')) {
        for (const attr of [...node.attributes]) {
            if ((attr.name === 'href' || attr.name === 'xlink:href') && attr.value.startsWith('#')) {
                const id = attr.value.slice(1);
                if (ids.has(id)) node.setAttribute(attr.name, `#${ids.get(id)}`);
            } else if (attr.value.includes('url(')) {
                node.setAttribute(attr.name, rewriteRefs(attr.value));
            }
        }
    }
    root.querySelectorAll('style').forEach(style => {
        let css = rewriteRefs(style.textContent);
        css = css.replace(/#([\w-]+)/g, (m, id) => ids.has(id) ? `#${ids.get(id)}` : m);
        css = css.replace(/\.([A-Za-z_][\w-]*)/g, (m, c) => classes.has(c) ? `.${prefix}-${c}` : m);
        style.textContent = css;
    });

    const width = parseFloat(root.getAttribute('width'));
    const height = parseFloat(root.getAttribute('height'));
    const viewBox = root.getAttribute('viewBox')
        || (width > 0 && height > 0 ? `0 0 ${width} ${height}` : null);
    if (!viewBox) throw new Error('no-size');

    const serializer = new XMLSerializer();
    const markup = [...root.childNodes].map(n => serializer.serializeToString(n)).join('');

    return { markup, viewBox };
}

// Raster overlays (PNG/WebP) and picked images are embedded as PNG/JPEG
// data URIs — the only raster formats the server-side sanitizer keeps —
// downscaled so the long edge is at most `maxEdge` pixels.
export function loadRasterAsDataUri(src, { maxEdge = 1920, forcePng = false } = {}) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
            const scale = Math.min(1, maxEdge / Math.max(img.naturalWidth, img.naturalHeight));
            const w = Math.max(1, Math.round(img.naturalWidth * scale));
            const h = Math.max(1, Math.round(img.naturalHeight * scale));
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            canvas.getContext('2d').drawImage(img, 0, 0, w, h);
            const jpeg = !forcePng && /^data:image\/jpe?g/i.test(src);
            resolve({ href: canvas.toDataURL(jpeg ? 'image/jpeg' : 'image/png', 0.9), width: w, height: h });
        };
        img.onerror = () => reject(new Error('bad-image'));
        img.src = src;
    });
}

export function readFileAs(file, method) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(reader.error);
        reader[method](file);
    });
}
