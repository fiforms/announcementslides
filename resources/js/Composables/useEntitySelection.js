import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

// Pages that have no meaning without an entity.
const ENTITY_ROUTES = ['shows.*', 'slide-announcers.*', 'play-links.*', 'local-slides.*'];

/**
 * Shared entity-selection state for the top nav (used by both
 * AuthenticatedLayout and PublicLayout, which each render their own copy of
 * the nav). The URL's ?entity_id= is the only source of truth: nothing is
 * remembered between visits, so a URL without one is the Global View.
 */
export function useEntitySelection(userEntitiesRef) {
    const currentEntityId = computed(() => {
        const param = new URLSearchParams(window.location.search).get('entity_id');
        return param ? parseInt(param) : null;
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
