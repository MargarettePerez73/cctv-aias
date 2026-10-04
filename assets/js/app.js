/*
 * Shared page behaviour. PHP renders every page; this file only adds what a
 * browser must do itself: alert polling and sounds, the panic button, relayed
 * camera frames, the video player and listing the webcams on this computer.
 */
(function () {
  "use strict"

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? ""
  const $ = (selector, root = document) => root.querySelector(selector)
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector))

  const escapeHtml = (value) =>
    String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c])

  async function api(action, { body, form, method } = {}) {
    const verb = method ?? (body !== undefined || form ? "POST" : "GET")
    const headers = { "X-CSRF-Token": csrf }
    if (body !== undefined) headers["Content-Type"] = "application/json"
    let response
    try {
      response = await fetch(`api/index.php?action=${encodeURIComponent(action)}`, {
        method: verb,
        credentials: "same-origin",
        headers,
        body: form ?? (body !== undefined ? JSON.stringify(body) : undefined),
      })
    } catch {
      throw Object.assign(new Error("Cannot reach the server"), { status: 0 })
    }
    let data = {}
    try {
      data = await response.json()
    } catch {
      // Non-JSON error page.
    }
    if (!response.ok || data.ok === false) {
      if (response.status === 401) window.location.href = "login.php"
      throw Object.assign(new Error(data.message || `Request failed (HTTP ${response.status})`), { status: response.status })
    }
    return data
  }

  function toast(message, tone = "success") {
    const container = $("[data-toasts]")
    if (!container) return
    const item = document.createElement("div")
    item.className = `toast toast-${tone}`
    item.setAttribute("role", "status")
    item.textContent = message
    container.append(item)
    while (container.children.length > 4) container.firstElementChild.remove()
    setTimeout(() => item.remove(), tone === "danger" ? 7000 : 3500)
  }

  // ---------------------------------------------------------------------------
  // Sound. Browsers block audio until the user interacts, so it starts lazily.
  // ---------------------------------------------------------------------------
  let audioContext = null
  function audio() {
    audioContext ??= new (window.AudioContext || window.webkitAudioContext)()
    if (audioContext.state === "suspended") audioContext.resume().catch(() => {})
    return audioContext
  }
  window.addEventListener("pointerdown", () => { try { audio() } catch {} }, { once: true })

  function chime() {
    try {
      const context = audio()
      ;[880, 660].forEach((frequency, index) => {
        const oscillator = context.createOscillator()
        const gain = context.createGain()
        const start = context.currentTime + index * 0.18
        oscillator.frequency.value = frequency
        gain.gain.setValueAtTime(0.15, start)
        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.16)
        oscillator.connect(gain).connect(context.destination)
        oscillator.start(start)
        oscillator.stop(start + 0.17)
      })
    } catch {}
  }

  let stopSiren = null
  function setSiren(on) {
    if (on && !stopSiren) {
      try {
        const context = audio()
        const oscillator = context.createOscillator()
        const lfo = context.createOscillator()
        const lfoGain = context.createGain()
        const gain = context.createGain()
        oscillator.type = "sawtooth"
        oscillator.frequency.value = 750
        lfo.frequency.value = 0.8
        lfoGain.gain.value = 300
        lfo.connect(lfoGain).connect(oscillator.frequency)
        gain.gain.value = 0.12
        oscillator.connect(gain).connect(context.destination)
        oscillator.start()
        lfo.start()
        stopSiren = () => { oscillator.stop(); lfo.stop(); stopSiren = null }
      } catch {}
    } else if (!on && stopSiren) {
      stopSiren()
    }
  }

  // ---------------------------------------------------------------------------
  // Sidebar, dropdowns, confirmations
  // ---------------------------------------------------------------------------
  $$("[data-sidebar-open]").forEach((button) => button.addEventListener("click", () => document.body.classList.add("sidebar-open")))
  $$("[data-sidebar-close]").forEach((button) => button.addEventListener("click", () => document.body.classList.remove("sidebar-open")))

  $$("[data-dropdown-toggle]").forEach((toggle) => {
    const panel = toggle.parentElement.querySelector(".dropdown-panel")
    toggle.addEventListener("click", (event) => {
      event.stopPropagation()
      const opening = panel.hidden
      $$(".dropdown-panel").forEach((other) => (other.hidden = true))
      panel.hidden = !opening
      if (opening && toggle.closest("[data-alerts]")) renderAlerts()
    })
  })
  document.addEventListener("click", (event) => {
    if (!event.target.closest(".dropdown")) $$(".dropdown-panel").forEach((panel) => (panel.hidden = true))
  })

  $$("form[data-confirm]").forEach((form) =>
    form.addEventListener("submit", (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault()
    }),
  )

  // ---------------------------------------------------------------------------
  // Alerts: poll every 8 seconds, notify on new ones
  // ---------------------------------------------------------------------------
  const eventIcons = { motion: "tone-amber", fire: "tone-red", panic: "tone-solid-red", access: "tone-blue", tamper: "tone-violet", other: "tone-slate" }
  const eventGlyphs = {
    motion: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    fire: '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
    panic: '<path d="M7 18v-6a5 5 0 1 1 10 0v6"/><path d="M5 21a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-1a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2z"/>',
    access: '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    tamper: '<path d="m2 2 20 20"/><path d="M6.7 6.7A10.7 10.7 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.3-1.6"/><path d="M10 5.2A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-2.2 3.2"/>',
    other: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
  }
  const glyph = (type) =>
    `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${eventGlyphs[type] ?? eventGlyphs.other}</svg>`
  const canReview = document.body.dataset.canReview === "1"
  let alerts = []
  let lastSeenId = null

  function timeAgo(value) {
    const date = new Date(String(value).replace(" ", "T"))
    const seconds = Math.round((Date.now() - date.getTime()) / 1000)
    if (seconds < 45) return "just now"
    if (seconds < 3600) return `${Math.round(seconds / 60)} min ago`
    if (seconds < 86400) return `${Math.round(seconds / 3600)} h ago`
    return date.toLocaleString()
  }

  function renderAlerts() {
    const list = $("[data-alerts-list]")
    if (!list) return
    const enable = $("[data-enable-notifications]")
    if (enable) enable.hidden = !("Notification" in window) || Notification.permission !== "default"
    if (!alerts.length) {
      list.innerHTML = `<p class="muted center pad">${
        document.body.dataset.role === "staff" ? "Alerts are sent to the owner, guard and administrators." : "No alerts yet."
      }</p>`
      return
    }
    list.innerHTML = alerts
      .map((alert) => {
        const tag = canReview ? "a" : "div"
        const href = canReview ? ` href="events.php?id=${alert.event_id}"` : ""
        return `<${tag} class="alert-item"${href}>
          <span class="icon-box ${eventIcons[alert.event_type] ?? "tone-slate"}" style="width:32px;height:32px">${glyph(alert.event_type)}</span>
          <span class="list-main"><strong>${escapeHtml(alert.title)}${alert.read_at ? "" : '<span class="unread-dot"></span>'}</strong>
          <small>${escapeHtml(alert.description || alert.location || "System")} · ${timeAgo(alert.created_at)}</small></span>
        </${tag}>`
      })
      .join("")
  }

  function setCount(selector, count) {
    $$(selector).forEach((element) => {
      element.hidden = count === 0
      element.textContent = count > 99 ? "99+" : String(count)
    })
  }

  async function pollAlerts() {
    try {
      const result = await api("alerts")
      alerts = result.data
      const newest = alerts[0]?.id ?? 0
      if (lastSeenId !== null) {
        const fresh = alerts.filter((alert) => alert.id > lastSeenId && !alert.read_at).reverse()
        fresh.forEach((alert) => {
          const urgent = alert.severity === "critical" || alert.severity === "high"
          toast(`${alert.title}${alert.location ? ` · ${alert.location}` : ""}`, urgent ? "danger" : "warning")
          if ("Notification" in window && Notification.permission === "granted") {
            new Notification(alert.title, { body: alert.description ?? "", tag: `cctv-alert-${alert.id}` })
          }
        })
        if (fresh.length) chime()
      }
      lastSeenId = Math.max(lastSeenId ?? 0, newest)
      setCount("[data-unread]", result.unread)
      $(".bell")?.classList.toggle("has-unread", result.unread > 0)
      setCount("[data-open-events]", result.open_events)
      const banner = $("[data-siren-banner]")
      if (banner) banner.hidden = !result.siren_active
      // Only the monitoring station (the PC in the store) sounds the siren.
      setSiren(result.siren_active && window.CCTV.stationActive)
      if (!$(".alerts-panel")?.hidden) renderAlerts()
    } catch {
      // Retry on the next tick.
    }
  }

  $("[data-mark-read]")?.addEventListener("click", async () => {
    await api("alerts-read", { body: {} }).catch(() => {})
    pollAlerts()
  })

  $("[data-enable-notifications]")?.addEventListener("click", async () => {
    await Notification.requestPermission()
    renderAlerts()
  })

  $("[data-siren-reset]")?.addEventListener("click", async () => {
    try {
      await api("siren-reset", { body: {} })
      toast("Siren silenced and logged.")
      pollAlerts()
    } catch (error) {
      toast(error.message, "danger")
    }
  })

  // ---------------------------------------------------------------------------
  // Panic button: tap twice within 4 seconds
  // ---------------------------------------------------------------------------
  $$("[data-panic]").forEach((button) => {
    let timer = null
    const label = button.querySelector("span")
    button.addEventListener("click", async () => {
      if (!button.classList.contains("armed")) {
        button.classList.add("armed")
        label.textContent = "Tap again to confirm"
        timer = setTimeout(() => {
          button.classList.remove("armed")
          label.textContent = "Panic alarm"
        }, 4000)
        return
      }
      clearTimeout(timer)
      button.classList.remove("armed")
      button.disabled = true
      label.textContent = "Sending…"
      try {
        await api("panic", { body: { location: button.dataset.location } })
        toast("Panic alarm sent. Owner, guard and administrators have been alerted.", "danger")
        pollAlerts()
      } catch (error) {
        toast(error.message, "danger")
      } finally {
        button.disabled = false
        label.textContent = "Panic alarm"
      }
    })
  })

  // ---------------------------------------------------------------------------
  // Camera tiles: relayed frames for viewers, clocks, full screen
  // ---------------------------------------------------------------------------
  function setTileLive(tile, live, label) {
    tile.classList.toggle("is-live", live)
    $("[data-status]", tile).textContent = label
  }

  function refreshRelay(tile) {
    if (tile.dataset.station === "1" || tile.dataset.relayEnabled !== "1") return
    const image = new Image()
    image.onload = () => {
      if (tile.dataset.station === "1") return
      const media = $(".cam-media", tile)
      let current = $("img", media)
      if (!current) {
        current = document.createElement("img")
        current.alt = ""
        media.replaceChildren(current)
      }
      current.src = image.src
      setTileLive(tile, true, "RELAY")
    }
    image.onerror = () => {
      if (tile.dataset.station === "1") return
      $(".cam-media", tile).replaceChildren()
      setTileLive(tile, false, "OFFLINE")
    }
    image.src = `api/index.php?action=frame&id=${tile.dataset.cameraId}&t=${Date.now()}`
  }

  const tiles = $$(".cam-tile")
  if (tiles.length) {
    const relayAll = () => document.visibilityState === "visible" && tiles.forEach(refreshRelay)
    relayAll()
    setInterval(relayAll, 3000)
    setInterval(() => {
      const now = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" })
      tiles.forEach((tile) => ($("[data-clock]", tile).textContent = tile.classList.contains("is-live") ? now : ""))
    }, 1000)
    tiles.forEach((tile) =>
      $("[data-fullscreen]", tile)?.addEventListener("click", () => {
        if (document.fullscreenElement) document.exitFullscreen()
        else tile.requestFullscreen?.()
      }),
    )
  }

  const grid = $("[data-cam-grid]")
  if (grid) {
    const apply = (columns) => {
      grid.className = `cam-grid cam-grid-${columns}`
      $$("[data-layout]").forEach((button) => button.classList.toggle("active", button.dataset.layout === columns))
      try { localStorage.setItem("cctv.layout", columns) } catch {}
    }
    let saved = "2"
    try { saved = localStorage.getItem("cctv.layout") || "2" } catch {}
    apply(saved)
    $$("[data-layout]").forEach((button) => button.addEventListener("click", () => apply(button.dataset.layout)))
  }

  // ---------------------------------------------------------------------------
  // Recording player (Recordings page)
  // ---------------------------------------------------------------------------
  const player = $("[data-player]")
  if (player) {
    const video = $("[data-player-video]", player)
    $$("[data-play]").forEach((button) =>
      button.addEventListener("click", () => {
        $("[data-player-title]", player).textContent = button.dataset.title
        $("[data-player-subtitle]", player).textContent = button.dataset.subtitle
        video.src = button.dataset.play
        player.showModal()
        video.play().catch(() => {})
      }),
    )
    const close = () => {
      video.pause()
      video.removeAttribute("src")
      video.load()
      player.close()
    }
    $("[data-player-close]", player).addEventListener("click", close)
    player.addEventListener("click", (event) => event.target === player && close())
    player.addEventListener("cancel", () => { video.pause(); video.removeAttribute("src") })
  }

  // ---------------------------------------------------------------------------
  // Settings: list the webcams on this computer for each camera slot
  // ---------------------------------------------------------------------------
  const deviceSelects = $$("[data-device-select]")
  async function listWebcams(askPermission) {
    if (!navigator.mediaDevices?.enumerateDevices) return
    let devices = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === "videoinput")
    if (askPermission && !devices.some((d) => d.label)) {
      try {
        const probe = await navigator.mediaDevices.getUserMedia({ video: true })
        probe.getTracks().forEach((track) => track.stop())
        devices = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === "videoinput")
      } catch {
        toast("Camera access was blocked. Allow it in the browser's address bar and try again.", "danger")
        return
      }
    }
    if (!devices.some((d) => d.label)) return
    const notice = $("[data-webcam-notice]")
    if (notice) notice.textContent = `${devices.length} webcam${devices.length === 1 ? "" : "s"} detected on this computer. Pick one for each camera slot and click Save.`
    deviceSelects.forEach((select) => {
      const form = select.closest("form")
      const idInput = $("[data-device-id]", form)
      const labelInput = $("[data-device-label]", form)
      const savedId = idInput.value
      const savedLabel = labelInput.value
      const match = devices.find((d) => d.deviceId === savedId) ?? devices.find((d) => savedLabel && d.label === savedLabel)
      select.innerHTML = '<option value="">Not assigned</option>'
      devices.forEach((device) => select.add(new Option(device.label, device.deviceId, false, device === match)))
      if (savedLabel && !match) select.add(new Option(`${savedLabel} (not on this computer)`, "__saved", false, true))
      select.onchange = () => {
        if (select.value === "__saved") {
          idInput.value = savedId
          labelInput.value = savedLabel
          return
        }
        const device = devices.find((d) => d.deviceId === select.value)
        idInput.value = device?.deviceId ?? ""
        labelInput.value = device?.label ?? ""
      }
    })
  }
  if (deviceSelects.length) {
    listWebcams(false)
    $("[data-detect-webcams]")?.addEventListener("click", () => listWebcams(true))
  }

  $$("[data-range]").forEach((range) => {
    const output = range.closest("label").querySelector("[data-range-output]")
    range.addEventListener("input", () => (output.textContent = range.value))
  })

  // ---------------------------------------------------------------------------
  // Door terminal: keep the reader's input focused, clear the result after a few seconds
  // ---------------------------------------------------------------------------
  const doorInput = $("[data-keep-focus]")
  if (doorInput) {
    setInterval(() => {
      if (document.activeElement?.tagName !== "SELECT") doorInput.focus()
    }, 1500)
    const panel = $("[data-reset-after]")
    if (panel) setTimeout(() => window.location.replace(window.location.href), Number(panel.dataset.resetAfter) * 1000)
  }

  window.CCTV = { api, toast, stationActive: false, pollAlerts }
  pollAlerts()
  setInterval(pollAlerts, 8000)
})()
