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
const XLINK = 'http://www.w3.org/1999/xlink';

// Root <svg> attributes that aren't presentation (and so aren't carried
// over onto the wrapping <g> that replaces the root).
const ROOT_ONLY_ATTRS = new Set(['width', 'height', 'viewbox', 'x', 'y', 'id', 'class', 'version', 'preserveaspectratio', 'baseprofile']);

// Recolors painted fills/strokes (attributes and inline style) to one
// color, leaving `none`, white, url(#…) paint servers and mask content
// alone, since masks rely on white/black to work.
function recolor(root, color) {
    const keep = value => /^(none|transparent|#fff(fff)?|white|url\(.*\)|currentColor)$/i.test(value.trim());
    for (const node of [root, ...root.querySelectorAll('*')]) {
        if (node.closest('mask')) continue;
        for (const name of ['fill', 'stroke']) {
            const value = node.getAttribute(name);
            if (value !== null && !keep(value)) node.setAttribute(name, color);
        }
        const style = node.getAttribute('style');
        if (style) node.setAttribute('style', recolorCss(style, color, keep));
    }
    root.querySelectorAll('style').forEach(el => {
        el.textContent = recolorCss(el.textContent, color, keep);
    });
    // Shapes with no fill anywhere up the tree default to black.
    if (!root.hasAttribute('fill') && !/fill\s*:/.test(root.getAttribute('style') ?? '')) root.setAttribute('fill', color);
}

// Rewrites fill/stroke declarations in a style attribute or stylesheet.
function recolorCss(css, color, keep) {
    return css.replace(/(^|[;{\s])(fill|stroke)\s*:\s*([^;}]+)/gi,
        (m, sep, prop, value) => keep(value) ? m : `${sep}${prop}:${color}`);
}

export function prepareSvgImport(svgText, prefix, { color = null } = {}) {
    const body = DOMPurify.sanitize(svgText, {
        USE_PROFILES: { svg: true, svgFilters: true },
        FORBID_TAGS: ['foreignObject', 'a'],
        // Off by default because <use> can pull in external documents; the
        // href filter below limits it to #fragment references in the file.
        ADD_TAGS: ['use'],
        RETURN_DOM: true,
    });
    const root = body.querySelector('svg');
    if (!root) throw new Error('not-svg');

    root.querySelectorAll('metadata, title, desc').forEach(n => n.remove());

    // Normalize xlink:href to plain SVG 2 href (supported by browsers, rsvg
    // and Inkscape). Rewriting a namespaced attribute with setAttribute()
    // would serialize without its namespace, which the server then strips.
    for (const node of root.querySelectorAll('*')) {
        const xlink = node.getAttributeNS(XLINK, 'href') ?? node.getAttribute('xlink:href');
        if (xlink !== null) {
            node.removeAttributeNS(XLINK, 'href');
            node.removeAttribute('xlink:href');
            if (!node.hasAttribute('href')) node.setAttribute('href', xlink);
        }
        const value = node.getAttribute('href');
        if (value !== null && !SAFE_HREF.test(value.trim())) node.removeAttribute('href');
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
            if (attr.name === 'href' && attr.value.startsWith('#')) {
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

    if (color) recolor(root, color);

    // The root's own presentation attributes (fill="none", style, …) would
    // be lost with the root itself; keep them on a wrapping <g>.
    const serializer = new XMLSerializer();
    const inner = [...root.childNodes].map(n => serializer.serializeToString(n)).join('');
    const carried = [...root.attributes]
        .filter(a => !ROOT_ONLY_ATTRS.has(a.name.toLowerCase()) && !a.name.includes(':') && !a.name.startsWith('xmlns'))
        .map(a => ` ${a.name}="${escapeAttr(a.value)}"`)
        .join('');
    const markup = carried ? `<g${carried}>${inner}</g>` : inner;

    return { markup, viewBox };
}

function escapeAttr(value) {
    return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
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
