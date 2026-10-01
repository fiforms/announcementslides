import axios from 'axios';

// Runtime for overlay widgets: loads a widget's ES module and mounts it
// into a box the host has already positioned and scaled (see
// WidgetLayer.vue). A widget module exports
//
//     export function mount(el, { width, height, params, api }) {
//         …draw into el, in a width × height coordinate space…
//         return () => { …stop timers, abort fetches… };   // cleanup
//     }
//
// Everything a widget needs from the host goes through `api`, so the
// widget stays portable if it's ever moved into a sandboxed iframe.

export class WidgetDataError extends Error {
    constructor(reason, status) {
        super(`Widget data unavailable: ${reason}`);
        this.reason = reason;
        this.status = status;
    }
}

function storageFor(prefix) {
    const key = k => `${prefix}${k}`;
    return {
        get(k) {
            try {
                const raw = localStorage.getItem(key(k));
                return raw === null ? null : JSON.parse(raw);
            } catch {
                return null;
            }
        },
        set(k, value) {
            try { localStorage.setItem(key(k), JSON.stringify(value)); } catch { /* storage unavailable */ }
        },
        remove(k) {
            try { localStorage.removeItem(key(k)); } catch { /* storage unavailable */ }
        },
    };
}

// `placement` is a saved placement from the server (live mode: has
// data_url) or an unsaved editor element (editor mode: fetches go through
// the preview endpoint with its current params).
export function createApi(placement, { mode, locale }) {
    // Runtime args (e.g. a forecast's lat/lon) travel as ?args[name]=value;
    // the server checks them against the endpoint's declared `args`.
    function withArgs(url, args) {
        const query = new URLSearchParams();
        for (const [k, v] of Object.entries(args ?? {})) query.append(`args[${k}]`, String(v));
        const qs = query.toString();
        return qs ? `${url}?${qs}` : url;
    }

    async function fetchLive(endpoint, args) {
        const url = withArgs(placement.data_url.replace('__endpoint__', encodeURIComponent(endpoint)), args);
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) throw new WidgetDataError(body.error ?? 'unavailable', res.status);
        return body;
    }

    async function fetchPreview(endpoint, args) {
        try {
            const { data } = await axios.post(route('widget-data.preview'), {
                widget: placement.widget, endpoint, params: placement.params ?? {}, args: args ?? {},
            });
            return data;
        } catch (err) {
            throw new WidgetDataError(err.response?.data?.error ?? 'unavailable', err.response?.status);
        }
    }

    return Object.freeze({
        mode,
        locale,
        // fetch(endpoint, args?) resolves to { data, fetched_at, stale };
        // rejects with WidgetDataError (reason e.g. 'not_configured',
        // 'invalid_args', 'rate_limited', 'upstream_status').
        fetch: mode === 'editor' ? fetchPreview : fetchLive,
        storage: storageFor(`as-widget:${placement.widget}:${placement.id}:`),
    });
}

// Mounts one placement into `el`; resolves to a cleanup function. Safe to
// call cleanup before the module has finished loading.
export function mountWidget(el, placement, entryUrl, options) {
    let disposed = false;
    let cleanup = null;

    const ready = (async () => {
        const mod = await import(/* @vite-ignore */ entryUrl);
        if (disposed) return;
        const result = await mod.mount(el, {
            width: placement.w,
            height: placement.h,
            params: Object.freeze({ ...(placement.params ?? {}) }),
            api: createApi(placement, options),
        });
        cleanup = typeof result === 'function' ? result : result?.destroy?.bind(result) ?? null;
        if (disposed) runCleanup();
    })().catch(err => {
        console.warn(`Widget "${placement.widget}" failed to load`, err);
    });

    function runCleanup() {
        const fn = cleanup;
        cleanup = null;
        try { fn?.(); } catch (err) { console.warn(`Widget "${placement.widget}" cleanup failed`, err); }
        el.replaceChildren();
    }

    return {
        ready,
        dispose() {
            disposed = true;
            runCleanup();
        },
    };
}
