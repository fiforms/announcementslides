// Plays a live channel's HLS stream (.m3u8) in one of our own <video>
// elements — no third-party page or script involved. hls.js is loaded only
// when a show actually uses an HLS channel. Kept in step with the web app's
// copy in resources/js/Composables/hlsChannel.js.
//
// attachHls(video, url, { muted }) starts playback and returns a stop()
// function. It keeps a live stream going on its own: network hiccups are
// retried, media errors recovered, and anything else restarts from scratch
// after a pause, so a screen left running for days recovers by itself.

const RESTART_DELAY_MS = 15000

async function playWithFallback(video, muted) {
  video.muted = muted
  try {
    await video.play()
  } catch {
    // A browser that blocks sound without a user gesture: muted beats frozen.
    video.muted = true
    try { await video.play() } catch { /* give up silently */ }
  }
}

export function attachHls(video, url, { muted = false } = {}) {
  let stopped = false
  let hls = null
  let restartTimer = null

  const scheduleRestart = () => {
    if (stopped || restartTimer) return
    hls?.destroy()
    hls = null
    restartTimer = setTimeout(() => { restartTimer = null; start() }, RESTART_DELAY_MS)
  }

  async function start() {
    if (stopped) return
    // Safari plays HLS natively.
    if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = url
      video.onerror = scheduleRestart
      playWithFallback(video, muted)
      return
    }
    const { default: Hls } = await import('hls.js')
    if (stopped || !Hls.isSupported()) return
    hls = new Hls({ liveDurationInfinity: true, lowLatencyMode: false })
    hls.on(Hls.Events.ERROR, (_event, data) => {
      if (!data.fatal) return
      if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
        hls.recoverMediaError()
      } else {
        scheduleRestart()
      }
    })
    hls.on(Hls.Events.MANIFEST_PARSED, () => playWithFallback(video, muted))
    hls.loadSource(url)
    hls.attachMedia(video)
  }

  start()

  return {
    stop() {
      stopped = true
      clearTimeout(restartTimer)
      hls?.destroy()
      hls = null
      video.onerror = null
      video.removeAttribute('src')
      video.load()
    },
    // After a pause, rejoin the live edge rather than resuming minutes behind.
    resume() {
      if (hls?.liveSyncPosition) video.currentTime = hls.liveSyncPosition
      playWithFallback(video, muted)
    },
    pause() {
      video.pause()
    },
  }
}
