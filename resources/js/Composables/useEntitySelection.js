import { computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

const STORAGE_KEY = 'currentEntityId';

/**
 * Shared entity-selection state for the top nav (used by both
 * AuthenticatedLayout and PublicLayout, which each render their own copy of
 * the nav). The URL's ?entity_id= is the source of truth for the current
 * page; localStorage remembers the user's last choice (including explicitly
 * picking "Global View") so it carries over to the next page/session where
 * the URL doesn't already specify one.
 */
export function useEntitySelection(userEntitiesRef) {
    const currentEntityId = computed(() => {
        const param = new URLSearchParams(window.location.search).get('entity_id');
        if (param) return parseInt(param);

        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'global') return null;
        if (stored) {
            const id = parseInt(stored);
            if (userEntitiesRef.value.some(e => e.id === id)) return id;
        }

        return userEntitiesRef.value[0]?.id || null;
    });

    const currentEntity = computed(() => userEntitiesRef.value.find(e => e.id === currentEntityId.value));

    // If the URL names an entity explicitly, remember it as the new default.
    onMounted(() => {
        const param = new URLSearchParams(window.location.search).get('entity_id');
        if (param) localStorage.setItem(STORAGE_KEY, param);
    });

    // entityId is null for "Global View".
    function selectEntity(entityId) {
        localStorage.setItem(STORAGE_KEY, entityId ? String(entityId) : 'global');

        // The Show Editor has no meaning without an entity — bounce to the
        // Announcements view instead of leaving a broken/empty page.
        if (!entityId && route().current('shows.*')) {
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
