import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

// Pages that have no meaning without an entity.
const ENTITY_ROUTES = ['shows.*', 'slide-announcers.*', 'play-links.*', 'local-slides.*'];

/**
 * The entity carried by the current URL: ?entity_id=, or the {entity} path
 * parameter on /entity/{entity}/... pages. null is the Global View.
 */
export function currentEntityIdFromUrl() {
    const param = new URLSearchParams(window.location.search).get('entity_id');
    if (param) return parseInt(param) || null;
    try {
        const routed = route().params?.entity;
        return routed ? parseInt(routed) || null : null;
    } catch {
        return null;
    }
}

/**
 * Query params that keep the current entity selected on the next page.
 * Spread into route() params: route('my-slides.index', entityQuery()).
 */
export function entityQuery() {
    const id = currentEntityIdFromUrl();
    return id ? { entity_id: id } : {};
}

/**
 * Shared entity-selection state for the top nav (used by both
 * AuthenticatedLayout and PublicLayout, which each render their own copy of
 * the nav). The URL's ?entity_id= is the only source of truth: nothing is
 * remembered between visits, so a URL without one is the Global View.
 */
export function useEntitySelection(userEntitiesRef) {
    const page = usePage();

    // Reading page.url makes this recompute on every Inertia visit.
    const currentEntityId = computed(() => {
        void page.url;
        return currentEntityIdFromUrl();
    });

    const currentEntity = computed(() => userEntitiesRef.value.find(e => e.id === currentEntityId.value));

    // entityId is null for "Global View".
    function selectEntity(entityId) {
        // Entity-only pages have no global form — go back to the Announcements view.
        if (!entityId && ENTITY_ROUTES.some(r => route().current(r))) {
            router.visit(route('slides.index'), { preserveScroll: true });
            return;
        }

        const url = new URL(window.location);
        if (entityId) {
            url.searchParams.set('entity_id', entityId);
        } else {
            url.searchParams.delete('entity_id');
        }
        router.visit(url.pathname + url.search, { preserveScroll: true });
    }

    return { currentEntityId, currentEntity, selectEntity };
}
