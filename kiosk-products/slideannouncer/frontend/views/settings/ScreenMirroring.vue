<script setup>
// Settings > Features > Screen Mirroring (AirShow, docs/AIRSHOW.md). Shown in
// the rail only while the feature is switched on in Features.vue.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api } from '../../api.js'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const status = ref(null) // { installed, enabled, background, paused_for }
const statusError = ref(null)
const starting = ref(false)
const startError = ref(null)
const savingBackground = ref(false)
const backgroundError = ref(null)
const restarting = ref(false)

const devices = ref([])
const devicesLoaded = ref(false)
const devicesError = ref(null)
const forgetting = ref(null)

async function loadStatus() {
  try {
    status.value = await api.airshowStatus()
    statusError.value = null
  } catch (err) {
    statusError.value = err.message
  }
}

async function loadDevices() {
  try {
    devices.value = (await api.airshowRemembered()).devices
    devicesError.value = null
  } catch (err) {
    devicesError.value = err.message
  } finally {
    devicesLoaded.value = true
  }
}

async function start() {
  if (starting.value) return
  starting.value = true
  startError.value = null
  try {
    // The slideshow (this page included) is stopped as AirShow takes the screen.
    await api.airshowStart()
  } catch (err) {
    startError.value = err.message
    starting.value = false
  }
}

async function setBackground(enabled) {
  if (savingBackground.value || enabled === status.value?.background) return
  savingBackground.value = true
  backgroundError.value = null
  try {
    const before = status.value?.background
    status.value = await api.setAirshowBackground(enabled)
    // The kiosk restarts to apply it, which reloads this page.
    if (status.value.background !== before) restarting.value = true
  } catch (err) {
    backgroundError.value = err.message
  } finally {
    savingBackground.value = false
  }
}

async function forget(device) {
  if (forgetting.value) return
  forgetting.value = device.id
  devicesError.value = null
  try {
    devices.value = (await api.airshowForget(device.id)).devices
  } catch (err) {
    devicesError.value = err.message
  } finally {
    forgetting.value = null
  }
}

// Why background mirroring is paused right now (backend/airshow.py's hold).
const pausedFor = computed(() =>
  (status.value?.paused_for || []).map((reason) => t(`settings.airshow.pausedFor_${reason}`)).join(', '),
)

let refresh = null
onMounted(() => {
  // Menu > Screen Mirroring lands here with ?start=1, after the PIN gate.
  // Dropped from the URL first so Back or a reload can't start it again.
  if (route.query.start === '1') {
    router.replace({ query: {} })
    start()
  }
  loadStatus()
  loadDevices()
  // Pauses come and go with streams and presentations.
  refresh = setInterval(loadStatus, 5000)
})
onUnmounted(() => clearInterval(refresh))
</script>

<template>
  <div class="settings-page">
    <section class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.airshow.title') }}</h2></div>
      <p class="hint">{{ t('settings.airshow.intro') }}</p>
      <p v-if="statusError" class="pill warn note">{{ statusError }}</p>
      <p v-else-if="status && !status.installed" class="pill warn note">{{ t('settings.airshow.notInstalled') }}</p>
      <p v-else-if="status && !status.enabled" class="hint note">{{ t('settings.features.featureOffHint') }}</p>
      <template v-else>
        <div class="start-row">
          <button type="button" class="tile action primary" :disabled="!status || starting" @click="start">
            {{ starting ? t('settings.airshow.starting') : t('settings.airshow.start') }}
          </button>
        </div>
        <p class="hint">{{ t('settings.airshow.startHint') }}</p>
        <p v-if="startError" class="pill warn note">{{ startError }}</p>
      </template>
    </section>

    <section v-if="status?.installed && status.enabled" class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.airshow.modeTitle') }}</h2></div>
      <div class="modes">
        <div class="mode">
          <button
            type="button"
            class="tile action"
            :class="{ active: !status.background }"
            :disabled="savingBackground || restarting"
            @click="setBackground(false)"
          >
            {{ t('settings.airshow.modeManual') }}
          </button>
          <p class="hint">{{ t('settings.airshow.modeManualHint') }}</p>
        </div>
        <div class="mode">
          <button
            type="button"
            class="tile action"
            :class="{ active: status.background }"
            :disabled="savingBackground || restarting"
            @click="setBackground(true)"
          >
            {{ t('settings.airshow.modeAlwaysOn') }}
          </button>
          <p class="hint">{{ t('settings.airshow.modeAlwaysOnHint') }}</p>
        </div>
      </div>
      <p v-if="status.background && pausedFor" class="pill note">{{ t('settings.airshow.paused', { reason: pausedFor }) }}</p>
      <p v-if="restarting" class="pill note">{{ t('settings.airshow.restarting') }}</p>
      <p v-if="backgroundError" class="pill warn note">{{ backgroundError }}</p>
    </section>

    <section v-if="status?.installed && status.enabled" class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.airshow.rememberedTitle') }}</h2></div>
      <p v-if="devicesError" class="pill warn note">{{ devicesError }}</p>
      <p v-if="devicesLoaded && !devices.length && !devicesError" class="hint">
        {{ t('settings.airshow.noneRemembered') }}
      </p>
      <ul v-else-if="devices.length" class="device-list">
        <li v-for="device in devices" :key="device.id" class="list-item">
          <span class="device-text">
            <span class="device-name">{{ device.name || device.id }}</span>
            <span v-if="device.protocol" class="meta">{{ device.protocol }}</span>
          </span>
          <button
            type="button"
            class="tile action"
            :disabled="forgetting === device.id"
            @click="forget(device)"
          >
            {{ t('settings.airshow.forget') }}
          </button>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.hint { color: var(--text-dim); margin: 0; }
.note { margin: 0.6rem 0 0; }
.start-row { margin: 0.9rem 0 0.6rem; }
.action { padding: 0.45rem 1rem; font-size: 0.9rem; }
.primary { font-size: 1.05rem; padding: 0.7rem 1.4rem; font-weight: 600; }
.action.active {
  border-color: var(--accent);
  color: var(--accent);
}
.modes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin-top: 0.35rem;
}
.mode {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.mode .action { padding: 0.7rem 1rem; font-size: 1rem; font-weight: 600; }
.device-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.list-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 0.6rem 1rem;
}
.device-text { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.device-name { font-weight: 600; overflow-wrap: anywhere; }
.meta { color: var(--text-dim); font-size: 0.9rem; }
</style>
