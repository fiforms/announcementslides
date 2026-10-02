<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import SlideshowModal from '@/Components/SlideshowModal.vue';

const props = defineProps({
    token: { type: String, required: true },
    title: { type: String, default: '' },
    slides: { type: Array, default: () => [] },
    delaySeconds: { type: Number, required: true },
    refreshSeconds: { type: Number, default: 300 },
});

const slides = ref(props.slides);
const delaySeconds = ref(props.delaySeconds);
const revoked = ref(false);

// An unattended screen never reloads, so re-check periodically: new or
// edited slides, a changed delay, and revocation all reach it. A failed
// check (offline, server restarting) just keeps playing what it has.
let snapshot = JSON.stringify(props.slides) + props.delaySeconds;
let timer = null;

async function refresh() {
    try {
        const res = await fetch(route('play.slides', { token: props.token }), {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        if (res.status === 404) {
            revoked.value = true;
            return;
        }
        if (!res.ok) return;
        const data = await res.json();
        const next = JSON.stringify(data.slides) + data.delaySeconds;
        if (next !== snapshot) {
            snapshot = next;
            slides.value = data.slides;
            delaySeconds.value = data.delaySeconds;
        }
    } catch {
        /* offline: keep playing */
    }
}

onMounted(() => {
    timer = setInterval(refresh, props.refreshSeconds * 1000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <Head :title="title" />

    <div class="fixed inset-0 bg-black text-gray-400 flex items-center justify-center text-center p-8">
        <p v-if="revoked">{{ $t('play.revoked') }}</p>
        <p v-else-if="!slides.length">{{ $t('play.no_slides') }}</p>
    </div>

    <SlideshowModal
        v-if="!revoked && slides.length"
        kiosk
        :show="true"
        :slides="slides"
        :interval-seconds="delaySeconds"
    />
</template>
