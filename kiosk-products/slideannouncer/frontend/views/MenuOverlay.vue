<script setup>
// Overlay on top of whatever's currently showing — not a route change, so
// the slideshow keeps running underneath it and Back (remoteNav.js)
// dismisses without navigating anywhere. Mounted at the top level in
// App.vue alongside <router-view>, not nested inside it.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { menuOpen, closeMenu } from '@core/menuOverlay.js'
import { shows, pinnedShowId, pinShow } from '../slideshowState.js'
import { features, loadFeatures } from '../features.js'
import { groupShows } from '../showFolders.js'

const router = useRouter()
const { t } = useI18n()

// Show folders (showFolders.js): "Channels/3ABN" is listed as "3ABN" inside a
// "Channels" folder. Enter opens a folder; Back (or its "‹" header) returns.
// The main show is never in a folder. Live channels the church offers come
// from the sync as slide-less shows (is_channel) and get a folder of their
// own, last. Folders are tracked by key, so a church folder that happens to
// be called "Live Channels" stays separate.
const entries = computed(() => {
  const list = groupShows(shows.value.filter((show) => !show.is_channel), { foldable: (show) => !show.is_main })
    .map((entry) => ({ ...entry, key: entry.folder ? `f:${entry.folder}` : `s:${entry.show.id}` }))
  const channels = shows.value.filter((show) => show.is_channel)
  if (channels.length) {
    list.push({ key: 'channels', folder: t('menu.liveChannels'), items: channels.map((show) => ({ show, label: show.name })) })
  }
  return list
})
const openFolderKey = ref(null)
const openFolder = computed(() => entries.value.find((e) => e.folder && e.key === openFolderKey.value) ?? null)
const isPlaying = (show) => show.id === pinnedShowId.value
const folderIsPlaying = (entry) => entry.items.some((item) => isPlaying(item.show))

function focusFirst(selector = '.menu-overlay .list-item') {
  nextTick(() => document.querySelector(selector)?.focus())
}

function enterFolder(entry) {
  openFolderKey.value = entry.key
  focusFirst('.menu-overlay .folder-items .list-item')
}

function leaveFolder() {
  const key = openFolderKey.value
  openFolderKey.value = null
  nextTick(() => {
    const row = [...document.querySelectorAll('.menu-overlay [data-folder]')].find((el) => el.dataset.folder === key)
    ;(row ?? document.querySelector('.menu-overlay .list-item'))?.focus()
  })
}

// Back inside a folder goes up a level instead of closing the menu. This
// runs in the capture phase, ahead of remoteNav.js's own (bubble-phase)
// window listener, and stops the event so neither it nor Slideshow.vue's
// listener also handles the same press. Same key list as remoteNav.js.
const BACK_KEYS = ['GoBack', 'BrowserBack', 'Back', 'Escape']
function onKeydown(event) {
  if (!menuOpen.value || !openFolder.value || !BACK_KEYS.includes(event.key)) return
  event.preventDefault()
  event.stopImmediatePropagation()
  leaveFolder()
}
window.addEventListener('keydown', onKeydown, true)
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown, true))

async function selectShow(show) {
  // "Main" is always represented as null server-side (see pin-show's
  // contract) rather than its own id, so an explicit re-pin to Main
  // behaves identically to never having pinned anything.
  await pinShow(show.is_main ? null : show.id)
  closeMenu()
}

// Starts AirShow mode (switched on in Settings > Features). It goes through the
// Screen Mirroring settings page with ?start=1 so the router's Settings PIN
// gate (router.js) asks for the PIN first when one is set: AirShow mode is
// where new senders are approved, and an approved sender can then take over
// the screen in background mode. Without a PIN it starts straight away.
function openScreenMirroring() {
  closeMenu()
  router.push({ path: '/settings/airshow', query: { start: '1' } })
}

function openSettings() {
  closeMenu()
  router.push('/settings')
}

// Opening the overlay isn't a route change, so router.afterEach's
// focus-management hook (remoteNav.js) never fires for it — focus the
// first item ourselves whenever it opens.
watch(menuOpen, (open) => {
  if (!open) return
  openFolderKey.value = null
  if (!features.loaded) loadFeatures().catch(() => {})
  focusFirst()
})

// A folder emptied by a sync (shows renamed or removed) falls back to the top.
watch(openFolder, (folder) => {
  if (!folder && openFolderKey.value) leaveFolder()
})
</script>

<template>
  <div v-if="menuOpen" class="menu-overlay">
    <div class="menu-card">
      <template v-if="openFolder">
        <button class="list-item folder-header" @click="leaveFolder">
          <span>‹ {{ openFolder.folder }}</span>
        </button>
        <div class="folder-items">
          <button
            v-for="item in openFolder.items"
            :key="item.show.id"
            class="list-item"
            :class="{ 'list-item--active': isPlaying(item.show) }"
            @click="selectShow(item.show)"
          >
            <span>{{ item.label }}</span>
            <span v-if="isPlaying(item.show)" class="pill ok">{{ t('menu.playing') }}</span>
          </button>
        </div>
      </template>
      <template v-else>
        <template v-for="entry in entries" :key="entry.key">
          <button
            v-if="entry.folder"
            class="list-item"
            :class="{ 'list-item--active': folderIsPlaying(entry) }"
            :data-folder="entry.key"
            @click="enterFolder(entry)"
          >
            <span>{{ entry.folder }}</span>
            <span class="folder-tail">
              <span v-if="folderIsPlaying(entry)" class="pill ok">{{ t('menu.playing') }}</span>
              <span aria-hidden="true">›</span>
            </span>
          </button>
          <button
            v-else
            class="list-item"
            :class="{ 'list-item--active': isPlaying(entry.show) }"
            @click="selectShow(entry.show)"
          >
            <span>{{ entry.show.is_main ? t('menu.mainShow') : entry.show.name }}</span>
            <span v-if="isPlaying(entry.show)" class="pill ok">{{ t('menu.playing') }}</span>
          </button>
        </template>
      </template>
      <div class="menu-divider" />
      <button v-if="features.airshow" class="list-item" @click="openScreenMirroring">
        {{ t('menu.screenMirroring') }}
      </button>
      <button class="list-item" @click="openSettings">
        {{ t('menu.settings') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.menu-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.menu-card {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  width: min(30rem, 80vw);
  max-height: 80vh;
  overflow-y: auto;
  background: var(--panel);
  border: var(--line) solid var(--border);
  border-radius: 1rem;
  padding: 1.5rem;
}
.menu-card .list-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.2rem;
  font-size: 1.1rem;
}
.menu-card .list-item--active {
  border-color: var(--accent);
}
.folder-items {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.folder-header {
  color: var(--text-dim);
}
.folder-tail {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.menu-divider {
  border-top: var(--line) solid var(--border);
  margin: 0.25rem 0;
}
</style>
