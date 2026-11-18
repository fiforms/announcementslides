<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import WidgetLayer from '@/Components/Widgets/WidgetLayer.vue';
import { attachHls } from '@/Composables/hlsChannel';

// One half of a show's frame, mounted once by the player and left alone
// while slides come and go (so a clock doesn't restart and a background
// video doesn't reload on a slide change):
//   layer="base" — the background image/video, under the slide stages.
//   layer="top"  — the overlay image and widgets, over the slide stages
//                  (so above a slide's own overlay and widgets).
// `frame` has a slide's field names: file_url/mime_type for the background,
// overlay_url/overlay_widgets for the top layer. A YouTube background is
// `youtube_url` instead of file_url: a server-built youtube-nocookie embed,
// checked here before it goes into an iframe. A live channel is `channel`:
// {kind, url, muted} — an 'iframe' embed whose URL must start with a known
// provider prefix (the server's LiveChannelSource::PROVIDERS), or an 'hls'
// stream played in our own <video>.
const props = defineProps({
    frame: { type: Object, required: true },
    layer: { type: String, required: true },
});

const videoEl = ref(null);

const YOUTUBE_PREFIX = 'https://www.youtube-nocookie.com/embed/';
const youtubeSrc = computed(() => props.frame.youtube_url?.startsWith(YOUTUBE_PREFIX)
    ? `${props.frame.youtube_url}&origin=${encodeURIComponent(window.location.origin)}`
    : null);

const CHANNEL_IFRAME_PREFIXES = ['https://embed.jetstream.studio/embed/', 'https://player.zype.com/embed/'];
const channel = computed(() => {
    const c = props.frame.channel;
    if (!c?.url) return null;
    if (c.kind === 'iframe' && CHANNEL_IFRAME_PREFIXES.some((p) => c.url.startsWith(p))) return c;
    if (c.kind === 'hls' && c.url.startsWith('https://')) return c;
    return null;
});

const hlsEl = ref(null);
let hlsPlayer = null;
function startHls() {
    hlsPlayer?.stop();
    hlsPlayer = null;
    if (hlsEl.value && channel.value?.kind === 'hls') {
        hlsPlayer = attachHls(hlsEl.value, channel.value.url, { muted: !!channel.value.muted });
    }
}
onMounted(startHls);
watch(() => channel.value?.url, startHls, { flush: 'post' });
onBeforeUnmount(() => hlsPlayer?.stop());

// Loops with sound where the browser allows it, else muted rather than
// frozen (same fallback as SlideStage).
onMounted(async () => {
    const el = videoEl.value;
    if (!el) return;
    el.muted = false;
    try {
        await el.play();
    } catch {
        el.muted = true;
        try { await el.play(); } catch { /* give up silently */ }
    }
});
</script>

<template>
    <div class="pointer-events-none absolute inset-0">
        <template v-if="layer === 'base' && youtubeSrc">
            <!-- Kept 16:9 and centred, like every other layer. Browsers may keep
                 sound off until the page has been interacted with. -->
            <div class="absolute inset-0 flex items-center justify-center overflow-hidden">
                <iframe :src="youtubeSrc" title="" tabindex="-1" allow="autoplay; encrypted-media"
                    sandbox="allow-scripts allow-same-origin allow-presentation"
                    referrerpolicy="strict-origin-when-cross-origin"
                    style="border: 0; aspect-ratio: 16 / 9; width: min(100%, calc(100vh * 16 / 9));" />
            </div>
        </template>
        <template v-else-if="layer === 'base' && channel">
            <div class="absolute inset-0 flex items-center justify-center overflow-hidden">
                <video v-if="channel.kind === 'hls'" ref="hlsEl" playsinline
                    class="absolute inset-0 h-full w-full object-contain" />
                <iframe v-else :src="channel.url" title="" tabindex="-1" allow="autoplay; encrypted-media"
                    sandbox="allow-scripts allow-same-origin allow-presentation"
                    referrerpolicy="strict-origin-when-cross-origin"
                    style="border: 0; aspect-ratio: 16 / 9; width: min(100%, calc(100vh * 16 / 9));" />
            </div>
        </template>
        <template v-else-if="layer === 'base' && frame.file_url">
            <video v-if="frame.mime_type?.startsWith('video/')" ref="videoEl" :src="frame.file_url"
                loop autoplay playsinline preload="auto" class="absolute inset-0 h-full w-full object-contain" />
            <img v-else :src="frame.file_url" alt="" class="absolute inset-0 h-full w-full object-contain" />
        </template>
        <template v-else-if="layer === 'top'">
            <img v-if="frame.overlay_url" :src="frame.overlay_url" alt="" class="absolute inset-0 h-full w-full object-contain" />
            <WidgetLayer v-if="frame.overlay_widgets?.length" :widgets="frame.overlay_widgets" />
        </template>
    </div>
</template>
