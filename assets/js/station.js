/*
 * Monitoring station (Live cameras page only).
 *
 * The browser on the PC with the USB / laptop webcams attached plays the role
 * of the video processing unit: it keeps every assigned camera open, analyses
 * frames for motion, possible fire and tampering, records continuous segments
 * and event clips, relays low-rate frames for other devices and uploads it all
 * to PHP. Only one browser should be the station, and its tab must stay open.
 */
(function () {
  "use strict"

  const { api, toast } = window.CCTV
  const $ = (selector, root = document) => root.querySelector(selector)

  const STORAGE_KEY = "cctv.monitoringStation"
  const ANALYSIS_MS = 500
  const RELAY_MS = 3000
  const HEARTBEAT_MS = 30000
  const CONFIG_MS = 20000
  const WARMUP_MS = 4000
  const DARK_TAMPER_MS = 10000
  const PERSON_MS = 1000
  const PERSON_MIN_SCORE = 0.55

  // Pretrained COCO-SSD object detector: recognises people out of the box, no training or labeled video needed.
  const DETECTOR_SCRIPTS = [
    ["tf", "https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.22.0/dist/tf.min.js"],
    ["cocoSsd", "https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.3/dist/coco-ssd.min.js"],
  ]

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const script = Object.assign(document.createElement("script"), { src, onload: resolve, onerror: () => reject(new Error(`Could not load ${src}`)) })
      document.head.append(script)
    })
  }

  function preferredMimeType() {
    const candidates = ["video/webm;codecs=vp9", "video/webm;codecs=vp8", "video/webm", "video/mp4"]
    return candidates.find((type) => window.MediaRecorder && MediaRecorder.isTypeSupported(type)) ?? ""
  }

  function withinHours(open, close, now = new Date()) {
    const minutes = now.getHours() * 60 + now.getMinutes()
    const [oh, om] = open.split(":").map(Number)
    const [ch, cm] = close.split(":").map(Number)
    const start = oh * 60 + om
    const end = ch * 60 + cm
    return start <= end ? minutes >= start && minutes < end : minutes >= start || minutes < end
  }

  /** % of changed pixels needed: sensitivity 1 → 20%, 5 → ~4.8%, 10 → ~0.8%. */
  const motionThreshold = (sensitivity) => 20 * Math.pow(0.7, sensitivity - 1)

  /** Records a stream until stop() or maxMs, then hands back the clip (or null if nothing was captured). */
  class ClipRecorder {
    constructor(stream, bitrate, maxMs, onDone) {
      const mimeType = preferredMimeType()
      this.chunks = []
      this.startedAt = Date.now()
      this.recorder = new MediaRecorder(stream, { ...(mimeType ? { mimeType } : {}), videoBitsPerSecond: bitrate })
      this.recorder.ondataavailable = (event) => event.data.size && this.chunks.push(event.data)
      this.recorder.onstop = () => {
        clearTimeout(this.timer)
        const seconds = Math.max(1, Math.round((Date.now() - this.startedAt) / 1000))
        const type = (this.recorder.mimeType || mimeType || "video/webm").split(";")[0]
        onDone(this.chunks.length ? new Blob(this.chunks, { type }) : null, seconds)
      }
      this.recorder.start(1000)
      this.timer = setTimeout(() => this.stop(), maxMs)
    }

    stop() {
      if (this.recorder.state !== "inactive") this.recorder.stop()
    }
  }

  class CameraRunner {
    constructor(station, camera, deviceId) {
      this.station = station
      this.camera = camera
      this.deviceId = deviceId
      this.tile = $(`.cam-tile[data-camera-id="${camera.id}"]`)
      this.stream = null
      this.video = document.createElement("video")
      this.video.muted = true
      this.video.playsInline = true
      this.video.autoplay = true
      this.analysisCanvas = Object.assign(document.createElement("canvas"), { width: 64, height: 48 })
      this.frameCanvas = Object.assign(document.createElement("canvas"), { width: 640, height: 360 })
      this.previous = null
      this.timers = []
      this.lastRaised = {}
      this.motionStreak = 0
      this.fireStreak = 0
      this.personStreak = 0
      this.personBusy = false
      this.darkSince = 0
      this.segment = null
      this.manual = null
      this.eventClip = null
      this.relayBusy = false
      this.stopped = false
      this.state = { status: "starting", motion: 0, armed: false }
      $("[data-record]", this.tile)?.addEventListener("click", (this.onRecordClick = () => this.toggleManual()))
    }

    // --- Tile rendering -----------------------------------------------------

    render() {
      const tile = this.tile
      if (!tile) return
      const live = this.state.status === "live"
      tile.classList.toggle("is-live", live)
      $("[data-status]", tile).textContent = live ? "LIVE" : "OFFLINE"
      const recording = Boolean(this.segment || this.manual || this.eventClip)
      const rec = $("[data-rec]", tile)
      rec.hidden = !recording || !live
      rec.textContent = this.eventClip ? "REC · EVENT" : "REC"
      $("[data-armed]", tile).hidden = !(live && this.state.armed)
      $("[data-motion]", tile).hidden = !live
      const bar = $("[data-motion-bar]", tile)
      bar.style.width = `${Math.min(100, this.state.motion * 5)}%`
      bar.classList.toggle("high", this.state.motion > 5)
      $("[data-motion-value]", tile).textContent = `${this.state.motion.toFixed(1)}%`
      const record = $("[data-record]", tile)
      record.hidden = !live
      record.classList.toggle("recording", Boolean(this.manual))
      record.title = this.manual ? "Stop & save clip" : "Record clip"
      if (!live) {
        const messages = {
          starting: ["Starting camera…", ""],
          error: [this.state.error || "Camera error", "Retrying automatically…"],
        }
        const [title, detail] = messages[this.state.status] ?? messages.starting
        $("[data-placeholder-title]", tile).textContent = title
        $("[data-placeholder-detail]", tile).textContent = detail
      }
      this.station.renderSummary()
    }

    patch(state) {
      Object.assign(this.state, state)
      this.render()
    }

    // --- Lifecycle ------------------------------------------------------------

    async start() {
      this.patch({ status: "starting", error: "" })
      try {
        this.stream = await navigator.mediaDevices.getUserMedia({
          video: { deviceId: { exact: this.deviceId }, width: { ideal: 1280 }, height: { ideal: 720 } },
          audio: false,
        })
      } catch (error) {
        const busy = error && error.name === "NotReadableError"
        this.patch({ status: "error", error: busy ? "Camera is in use by another app" : "Could not open this camera" })
        this.scheduleRetry()
        return
      }
      if (this.stopped) return this.releaseStream()
      this.stream.getVideoTracks()[0]?.addEventListener("ended", () => this.onDisconnected())
      this.video.srcObject = this.stream
      $(".cam-media", this.tile)?.replaceChildren(this.video)
      await this.video.play().catch(() => {})
      this.startedAt = Date.now()
      this.previous = null
      this.patch({ status: "live" })
      this.timers.push(setInterval(() => this.analyse(), ANALYSIS_MS))
      this.timers.push(setInterval(() => this.detectPeople(), PERSON_MS))
      this.timers.push(setInterval(() => this.relayFrame(), RELAY_MS))
      this.syncContinuous()
      this.station.sendHeartbeat()
    }

    scheduleRetry() {
      if (!this.stopped) this.timers.push(setTimeout(() => this.restart(), 10000))
    }

    async restart() {
      this.clearTimers()
      this.releaseStream()
      if (!this.stopped) await this.start()
    }

    onDisconnected() {
      if (this.stopped) return
      this.patch({ status: "error", error: "Camera disconnected" })
      this.raise("tamper", "feed lost")
      this.segment?.stop()
      this.manual?.stop()
      this.eventClip?.stop()
      this.releaseStream()
      this.clearTimers()
      this.scheduleRetry()
    }

    clearTimers() {
      this.timers.forEach((timer) => { clearInterval(timer); clearTimeout(timer) })
      this.timers = []
    }

    releaseStream() {
      this.stream?.getTracks().forEach((track) => track.stop())
      this.stream = null
      this.video.srcObject = null
      this.video.remove()
    }

    stop() {
      this.stopped = true
      this.clearTimers()
      this.segment?.stop()
      this.manual?.stop()
      this.eventClip?.stop()
      this.segment = this.manual = this.eventClip = null
      this.releaseStream()
      $("[data-record]", this.tile)?.removeEventListener("click", this.onRecordClick)
    }

    update(camera) {
      this.camera = camera
      this.syncContinuous()
    }

    get isRecording() {
      return Boolean(this.segment || this.manual || this.eventClip)
    }

    // --- Analysis ---------------------------------------------------------------

    isArmed() {
      const settings = this.station.settings
      if (!settings || !Number(this.camera.motion_detection) || settings.motion_mode === "off") return false
      if (settings.motion_mode === "always") return true
      return !withinHours(settings.business_open, settings.business_close)
    }

    analyse() {
      if (this.video.readyState < 2) return
      const context = this.analysisCanvas.getContext("2d", { willReadFrequently: true })
      context.drawImage(this.video, 0, 0, 64, 48)
      const { data } = context.getImageData(0, 0, 64, 48)
      const pixels = data.length / 4
      const gray = new Uint8Array(pixels)
      let changed = 0
      let brightness = 0
      let firePixels = 0
      for (let i = 0; i < pixels; i++) {
        const r = data[i * 4]
        const g = data[i * 4 + 1]
        const b = data[i * 4 + 2]
        const value = (r * 77 + g * 150 + b * 29) >> 8
        gray[i] = value
        brightness += value
        if (this.previous && Math.abs(value - this.previous[i]) > 25) changed++
        if (r > 180 && g > 60 && g < 210 && b < 120 && r - g > 40 && g - b > 20) firePixels++
      }
      this.previous = gray
      const motion = (changed / pixels) * 100
      const armed = this.isArmed()
      this.patch({ motion: Math.round(motion * 10) / 10, armed })

      const settings = this.station.settings
      const now = Date.now()
      if (!settings || now - this.startedAt < WARMUP_MS) return

      this.motionStreak = armed && motion >= motionThreshold(settings.motion_sensitivity) ? this.motionStreak + 1 : 0
      if (this.motionStreak >= 2) {
        this.motionStreak = 0
        this.raise("motion", `change ${motion.toFixed(1)}%`)
      }

      const fireRatio = (firePixels / pixels) * 100
      this.fireStreak = settings.fire_detection_enabled && fireRatio > 2 ? this.fireStreak + 1 : 0
      if (this.fireStreak >= 6) {
        this.fireStreak = 0
        this.raise("fire", `flame colours ${fireRatio.toFixed(1)}%`)
      }

      if (brightness / pixels < 10) {
        this.darkSince ||= now
        if (now - this.darkSince > DARK_TAMPER_MS) {
          this.darkSince = now
          this.raise("tamper", "lens covered or no light")
        }
      } else {
        this.darkSince = 0
      }
    }

    /** Runs while armed (same schedule as motion alerts); two detections in a row raise the event. */
    async detectPeople() {
      const settings = this.station.settings
      if (this.personBusy || !settings?.person_detection_enabled || this.video.readyState < 2) return
      if (!this.state.armed || Date.now() - this.startedAt < WARMUP_MS) {
        this.personStreak = 0
        return
      }
      this.personBusy = true
      try {
        const model = await this.station.personDetector()
        if (!model || this.stopped) return
        const people = (await model.detect(this.video, 10, PERSON_MIN_SCORE)).filter((found) => found.class === "person")
        this.personStreak = people.length ? this.personStreak + 1 : 0
        if (this.personStreak >= 2) {
          this.personStreak = 0
          const confidence = Math.round(Math.max(...people.map((found) => found.score)) * 100)
          const count = `${people.length} ${people.length === 1 ? "person" : "people"}`
          this.raise("person", `${count} · ${confidence}% confidence`, people.map((found) => found.bbox))
        }
      } catch {
        // Skip this frame; the next tick tries again.
      } finally {
        this.personBusy = false
      }
    }

    /**
     * JPEG of the current frame with the camera name and time burned in, like a DVR overlay.
     * Boxes are [x, y, width, height] in video pixels and are outlined on the snapshot.
     */
    captureFrame(quality, boxes = []) {
      if (this.video.readyState < 2) return Promise.resolve(null)
      const context = this.frameCanvas.getContext("2d")
      context.drawImage(this.video, 0, 0, 640, 360)
      const scaleX = 640 / (this.video.videoWidth || 640)
      const scaleY = 360 / (this.video.videoHeight || 360)
      context.strokeStyle = "#ef4444"
      context.lineWidth = 3
      boxes.forEach(([x, y, width, height]) => context.strokeRect(x * scaleX, y * scaleY, width * scaleX, height * scaleY))
      context.fillStyle = "rgba(0,0,0,.55)"
      context.fillRect(0, 334, 640, 26)
      context.fillStyle = "#fff"
      context.font = "600 14px monospace"
      context.fillText(`${this.camera.name} · ${this.camera.location}`, 10, 352)
      const stamp = new Date().toLocaleString()
      context.fillText(stamp, 630 - context.measureText(stamp).width, 352)
      return new Promise((resolve) => this.frameCanvas.toBlob(resolve, "image/jpeg", quality))
    }

    async relayFrame() {
      if (this.relayBusy) return
      this.relayBusy = true
      try {
        const blob = await this.captureFrame(0.6)
        if (!blob) return
        const form = new FormData()
        form.append("camera_id", this.camera.id)
        form.append("frame", blob, "frame.jpg")
        await api("frame-upload", { form })
      } catch {
        // Best effort; the next tick retries.
      } finally {
        this.relayBusy = false
      }
    }

    async raise(type, score, boxes = []) {
      const settings = this.station.settings
      const cooldown = (settings?.event_cooldown_seconds ?? 60) * 1000
      const now = Date.now()
      if (now - (this.lastRaised[type] ?? 0) < cooldown) return
      this.lastRaised[type] = now

      const form = new FormData()
      form.append("camera_id", this.camera.id)
      form.append("event_type", type)
      form.append("score", score)
      const snapshot = await this.captureFrame(0.85, boxes)
      if (snapshot) form.append("snapshot", snapshot, "snapshot.jpg")
      try {
        const result = await api("event-create", { form })
        if (result.suppressed) return
        const label = { motion: "Motion", fire: "Possible fire", tamper: "Camera tampering", person: "Person" }[type]
        toast(`${label} detected · ${this.camera.location}`, type === "motion" ? "warning" : "danger")
        const clipSeconds = settings?.event_clip_seconds ?? 0
        if (clipSeconds > 0 && type !== "tamper" && this.stream && !this.eventClip) {
          this.eventClip = new ClipRecorder(this.stream, 1_000_000, clipSeconds * 1000, (blob, seconds) => {
            this.eventClip = null
            this.render()
            if (blob) this.station.uploadRecording(this.camera.id, blob, seconds, result.event_id)
          })
          this.render()
        }
      } catch (error) {
        toast(`Could not log ${type} event: ${error.message}`, "danger")
      }
    }

    // --- Recording ----------------------------------------------------------------

    syncContinuous() {
      const shouldRecord = Boolean(Number(this.camera.continuous_recording) && this.stream)
      if (shouldRecord && !this.segment) this.startSegment()
      if (!shouldRecord && this.segment) {
        const segment = this.segment
        this.segment = null
        segment.stop()
      }
      this.render()
    }

    /** Continuous recording is split into segments so each upload fits PHP's upload limit. */
    startSegment() {
      const seconds = (this.station.settings?.segment_minutes ?? 2) * 60
      try {
        const segment = new ClipRecorder(this.stream, this.station.bitrateFor(seconds), seconds * 1000, (blob, duration) => {
          if (blob) this.station.uploadRecording(this.camera.id, blob, duration)
          if (this.segment === segment) {
            this.segment = null
            if (!this.stopped) this.syncContinuous()
          }
        })
        this.segment = segment
      } catch {
        toast(`Continuous recording could not start on ${this.camera.name}`, "danger")
      }
    }

    toggleManual() {
      if (this.manual) return this.manual.stop()
      if (!this.stream) return
      const maxSeconds = Math.min(600, Math.floor((this.station.uploadLimit * 8 * 0.8) / 1_000_000))
      try {
        this.manual = new ClipRecorder(this.stream, 1_000_000, maxSeconds * 1000, (blob, seconds) => {
          this.manual = null
          this.render()
          if (!blob) return
          if (seconds >= maxSeconds) toast("Manual clip reached its maximum length and was saved.", "info")
          this.station.uploadRecording(this.camera.id, blob, seconds)
        })
        this.render()
      } catch {
        toast("This browser cannot record from the camera.", "danger")
      }
    }
  }

  class Station {
    constructor() {
      this.enabled = false
      try { this.enabled = localStorage.getItem(STORAGE_KEY) === "1" } catch {}
      this.cameras = []
      this.settings = null
      this.uploadLimit = 40 * 1024 * 1024
      this.devices = []
      this.runners = new Map()
      this.queue = Promise.resolve()
      this.pendingUploads = 0
      this.timers = []
      this.tiles = Array.from(document.querySelectorAll(".cam-tile"))
      this.placeholders = new Map(this.tiles.map((tile) => [tile, $(".cam-placeholder", tile).innerHTML]))

      $("[data-station-toggle]")?.addEventListener("click", () => this.setEnabled(!this.enabled))
      window.addEventListener("beforeunload", (event) => {
        const busy = this.pendingUploads > 0 || [...this.runners.values()].some((runner) => runner.isRecording)
        if (busy) {
          event.preventDefault()
          event.returnValue = ""
        }
      })
      navigator.mediaDevices?.addEventListener?.("devicechange", () => this.enabled && this.refreshDevices().then(() => this.sync()))
      this.renderPanel()
      if (this.enabled) this.start()
    }

    async setEnabled(enabled) {
      this.enabled = enabled
      try { localStorage.setItem(STORAGE_KEY, enabled ? "1" : "0") } catch {}
      if (enabled) await this.start()
      else this.stop()
    }

    async start() {
      window.CCTV.stationActive = true
      this.renderPanel()
      const ok = await this.refreshDevices(true)
      if (!ok) return
      await this.loadConfig()
      this.timers.push(setInterval(() => this.loadConfig(), CONFIG_MS))
      this.timers.push(setInterval(() => this.sendHeartbeat(), HEARTBEAT_MS))
      window.CCTV.pollAlerts()
    }

    stop() {
      window.CCTV.stationActive = false
      window.CCTV.pollAlerts()
      this.timers.forEach(clearInterval)
      this.timers = []
      this.runners.forEach((runner) => runner.stop())
      this.runners.clear()
      this.tiles.forEach((tile) => {
        delete tile.dataset.station
        tile.classList.remove("is-live")
        $(".cam-media", tile).replaceChildren()
        $(".cam-placeholder", tile).innerHTML = this.placeholders.get(tile)
        ;["[data-rec]", "[data-armed]", "[data-motion]", "[data-record]"].forEach((selector) => ($(selector, tile).hidden = true))
        $("[data-status]", tile).textContent = "OFFLINE"
      })
      this.renderPanel()
    }

    async loadConfig() {
      try {
        const config = await api("station-config")
        this.cameras = config.cameras
        this.settings = config.settings
        this.uploadLimit = config.upload_limit_bytes
        this.sync()
      } catch {
        // Keep the last known configuration.
      }
    }

    /** Browsers only reveal camera names after permission is granted, so ask once if needed. */
    async refreshDevices(askPermission = false) {
      const error = $("[data-station-error]")
      if (!navigator.mediaDevices?.enumerateDevices) {
        error.hidden = false
        error.textContent = "This browser cannot access cameras. Open the site via http://localhost or HTTPS."
        return false
      }
      let devices = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === "videoinput")
      if (askPermission && !devices.some((d) => d.label)) {
        try {
          const probe = await navigator.mediaDevices.getUserMedia({ video: true, audio: false })
          probe.getTracks().forEach((track) => track.stop())
          devices = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === "videoinput")
        } catch {
          error.hidden = false
          error.textContent = "Camera permission was blocked. Allow camera access for this site in the address bar, then try again."
          this.enabled = false
          this.stop()
          return false
        }
      }
      error.hidden = true
      this.devices = devices.map((device, index) => ({ deviceId: device.deviceId, label: device.label || `Camera ${index + 1}` }))
      this.renderPanel()
      return true
    }

    /** Resolves the saved device id, falling back to the label because browsers can rotate device ids. */
    resolveDevice(camera) {
      return (
        this.devices.find((device) => device.deviceId === camera.device_id) ??
        this.devices.find((device) => camera.device_label && device.label === camera.device_label)
      )
    }

    sync() {
      if (!this.enabled) return
      const wanted = new Map()
      for (const camera of this.cameras) {
        const tile = $(`.cam-tile[data-camera-id="${camera.id}"]`)
        if (!tile) continue
        tile.dataset.station = "1"
        const device = Number(camera.is_enabled) && (camera.device_id || camera.device_label) ? this.resolveDevice(camera) : null
        if (device) {
          wanted.set(camera.id, { camera, device })
          continue
        }
        tile.classList.remove("is-live")
        $(".cam-media", tile).replaceChildren()
        $("[data-status]", tile).textContent = "OFFLINE"
        const [title, detail] = !Number(camera.is_enabled)
          ? ["Camera disabled", "Enable it in System settings"]
          : !(camera.device_id || camera.device_label)
            ? ["No webcam assigned", "Assign a USB or laptop camera in System settings"]
            : ["Webcam not connected", camera.device_label || "Plug the USB camera back in"]
        $("[data-placeholder-title]", tile).textContent = title
        $("[data-placeholder-detail]", tile).textContent = detail
      }
      for (const [id, runner] of this.runners) {
        const target = wanted.get(id)
        if (!target || target.device.deviceId !== runner.deviceId) {
          runner.stop()
          this.runners.delete(id)
        } else {
          runner.update(target.camera)
        }
      }
      for (const [id, { camera, device }] of wanted) {
        if (this.runners.has(id)) continue
        const runner = new CameraRunner(this, camera, device.deviceId)
        this.runners.set(id, runner)
        runner.start()
      }
      this.renderSummary()
    }

    /** One model shared by all cameras, loaded on first use. A failed load is retried after a minute. */
    personDetector() {
      this.detector ??= (async () => {
        try {
          for (const [global, src] of DETECTOR_SCRIPTS) if (!window[global]) await loadScript(src)
          const model = await window.cocoSsd.load({ base: "lite_mobilenet_v2" })
          toast("Person detection is ready.", "info")
          return model
        } catch {
          toast("Person detection could not load. The station needs internet the first time. Motion and fire detection still work.", "danger")
          setTimeout(() => (this.detector = null), 60000)
          return null
        }
      })()
      return this.detector
    }

    sendHeartbeat() {
      const ids = [...this.runners.values()].filter((runner) => runner.state.status === "live").map((runner) => runner.camera.id)
      if (ids.length) api("heartbeat", { body: { camera_ids: ids } }).catch(() => {})
    }

    /** Bitrate that keeps a clip of `seconds` under ~80% of PHP's upload limit, capped at 1.5 Mbps. */
    bitrateFor(seconds) {
      return Math.max(250_000, Math.min(1_500_000, Math.floor((this.uploadLimit * 8 * 0.8) / seconds)))
    }

    /** Uploads one at a time with retries so a busy server is not flooded. */
    uploadRecording(cameraId, blob, seconds, eventId) {
      this.pendingUploads++
      this.renderSummary()
      this.queue = this.queue
        .then(async () => {
          const extension = blob.type.includes("mp4") ? "mp4" : "webm"
          for (let attempt = 1; attempt <= 3; attempt++) {
            const form = new FormData()
            form.append("camera_id", cameraId)
            form.append("duration_seconds", seconds)
            if (eventId) form.append("event_id", eventId)
            form.append("recording", blob, `camera-${cameraId}-${Date.now()}.${extension}`)
            try {
              await api("upload-recording", { form })
              return
            } catch (error) {
              const retryable = !error.status || error.status >= 500
              if (!retryable || attempt === 3) {
                toast(`Recording upload failed: ${error.message}`, "danger")
                return
              }
              await sleep(2000 * attempt)
            }
          }
        })
        .finally(() => {
          this.pendingUploads--
          this.renderSummary()
        })
    }

    renderPanel() {
      const panel = $("[data-station-panel]")
      if (!panel) return
      panel.classList.toggle("on", this.enabled)
      $("[data-station-heading]").textContent = this.enabled ? "This computer is the monitoring station" : "This device is a viewer"
      $("[data-station-description]").textContent = this.enabled
        ? `${this.devices.length} webcam${this.devices.length === 1 ? "" : "s"} detected. This tab records, runs motion detection and relays video to other devices. Keep it open.`
        : "Feeds are relayed from the monitoring station every few seconds. If the USB / laptop webcams are plugged into this computer, make it the station."
      const toggle = $("[data-station-toggle]")
      toggle.className = `btn ${this.enabled ? "" : "btn-primary"}`
      $("span", toggle).textContent = this.enabled ? "Stop station on this computer" : "Use this computer as station"
      $("[data-station-hint]").hidden = !this.enabled
      this.renderSummary()
    }

    renderSummary() {
      const card = $("[data-station-card]")
      if (!card) return
      const runners = [...this.runners.values()]
      const live = runners.filter((runner) => runner.state.status === "live").length
      const recording = runners.filter((runner) => runner.isRecording).length
      card.classList.toggle("on", this.enabled)
      $("[data-station-title]", card).textContent = this.enabled ? "Monitoring station" : "Viewer device"
      $("[data-station-text]", card).textContent = this.enabled
        ? `${live} camera${live === 1 ? "" : "s"} live${recording ? ` · ${recording} recording` : ""}${this.pendingUploads ? ` · uploading ${this.pendingUploads}` : ""}`
        : "Station runs on the Live cameras page"
    }
  }

  new Station()
})()
