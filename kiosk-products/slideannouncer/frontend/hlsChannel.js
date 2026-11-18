// Plays a live channel's HLS stream (.m3u8) in one of our own <video>
// elements — no third-party page or script involved. hls.js is loaded only
// when a show actually uses an HLS channel. Kept in step with the web app's
// copy in resources/js/Composables/hlsChannel.js.
//
// attachHls(video, url, { muted }) starts playback and returns
// { stop, pause, resume }. It keeps a live stream going on its own, so a
// screen left running for days recovers by itself:
//  - hls.js is used wherever Media Source Extensions exist. Native HLS is
//    only the fallback (Safari/iOS): recent Chromium (the Pi runs 156)
//    claims native HLS too, but that path has none of the recovery below,
//    and live streams played that way stopped after a few seconds.
//  - Fatal network errors restart after a pause; media errors are recovered.
//  - A watchdog restarts the stream when playback stops advancing on its own
//    (live streams can stall without ever raising an error).
//  - Audio-only renditions are dropped: some broadcasters (It Is Written)
//    list one beside the video qualities, and when hls.js's bandwidth logic
//    switches to it the picture freezes on its last frame.

const RESTART_DELAY_MS = 15000
const WATCHDOG_INTERVAL_MS = 5000
const STALL_LIMIT_MS = 20000

async function playWithFallback(video, muted) {
  video.muted = muted
  try {
    await video.play()
  } catch {
    // A browser that blocks sound without a user gesture: muted beats frozen.
    video.muted = true
    try { await video.play() } catch { /* the watchdog retries */ }
  }
}

export function attachHls(video, url, { muted = false } = {}) {
  let stopped = false
  let pausedByUs = false
  let hls = null
  let restartTimer = null
  let lastTime = -1
  let stalledMs = 0

  function teardown() {
    hls?.destroy()
    hls = null
    video.onerror = null
    video.removeAttribute('src')
    video.load()
  }

  function scheduleRestart() {
    if (stopped || restartTimer) return
    teardown()
    restartTimer = setTimeout(() => { restartTimer = null; start() }, RESTART_DELAY_MS)
  }

  async function start() {
    if (stopped) return
    lastTime = -1
    stalledMs = 0
    const { default: Hls } = await import('hls.js')
    if (stopped) return
    if (!Hls.isSupported()) {
      // No Media Source Extensions (iOS Safari): the browser's own HLS.
      if (!video.canPlayType('application/vnd.apple.mpegurl')) return
      video.src = url
      video.onerror = scheduleRestart
      if (!pausedByUs) playWithFallback(video, muted)
      return
    }
    hls = new Hls({ liveDurationInfinity: true, lowLatencyMode: false, capLevelToPlayerSize: true })
    hls.on(Hls.Events.ERROR, (_event, data) => {
      if (!data.fatal) return
      if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
        hls.recoverMediaError()
      } else {
        scheduleRestart()
      }
    })
    hls.on(Hls.Events.MANIFEST_PARSED, () => {
      for (let i = hls.levels.length - 1; i >= 0 && hls.levels.length > 1; i--) {
        const level = hls.levels[i]
        if (!level.videoCodec && !level.width) hls.removeLevel(i)
      }
      if (!pausedByUs) playWithFallback(video, muted)
    })
    hls.loadSource(url)
    hls.attachMedia(video)
  }

  // Playback that should be running but hasn't moved for STALL_LIMIT_MS
  // (frozen, or paused by the browser) starts over from the live edge.
  const watchdog = setInterval(() => {
    if (stopped || pausedByUs || restartTimer) {
      stalledMs = 0
      return
    }
    if (video.currentTime !== lastTime) {
      lastTime = video.currentTime
      stalledMs = 0
      return
    }
    stalledMs += WATCHDOG_INTERVAL_MS
    if (stalledMs >= STALL_LIMIT_MS) {
      teardown()
      start()
    }
  }, WATCHDOG_INTERVAL_MS)

  start()

  return {
    stop() {
      stopped = true
      clearInterval(watchdog)
      clearTimeout(restartTimer)
      teardown()
    },
    // After a pause, rejoin the live edge rather than resuming minutes behind.
    resume() {
      pausedByUs = false
      if (hls?.liveSyncPosition) video.currentTime = hls.liveSyncPosition
      playWithFallback(video, muted)
    },
    pause() {
      pausedByUs = true
      video.pause()
    },
  }
}
