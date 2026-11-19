<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import WidgetLayer from './WidgetLayer.vue'
import { attachHls } from '../hlsChannel.js'

// One half of a show's frame — the kiosk twin of the main app's
// resources/js/Components/ShowFrameLayer.vue. Slideshow.vue mounts it once
// and leaves it alone while slides come and go, so a clock doesn't restart
// and a background video doesn't reload on a slide change:
//   layer="base" — the background image/video, under the slide stages.
//   layer="top"  — the overlay image and widgets, over the slide stages
//                  (so above a slide's own overlay and widgets).
// A YouTube background is `youtube_url` instead of media_url: a server-built
// youtube-nocookie embed, checked here before it goes into an iframe. It
// needs the internet, so the iframe is only mounted while online (offline
// it would show a browser error page; it resumes when the network returns).
// A live channel is `channel`: {kind, url, muted}. An 'iframe' embed must
// start with a known provider prefix (the server's LiveChannelSource) and,
// having no player API, is unmounted while suspended; an 'hls' stream plays
// in our own <video> (hls.js) and pauses/rejoins the live edge instead.
const props = defineProps({
  frame: { type: Object, required: true },
  layer: { type: String, required: true },
  // The background video holds still while the show is paused, and while
  // an external feed is on screen (two audio sources must never mix).
  suspended: { type: Boolean, default: false },
  // Streamed backgrounds (YouTube, live channels) are removed outright —
  // nothing left downloading — while Slideshow.vue's idle interlock holds.
  halted: { type: Boolean, default: false },
})

const videoEl = ref(null)
const ytEl = ref(null)

const YOUTUBE_PREFIX = 'https://www.youtube-nocookie.com/embed/'
const online = ref(navigator.onLine)
const setOnline = () => { online.value = navigator.onLine }
window.addEventListener('online', setOnline)
window.addEventListener('offline', setOnline)
onBeforeUnmount(() => {
  window.removeEventListener('online', setOnline)
  window.removeEventListener('offline', setOnline)
})
const youtubeSrc = computed(() => (!props.halted && online.value && props.frame.youtube_url?.startsWith(YOUTUBE_PREFIX))
  ? `${props.frame.youtube_url}&origin=${encodeURIComponent(window.location.origin)}`
  : null)

const CHANNEL_IFRAME_PREFIXES = ['https://embed.jetstream.studio/embed/', 'https://player.zype.com/embed/']
const channel = computed(() => {
  const c = props.frame.channel
  if (props.halted || !online.value || !c?.url) return null
  if (c.kind === 'iframe' && CHANNEL_IFRAME_PREFIXES.some((p) => c.url.startsWith(p))) return c
  if (c.kind === 'hls' && c.url.startsWith('https://')) return c
  return null
})

const hlsEl = ref(null)
let hlsPlayer = null
function startHls() {
  hlsPlayer?.stop()
  hlsPlayer = null
  if (hlsEl.value && channel.value?.kind === 'hls') {
    hlsPlayer = attachHls(hlsEl.value, channel.value.url, { muted: !!channel.value.muted })
    if (props.suspended) hlsPlayer.pause()
  }
}
watch(() => channel.value?.url, startHls, { flush: 'post' })
onBeforeUnmount(() => hlsPlayer?.stop())

// A background iframe must never keep keyboard focus: once it has it, remote
// keys (Menu, Back, Home) go to the broadcaster's page and the kiosk can't
// react to them. Focus can land there from a click into the player (the
// JetStream play assist, or the remote's pointer). The top window gets a
// `blur` when focus moves into a child frame; take it straight back.
function reclaimFocus() {
  setTimeout(() => {
    const el = document.activeElement
    if (el?.tagName === 'IFRAME' && el.closest('.frame-layer')) {
      el.blur()
      window.focus()
    }
  }, 0)
}
if (props.layer === 'base') {
  window.addEventListener('blur', reclaimFocus)
  onBeforeUnmount(() => window.removeEventListener('blur', reclaimFocus))
}

// enablejsapi=1 (in the URL) lets the player be paused and resumed by message.
function youtubeCommand(func) {
  ytEl.value?.contentWindow?.postMessage(JSON.stringify({ event: 'command', func, args: [] }), '*')
}

// kiosk-start.sh launches Chromium with --autoplay-policy=no-user-gesture-
// required, so this plays with sound; the muted retry is only a safety net.
async function play() {
  const el = videoEl.value
  if (!el) return
  el.muted = false
  try {
    await el.play()
  } catch {
    el.muted = true
    try { await el.play() } catch { /* give up silently */ }
  }
}

onMounted(() => {
  if (!props.suspended) play()
  startHls()
})
watch(() => props.suspended, (suspended) => {
  if (suspended) {
    videoEl.value?.pause()
    youtubeCommand('pauseVideo')
    hlsPlayer?.pause()
  } else {
    play()
    youtubeCommand('playVideo')
    hlsPlayer?.resume()
  }
})
</script>

<template>
  <div class="frame-layer">
    <template v-if="layer === 'base' && youtubeSrc">
      <div class="frame-youtube">
        <iframe
          ref="ytEl"
          :src="youtubeSrc"
          title=""
          tabindex="-1"
          allow="autoplay; encrypted-media"
          sandbox="allow-scripts allow-same-origin allow-presentation"
          referrerpolicy="strict-origin-when-cross-origin"
        />
      </div>
    </template>
    <template v-else-if="layer === 'base' && channel">
      <video v-if="channel.kind === 'hls'" ref="hlsEl" playsinline class="frame-image" />
      <div v-else-if="!suspended" class="frame-youtube">
        <iframe
          :src="channel.url"
          title=""
          tabindex="-1"
          allow="autoplay; encrypted-media"
          sandbox="allow-scripts allow-same-origin allow-presentation"
          referrerpolicy="strict-origin-when-cross-origin"
        />
      </div>
    </template>
    <template v-else-if="layer === 'base' && frame.media_url">
      <video
        v-if="frame.mime_type?.startsWith('video/')"
        ref="videoEl"
        :src="frame.media_url"
        loop
        preload="auto"
        playsinline
        class="frame-image"
      />
      <img v-else :src="frame.media_url" class="frame-image">
    </template>
    <template v-else-if="layer === 'top'">
      <img v-if="frame.overlay_media_url" :src="frame.overlay_media_url" class="frame-image">
      <WidgetLayer v-if="frame.widgets?.length" :widgets="frame.widgets" />
    </template>
  </div>
</template>

<style scoped>
.frame-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.frame-youtube {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.frame-youtube iframe {
  border: 0;
  aspect-ratio: 16 / 9;
  width: min(100%, calc(100vh * 16 / 9));
}
.frame-image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
}
</style>
