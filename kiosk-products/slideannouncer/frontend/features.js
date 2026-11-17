// On/off state for the optional features Settings > Features switches
// (Screen Mirroring, Revelation Peering, LAN Video Receiver) — shared between Features.vue,
// which flips them, and SettingsLayout.vue, whose rail only lists a
// feature's own settings page while it's on. Same tiny reactive-module
// style as menuOverlay.js.
import { reactive } from 'vue'
import { api } from './api.js'

export const features = reactive({
  loaded: false,
  revelation: false,
  srtSink: false,
  // Admin dashboard's fleet-wide SRT force-disable (see srt_sink.py) —
  // shown as a warning next to the switch, doesn't change what it does.
  srtSinkServerAllows: true,
  // Screen Mirroring (AirShow): switched on, and in the image. airshowInstalled
  // alone decides whether the switch is offered at all.
  airshow: false,
  airshowInstalled: false,
})

export async function loadFeatures() {
  const [revelation, srt, airshow] = await Promise.allSettled([
    api.revelationEnabled(),
    api.srtSinkStatus(),
    api.airshowStatus(),
  ])
  if (revelation.status === 'fulfilled') features.revelation = revelation.value.enabled
  if (srt.status === 'fulfilled') {
    features.srtSink = srt.value.local_enabled
    features.srtSinkServerAllows = srt.value.server_allows
  }
  if (airshow.status === 'fulfilled') setAirshow(airshow.value)
  features.loaded = true
}

function setAirshow(status) {
  features.airshowInstalled = status.installed
  features.airshow = status.installed && status.enabled
}

export async function setFeature(name, enabled) {
  if (name === 'airshow') {
    setAirshow(await api.setAirshowEnabled(enabled))
  } else if (name === 'revelation') {
    features.revelation = (await api.setRevelationEnabled(enabled)).enabled
  } else if (name === 'srtSink') {
    const data = await api.setSrtSink(enabled)
    features.srtSink = data.local_enabled
    features.srtSinkServerAllows = data.server_allows
  }
}
