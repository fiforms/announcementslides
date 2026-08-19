import { ref, onMounted, onUnmounted } from 'vue';

/**
 * Shared state/behavior for the single-slide lightbox (see SlideLightbox.vue):
 * which slide is open, Escape-to-close, and locking page scroll while open.
 * Kept separate from the component so every page that can open a lightbox
 * (Announcements, Archive, Show Editor, ...) gets identical behavior without
 * re-wiring the keydown listener and scroll lock each time.
 */
export function useLightbox() {
    const lightboxSlide = ref(null);

    function openLightbox(slide) {
        lightboxSlide.value = slide;
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightboxSlide.value = null;
        document.body.style.overflow = '';
    }

    function onLightboxKeydown(e) {
        if (e.key === 'Escape') closeLightbox();
    }

    onMounted(() => document.addEventListener('keydown', onLightboxKeydown));
    onUnmounted(() => {
        document.removeEventListener('keydown', onLightboxKeydown);
        document.body.style.overflow = '';
    });

    return { lightboxSlide, openLightbox, closeLightbox };
}
