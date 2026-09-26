<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import AppIcon from './components/AppIcon.vue'
import IntersectionMap from './components/IntersectionMap.vue'
import CctvMonitoring from './components/CctvMonitoring.vue'
import {
  fetchBackendHealth,
  fetchAiHealth,
  fetchCompleteIntersectionData,
  evaluateHeuristic,
  fetchLatestHeuristicDecision,
  triggerEvp,
  cancelEvp,
  completeEvp,
  fetchEvpStatus,
  fetchEvpLogs,
} from './services/api.js'

const menus = [
  { label: 'Dashboard', icon: 'grid', target: 'dashboard' },
  { label: 'Live Monitoring', icon: 'camera', target: 'live-monitoring', keywords: 'cctv kamera' },
  { label: 'Peta Simpang', icon: 'map', target: 'peta-simpang' },
  { label: 'Rekomendasi AI', icon: 'spark', target: 'rekomendasi-ai' },
  { label: 'Kontrol Fase', icon: 'traffic' },
  { label: 'Emergency Vehicle Priority', icon: 'shield' },
  { label: 'Analitik Historis', icon: 'chart' },
  { label: 'Riwayat Keputusan', icon: 'history' },
  { label: 'Kesehatan Perangkat', icon: 'pulse' },
  { label: 'Pengaturan Simpang', icon: 'settings' },
]
const directions = ['Barat', 'Utara', 'Timur', 'Selatan']
const search = ref('')
const searchOpen = ref(false)
const searchResults = computed(() => menus.filter(menu => `${menu.label} ${menu.keywords || ''}`.toLowerCase().includes(search.value.trim().toLowerCase())))
const sidebarCompact = ref(false)
const isMobile = ref(false)
const mobileMenuOpen = ref(false)
const openPopover = ref('')
const placeholder = ref(null)
const placeholderDialog = ref(null)
const toast = ref('')
const refreshing = ref(false)
const lastChecked = ref('')

// State integrasi backend, AI & Heuristik DSS (Tahap 2 & Tahap 5)
const backendStatus = ref('Standby')
const aiStatus = ref('Standby')
const dbConnected = ref(false)
const intersection = ref(null)
const systemStatus = ref(null)
const cameras = ref([])
const signalPhases = ref([])
const approaches = ref([])
const latestDecision = ref(null)
const evaluatingHeuristic = ref(false)
const selectedScenario = ref('default')

// State Emergency Vehicle Priority (EVP - Tahap 7)
const activeEvp = ref(null)
const evpLogs = ref([])
const evpDialog = ref(null)
const evpLoading = ref(false)
const evpForm = reactive({
  vehicleType: 'ambulance',
  direction: 'WEST',
})

const serviceStatus = computed(() => backendStatus.value === 'Terhubung' && aiStatus.value === 'Terhubung' ? 'Terhubung' : 'Standby')

const activeSignalPhase = computed(() => {
  if (signalPhases.value && signalPhases.value.length > 0) {
    return signalPhases.value.find(p => p.is_active) || signalPhases.value[0]
  }
  return { name: 'Barat–Timur', duration: { default_seconds: 30 } }
})

let toastTimer
let abortController
let mobileQuery

function formatMode(mode) {
  if (!mode) return 'ATCS Normal'
  if (mode === 'ATCS_NORMAL') return 'ATCS Normal'
  if (mode === 'SIGAP_ADAPTIVE') return 'SIGAP Adaptive'
  if (mode === 'FALLBACK') return 'Fallback Aman'
  return mode.replaceAll('_', ' ')
}

function syncMobileLayout() {
  isMobile.value = mobileQuery.matches
  mobileMenuOpen.value = false
}

function notify(message) {
  toast.value = message
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.value = '' }, 5500)
}

async function loadData(manual = false) {
  if (refreshing.value) return
  refreshing.value = true
  abortController = new AbortController()
  const signal = abortController.signal

  try {
    // 1. Periksa endpoint kesehatan backend & AI secara paralel
    const [backendHealthRes, aiHealthRes] = await Promise.allSettled([
      fetchBackendHealth(signal),
      fetchAiHealth(signal),
    ])

    if (signal.aborted) return

    const isBackendOk = backendHealthRes.status === 'fulfilled' && (
      backendHealthRes.value?.status === 'ok' ||
      backendHealthRes.value?.database?.connected
    )
    const isAiOk = aiHealthRes.status === 'fulfilled' && aiHealthRes.value?.status === 'healthy'

    backendStatus.value = isBackendOk ? 'Terhubung' : 'Standby'
    aiStatus.value = isAiOk ? 'Terhubung' : 'Standby'
    dbConnected.value = isBackendOk && Boolean(backendHealthRes.value?.database?.connected)

    // 2. Jika backend terhubung, ambil data simpang, kamera, dan fase dari REST API
    if (isBackendOk) {
      try {
        const fullData = await fetchCompleteIntersectionData('BDG-IBR-ADJ-01', signal)
        if (fullData) {
          intersection.value = fullData.intersection
          systemStatus.value = fullData.systemStatus
          cameras.value = fullData.cameras || []
          signalPhases.value = fullData.signalPhases || []
          approaches.value = fullData.approaches || []
          latestDecision.value = fullData.heuristicDecision || null
        }
        await Promise.allSettled([
          fetchEvpState(),
          loadEvpLogs(),
        ])
      } catch (fetchErr) {
        console.warn('Gagal memuat detail simpang dari API:', fetchErr)
      }
    }

    lastChecked.value = new Intl.DateTimeFormat('id-ID', {
      hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Asia/Jakarta'
    }).format(new Date()).replaceAll('.', ':')

    if (manual) {
      if (isBackendOk) {
        notify(`Data backend berhasil disinkronkan (${lastChecked.value} WIB). Database: ${dbConnected.value ? 'Terhubung' : 'Degraded'}.`)
      } else {
        notify(`API backend dalam status Standby (${lastChecked.value} WIB). Menggunakan konfigurasi cadangan.`)
      }
    }
  } catch (err) {
    backendStatus.value = 'Standby'
    aiStatus.value = 'Standby'
    if (manual) notify(`Koneksi terputus: ${err.message || 'Gagal menghubungi server'}`)
  } finally {
    refreshing.value = false
  }
}

async function runHeuristicEvaluation(scenario = 'default') {
  if (evaluatingHeuristic.value || !intersection.value?.id) return
  evaluatingHeuristic.value = true
  try {
    const result = await evaluateHeuristic(intersection.value.id, scenario)
    if (result) {
      latestDecision.value = result
      const winner = result.signal_phase?.name || result.payload?.winning_phase_name || 'Fase Terpilih'
      notify(`Evaluasi DSS: Rekomendasi ${winner} (${result.proposed_duration_seconds} dtk, Skor: ${result.priority_score}).`)
    }
  } catch (err) {
    notify(`Gagal menjalankan evaluasi heuristik: ${err.message || 'Terjadi kesalahan'}`)
  } finally {
    evaluatingHeuristic.value = false
  }
}

async function fetchEvpState() {
  const intersectionId = intersection.value?.id || 1
  try {
    const res = await fetchEvpStatus(intersectionId)
    if (res && res.is_active) {
      activeEvp.value = res
    } else {
      activeEvp.value = null
    }
  } catch (err) {
    console.warn('Gagal memuat status EVP:', err)
  }
}

async function loadEvpLogs() {
  const intersectionId = intersection.value?.id || 1
  try {
    const logs = await fetchEvpLogs(intersectionId)
    evpLogs.value = logs || []
  } catch (err) {
    console.warn('Gagal memuat log EVP:', err)
  }
}

async function handleEvpTriggered({ vehicleType = 'ambulance', direction = 'WEST' } = {}) {
  const intersectionId = intersection.value?.id || 1
  evpLoading.value = true
  try {
    const res = await triggerEvp(intersectionId, { vehicleType, direction })
    if (res) {
      activeEvp.value = res
      notify(`🚨 PRIORITAS EVP: Terdeteksi ${res.vehicle_label} dari Arah ${res.direction_label}! Koridor ${res.target_phase_name} dibuka aman.`)
      try {
        const latest = await fetchLatestHeuristicDecision(intersectionId)
        if (latest) latestDecision.value = latest
      } catch (_) {}
      await loadEvpLogs()
    }
  } catch (err) {
    notify(`Gagal mengaktifkan EVP: ${err.message || 'Terjadi kesalahan sistem'}`)
  } finally {
    evpLoading.value = false
  }
}

async function handleEvpCancelled() {
  const intersectionId = intersection.value?.id || 1
  evpLoading.value = true
  try {
    await cancelEvp(intersectionId)
    activeEvp.value = null
    notify('Pembatalan Aman EVP: Menjalankan fase transisi kuning (3s) dan all-red (2s) sebelum kembali ke siklus normal.')
    await loadEvpLogs()
  } catch (err) {
    notify(`Gagal membatalkan EVP: ${err.message || 'Terjadi kesalahan'}`)
  } finally {
    evpLoading.value = false
  }
}

async function handleEvpCompleted() {
  const intersectionId = intersection.value?.id || 1
  try {
    await completeEvp(intersectionId)
    activeEvp.value = null
    notify('EVP Selesai: Kendaraan darurat telah melintasi simpang. Memulihkan siklus ATCS normal.')
    await loadEvpLogs()
  } catch (err) {
    console.warn('Gagal mencatat pemulihan EVP:', err)
  }
}

async function openEvpModal() {
  await Promise.allSettled([
    fetchEvpState(),
    loadEvpLogs(),
  ])
  await nextTick()
  evpDialog.value?.showModal()
}

function closeEvpModal() {
  evpDialog.value?.close()
}

async function submitManualEvp() {
  await handleEvpTriggered({
    vehicleType: evpForm.vehicleType,
    direction: evpForm.direction,
  })
}

async function navigate(menu) {
  searchOpen.value = false
  search.value = ''
  mobileMenuOpen.value = false
  openPopover.value = ''
  if (menu.label === 'Emergency Vehicle Priority') {
    await openEvpModal()
    return
  }
  if (menu.target) {
    const section = document.getElementById(menu.target)
    section?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' })
    section?.focus({ preventScroll: true })
  } else {
    placeholder.value = menu
    await nextTick()
    placeholderDialog.value?.showModal()
  }
}

function closePlaceholder() { placeholderDialog.value?.close() }
function toggleMenu() {
  if (isMobile.value) mobileMenuOpen.value = !mobileMenuOpen.value
  else sidebarCompact.value = !sidebarCompact.value
}
function closeOverlays(event) {
  if (!event.target.closest('.topbar-popover-wrap')) openPopover.value = ''
  if (!event.target.closest('.search-box')) searchOpen.value = false
}
function handleEscape(event) {
  if (event.key === 'Escape') {
    openPopover.value = ''
    searchOpen.value = false
    mobileMenuOpen.value = false
  }
}

onMounted(() => {
  mobileQuery = window.matchMedia('(max-width: 1000px)')
  syncMobileLayout()
  mobileQuery.addEventListener('change', syncMobileLayout)
  loadData()
  document.addEventListener('click', closeOverlays)
  document.addEventListener('keydown', handleEscape)
})

onBeforeUnmount(() => {
  mobileQuery?.removeEventListener('change', syncMobileLayout)
  abortController?.abort()
  clearTimeout(toastTimer)
  document.removeEventListener('click', closeOverlays)
  document.removeEventListener('keydown', handleEscape)
})
</script>

<template>
  <div class="app-shell" :class="{ 'sidebar-compact': sidebarCompact, 'mobile-menu-open': mobileMenuOpen }">
    <a class="skip-link" href="#dashboard">Lewati ke konten utama</a>
    <header class="topbar">
      <div class="brand-area">
        <a href="#dashboard" class="brand" aria-label="SIGAP Dashboard" @click.prevent="navigate(menus[0])">
          <svg class="brand-mark" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M11 3h10v8h8v10h-8v8H11v-8H3V11h8Z" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"/><path d="M16 7v6m9 3h-6m-3 9v-6M7 16h6" stroke="currentColor" stroke-width="2"/><circle cx="16" cy="16" r="2" fill="currentColor"/></svg>
          <span>SIGAP</span>
        </a>
        <button class="icon-button menu-toggle" aria-label="Buka atau ringkas navigasi" aria-controls="main-sidebar" :aria-expanded="isMobile ? mobileMenuOpen : !sidebarCompact" @click="toggleMenu"><AppIcon name="menu" :size="21" /></button>
      </div>
      <div class="topbar-content">
        <form class="search-box" role="search" @submit.prevent="searchResults.length && navigate(searchResults[0])">
          <AppIcon name="search" :size="18" /><input v-model="search" aria-label="Cari menu atau pemantauan" placeholder="Cari menu atau pemantauan..." autocomplete="off" :aria-expanded="searchOpen" aria-controls="search-results" @focus="searchOpen = true" @input="searchOpen = true" /><kbd aria-hidden="true">⌕</kbd>
          <div v-if="searchOpen" id="search-results" class="search-results"><span class="popover-eyebrow">NAVIGASI CEPAT</span><button v-for="menu in searchResults" :key="menu.label" type="button" @click="navigate(menu)"><AppIcon :name="menu.icon" :size="16" /><span>{{ menu.label }}</span><span v-if="!menu.target" class="search-placeholder">Standby</span><AppIcon v-else name="chevron" :size="14" /></button><p v-if="!searchResults.length">Menu tidak ditemukan. Coba “CCTV” atau “Peta”.</p></div>
        </form>
        <div class="topbar-right">
          <span class="topbar-status"><i class="status-dot" :class="{ green: dbConnected }"></i>Mode Simulator</span><span class="topbar-divider"></span>
          <div class="topbar-popover-wrap"><button class="icon-button notification-button" aria-label="Notifikasi sistem" :aria-expanded="openPopover === 'notifications'" @click="openPopover = openPopover === 'notifications' ? '' : 'notifications'"><AppIcon name="bell" /></button><div v-if="openPopover === 'notifications'" class="topbar-popover notification-popover"><h3>Notifikasi sistem</h3><div class="notification-item"><span class="icon-tile blue"><AppIcon name="info" :size="19" /></span><div><strong>Menunggu koneksi CCTV</strong><p>{{ systemStatus?.notes || 'Keempat arah masih Standby. Rekomendasi tersedia setelah data antrean terhubung.' }}</p></div></div><span class="popover-note">Informasi prototype · Simulator</span></div></div>
          <div class="topbar-popover-wrap"><button class="operator-button" :aria-expanded="openPopover === 'profile'" aria-label="Profil Operator BCC" @click="openPopover = openPopover === 'profile' ? '' : 'profile'"><span class="avatar avatar-small">OP</span><span class="topbar-operator">Operator BCC</span><AppIcon name="down" :size="14" /></button><div v-if="openPopover === 'profile'" class="topbar-popover profile-popover"><span class="avatar">OP</span><h3>Operator BCC</h3><p>Bandung Command Center</p><span class="badge badge-blue">Operator · Simulator</span></div></div>
        </div>
      </div>
    </header>

    <button v-if="mobileMenuOpen" class="sidebar-backdrop" aria-label="Tutup navigasi" @click="mobileMenuOpen = false"></button>
    <aside id="main-sidebar" class="sidebar" aria-label="Navigasi utama" :inert="isMobile && !mobileMenuOpen">
      <div class="sidebar-profile"><span class="avatar">OP<span class="avatar-dot"></span></span><div class="sidebar-profile-text"><strong>Operator BCC</strong><span>Bandung Command Center</span></div></div>
      <div class="navigation-label">RUANG OPERASIONAL</div>
      <nav><button v-for="(menu, index) in menus" :key="menu.label" :class="['nav-item', { active: index === 0, 'nav-group-start': index === 6 }]" :aria-current="index === 0 ? 'page' : undefined" :title="menu.label + (!menu.target ? ' · Standby' : '')" @click="navigate(menu)"><AppIcon :name="menu.icon" :size="19" /><span>{{ menu.label }}</span><span v-if="index === 0" class="active-dot"></span></button></nav>
      <div class="sidebar-bottom">
        <div class="sidebar-location">
          <AppIcon name="pin" :size="18" />
          <div>
            <strong>{{ intersection?.name || 'Simpang Ibrahim Adjie' }}</strong>
            <span>{{ intersection?.location || 'Bandung, Jawa Barat' }}</span>
          </div>
        </div>
        <div class="prototype-label"><span class="status-dot blue"></span>PROTOTYPE DSS<span>v0.2</span></div>
      </div>
    </aside>

    <main id="dashboard" class="main-content" tabindex="-1">
      <!-- High-Priority EVP Emergency Alert Banner -->
      <div v-if="activeEvp" class="evp-alert-banner" role="alert" aria-live="assertive">
        <div class="evp-banner-content">
          <span class="evp-siren-icon"><AppIcon name="shield" :size="20" /></span>
          <div class="evp-banner-text">
            <strong>PERINGATAN PRIORITAS KENDARAAN DARURAT (EVP) AKTIF</strong>
            <p>{{ activeEvp.alert_message || `Terdeteksi ${activeEvp.vehicle_label} dari Arah ${activeEvp.direction_label}. Koridor ${activeEvp.target_phase_name} dibuka aman.` }}</p>
          </div>
        </div>
        <div class="evp-banner-actions">
          <span class="evp-phase-pill">
            <AppIcon name="traffic" :size="14" />
            {{ activeEvp.target_phase_name }} · {{ activeEvp.priority_duration_seconds }}s
          </span>
          <button type="button" class="evp-cancel-btn" :disabled="evpLoading" @click="handleEvpCancelled">
            Batalkan Aman (Safe Cancel)
          </button>
          <button type="button" class="evp-details-btn" @click="openEvpModal">
            Kontrol &amp; Audit Log
          </button>
        </div>
      </div>

      <section class="hero" aria-labelledby="dashboard-title">
        <div class="hero-content">
          <div class="hero-eyebrow"><span>COMMAND CENTER</span><span class="eyebrow-slash">/</span>PEMANTAUAN SIMPANG</div>
          <h1 id="dashboard-title">Dashboard Operasional</h1>
          <p>{{ intersection ? `${intersection.name} (${intersection.code})` : 'SIGAP — Simpang Jl. Ibrahim Adjie, Sisi Mall Tenth Avenue, Bandung' }}</p>
        </div>
        <div class="hero-actions">
          <span class="hero-badge">
            <i class="status-dot" :class="{ green: dbConnected }"></i>
            {{ activeEvp ? 'EVP PRIORITAS AKTIF' : (systemStatus?.current_mode ? formatMode(systemStatus.current_mode).toUpperCase() : 'ATCS NORMAL') }} · SIMULATOR
          </span>
          <div class="hero-action-row">
            <span class="operator-monitor"><AppIcon name="shield" :size="15" />Dipantau Operator BCC</span>
            <button class="refresh-button" :disabled="refreshing" @click="loadData(true)">
              <AppIcon name="refresh" :size="16" :class="{ spinning: refreshing }" />
              {{ refreshing ? 'Memeriksa...' : 'Refresh Status' }}
            </button>
          </div>
        </div>
      </section>

      <div class="dashboard-body">
        <div class="summary-grid">
          <section class="card operation-card" aria-labelledby="operation-title">
            <div class="summary-heading">
              <h2 id="operation-title">Status Operasional Simpang</h2>
              <span class="summary-meta">{{ lastChecked ? `${lastChecked} WIB` : 'Menunggu status' }}</span>
            </div>
            <div class="operation-metrics">
              <div class="operation-metric">
                <span class="icon-tile blue"><AppIcon name="arrows" /></span>
                <div>
                  <span class="metric-label">Fase aktif</span>
                  <strong>{{ activeEvp ? activeEvp.target_phase_name : activeSignalPhase.name }}</strong>
                  <span class="metric-note">{{ activeEvp ? `${activeEvp.priority_duration_seconds} dtk (EVP)` : (activeSignalPhase.duration?.default_seconds ? `${activeSignalPhase.duration.default_seconds} dtk (DB)` : 'Simulator') }}</span>
                </div>
              </div>
              <div class="operation-metric">
                <span class="icon-tile indigo"><AppIcon name="traffic" /></span>
                <div>
                  <span class="metric-label">Mode sistem</span>
                  <strong>{{ activeEvp ? 'EVP Prioritas' : formatMode(systemStatus?.current_mode) }}</strong>
                  <span class="metric-note">{{ activeEvp ? 'Interupsi Aman' : (dbConnected ? 'Database Aktif' : 'Simulator') }}</span>
                </div>
              </div>
              <div class="operation-metric">
                <span class="icon-tile" :class="activeEvp ? 'red' : 'green'"><AppIcon name="shield" /></span>
                <div>
                  <span class="metric-label">Status EVP</span>
                  <strong v-if="activeEvp" class="text-danger evp-pulsing">{{ activeEvp.vehicle_label }} (Aktif)</strong>
                  <strong v-else class="text-green">Aman</strong>
                  <span class="metric-note">{{ activeEvp ? `Arah ${activeEvp.direction_label} · Prioritas` : 'Simulator Standby' }}</span>
                </div>
              </div>
              <div class="operation-metric">
                <span class="icon-tile amber"><AppIcon name="pulse" /></span>
                <div>
                  <span class="metric-label">Kesehatan layanan</span>
                  <strong>{{ serviceStatus }}</strong>
                  <span class="metric-note">{{ dbConnected ? 'API & Database OK' : (backendStatus === 'Terhubung' ? 'API Standby' : 'Menunggu koneksi') }}</span>
                </div>
              </div>
            </div>
          </section>
          <section class="card queue-card" aria-labelledby="queue-title">
            <div class="summary-heading">
              <h2 id="queue-title">Ringkasan Antrean</h2>
              <span class="badge badge-neutral">{{ cameras.length > 0 ? `${cameras.length} Kamera Terdaftar` : 'Menunggu data' }}</span>
            </div>
            <div class="queue-metrics">
              <div v-for="direction in directions" :key="direction" class="queue-metric">
                <div><span>{{ direction }}</span><strong>—</strong></div>
                <div class="queue-indicator" aria-hidden="true"><i v-for="segment in 12" :key="segment"></i></div>
                <p>{{ cameras.length > 0 ? 'Menunggu data CCTV' : 'Menunggu koneksi' }}</p>
              </div>
            </div>
          </section>
        </div>

        <div class="main-grid">
          <IntersectionMap
            :signalPhases="signalPhases"
            :activeEvp="activeEvp"
            @evp-triggered="handleEvpTriggered"
            @evp-cancelled="handleEvpCancelled"
            @evp-completed="handleEvpCompleted"
          />
          <div class="decision-column">
            <section id="rekomendasi-ai" class="card recommendation-card" tabindex="-1" aria-labelledby="recommendation-title">
              <div class="card-heading">
                <div class="heading-with-icon">
                  <span class="icon-tile blue"><AppIcon name="spark" :size="19" /></span>
                  <div>
                    <h2 id="recommendation-title">Rekomendasi AI / Heuristik</h2>
                    <p>Pendukung keputusan operator</p>
                  </div>
                </div>
                <span class="badge" :class="latestDecision ? 'badge-blue' : 'badge-neutral'">
                  <i class="status-dot" :class="{ green: Boolean(latestDecision) }"></i>
                  {{ latestDecision ? 'Rekomendasi Aktif' : 'Standby' }}
                </span>
              </div>
              <div class="recommendation-content">
                <dl class="recommendation-metrics">
                  <div>
                    <dt>Fase rekomendasi</dt>
                    <dd :class="{ 'phase-text': Boolean(latestDecision) }">
                      {{ latestDecision?.signal_phase?.name || latestDecision?.payload?.winning_phase_name || '—' }}
                    </dd>
                  </div>
                  <div>
                    <dt>Skor prioritas</dt>
                    <dd :class="{ 'score-text': Boolean(latestDecision) }">
                      {{ latestDecision?.priority_score ? `${latestDecision.priority_score} / 100` : '—' }}
                    </dd>
                  </div>
                  <div>
                    <dt>Durasi hijau</dt>
                    <dd :class="{ 'duration-text': Boolean(latestDecision) }">
                      {{ latestDecision?.proposed_duration_seconds ? `${latestDecision.proposed_duration_seconds} dtk` : '—' }}
                    </dd>
                  </div>
                </dl>
                <div class="recommendation-reason">
                  <AppIcon :name="latestDecision?.has_emergency ? 'shield' : 'clock'" :size="19" />
                  <div>
                    <strong>{{ latestDecision?.has_emergency ? 'Emergency Vehicle Priority (EVP)' : (latestDecision ? `Alasan Keputusan DSS (${latestDecision.rule_applied || 'Heuristik'})` : 'Menunggu data antrean') }}</strong>
                    <p><span class="sr-only">Alasan: </span>{{ latestDecision?.reason || systemStatus?.notes || 'Menunggu pengukuran zona antrean dari CCTV.' }}</p>
                  </div>
                </div>
                <p class="heuristic-note">Keputusan adaptif dihitung dengan formula Satuan Mobil Penumpang (SMP) &amp; anti-starvation di backend Laravel.</p>
                <div class="heuristic-sim-row">
                  <label for="heuristic-scenario">Uji Skenario Kepadatan Simpang:</label>
                  <div class="sim-row-controls">
                    <select id="heuristic-scenario" v-model="selectedScenario" :disabled="evaluatingHeuristic || !dbConnected">
                      <option value="default">Jam Sibuk Normal (Barat Lebih Padat)</option>
                      <option value="north_peak">Puncak Kepadatan Arah Utara</option>
                      <option value="emergency_west">EVP: Ambulans dari Barat</option>
                      <option value="emergency_north">EVP: Pemadam dari Utara</option>
                    </select>
                    <button type="button" class="eval-btn" :disabled="evaluatingHeuristic || !dbConnected" @click="runHeuristicEvaluation(selectedScenario)">
                      <AppIcon name="spark" :size="13" :class="{ spinning: evaluatingHeuristic }" />
                      {{ evaluatingHeuristic ? 'Menghitung...' : 'Evaluasi' }}
                    </button>
                  </div>
                </div>
              </div>
            </section>
            <section class="card integration-card" aria-labelledby="integration-title">
              <div class="card-heading">
                <div>
                  <h2 id="integration-title">Status Integrasi ATCS</h2>
                  <p>Alur mode operasional sistem</p>
                </div>
                <AppIcon name="link" class="muted-icon" :size="19" />
              </div>
              <div class="integration-content">
                <ol class="integration-flow" aria-label="SIGAP Adaptive ke Fallback Aman ke ATCS Normal">
                  <li><AppIcon name="spark" :size="19" /><span>SIGAP Adaptive</span></li>
                  <li class="flow-step-arrow" aria-hidden="true"><AppIcon name="arrow" :size="15" /></li>
                  <li><AppIcon name="shield" :size="19" /><span>Fallback Aman</span></li>
                  <li class="flow-step-arrow" aria-hidden="true"><AppIcon name="arrow" :size="15" /></li>
                  <li class="current"><AppIcon name="traffic" :size="19" /><span>ATCS Normal</span></li>
                </ol>
                <div class="integration-active">
                  <span><i class="status-dot blue"></i>Status aktif</span>
                  <strong>{{ formatMode(systemStatus?.current_mode) }} (Simulator)</strong>
                </div>
                <p class="operator-note">Operator Bandung Command Center tetap memantau sistem pada seluruh mode.</p>
                <div class="service-readout">
                  <span>Backend <i class="status-dot" :class="{ green: backendStatus === 'Terhubung' }"></i>{{ backendStatus }} {{ dbConnected ? '(DB OK)' : '' }}</span>
                  <span>Layanan AI <i class="status-dot" :class="{ green: aiStatus === 'Terhubung' }"></i>{{ aiStatus }}</span>
                </div>
              </div>
            </section>
          </div>
        </div>

        <CctvMonitoring
          :cameras="cameras"
          :isOnline="backendStatus === 'Terhubung'"
          :isCctvHealthy="Boolean(systemStatus?.is_cctv_healthy)"
        />
        <footer class="page-footer">
          <p>SIGAP — Prototype Decision Support System untuk ATCS Bandung Command Center</p>
          <span><i class="status-dot blue"></i>Lingkungan simulator</span>
        </footer>
      </div>
    </main>
    <div class="toast-region" role="status" aria-live="polite">
      <div v-if="toast" class="toast">
        <AppIcon name="info" :size="19" />
        <span>{{ toast }}</span>
        <button aria-label="Tutup pesan" @click="toast = ''"><AppIcon name="close" :size="16" /></button>
      </div>
    </div>
    <dialog ref="placeholderDialog" class="placeholder-dialog" aria-labelledby="placeholder-title" @click="event => { if (event.target === placeholderDialog) closePlaceholder() }">
      <template v-if="placeholder">
        <button class="dialog-close" aria-label="Tutup" @click="closePlaceholder"><AppIcon name="close" /></button>
        <span class="icon-tile blue"><AppIcon :name="placeholder.icon" :size="26" /></span>
        <span class="badge badge-neutral">Standby</span>
        <h2 id="placeholder-title">{{ placeholder.label }}</h2>
        <p>Modul ini belum terhubung pada prototype SIGAP. Pemantauan simpang tersedia di Dashboard Operasional.</p>
        <button class="primary-button" @click="closePlaceholder">Kembali ke dashboard</button>
      </template>
    </dialog>

    <!-- Dedicated EVP Control & Audit Log Modal (Tahap 7) -->
    <dialog ref="evpDialog" class="evp-modal-dialog" aria-labelledby="evp-modal-title" @click="event => { if (event.target === evpDialog) closeEvpModal() }">
      <div class="evp-modal-header">
        <h2 id="evp-modal-title"><AppIcon name="shield" :size="18" />Emergency Vehicle Priority (EVP) Control Center</h2>
        <button class="evp-modal-close" aria-label="Tutup dialog" @click="closeEvpModal"><AppIcon name="close" :size="18" /></button>
      </div>
      <div class="evp-modal-body">
        <div class="evp-protocol-box">
          <AppIcon name="info" :size="20" />
          <div>
            <strong>Protokol Keselamatan Transisi EVP</strong>
            <p>Sistem tidak pernah memutus lampu secara tiba-tiba. Setiap interupsi darurat menyelesaikan clearance fase saat ini (Kuning 3s &rarr; All-Red 2s), mengunci lajur non-prioritas, lalu mengaktifkan hijau aman pada koridor darurat.</p>
          </div>
        </div>

        <div class="evp-status-card" :class="{ active: Boolean(activeEvp) }">
          <div>
            <span class="metric-label">Status Terkini Simpang</span>
            <div class="evp-status-badge" :class="activeEvp ? 'emergency' : 'normal'">
              <i class="status-dot" :class="activeEvp ? 'red' : 'green'"></i>
              {{ activeEvp ? `EVP AKTIF: ${activeEvp.vehicle_label} (${activeEvp.direction_label})` : 'Status Aman (Tidak Ada Kendaraan Darurat)' }}
            </div>
            <p v-if="activeEvp" style="font-size: 11px; color: #742a2a; margin-top: 4px;">
              Fase Prioritas: <strong>{{ activeEvp.target_phase_name }}</strong> (Durasi: {{ activeEvp.priority_duration_seconds }}s)
            </p>
          </div>
          <div v-if="activeEvp" style="display: flex; gap: 8px;">
            <button type="button" class="evp-cancel-btn" :disabled="evpLoading" @click="handleEvpCancelled">
              Batalkan Aman
            </button>
            <button type="button" class="eval-btn" :disabled="evpLoading" style="background: #276749;" @click="handleEvpCompleted">
              Selesai &amp; Pulihkan
            </button>
          </div>
        </div>

        <h3 class="evp-section-heading"><AppIcon name="spark" :size="16" />Pemicu Simulasi / Deteksi Darurat</h3>
        <form class="evp-form-grid" @submit.prevent="submitManualEvp">
          <div class="evp-form-group">
            <label for="modal-evp-type">Jenis Kendaraan Darurat</label>
            <select id="modal-evp-type" v-model="evpForm.vehicleType" :disabled="Boolean(activeEvp) || evpLoading">
              <option value="ambulance">Ambulans (Prioritas Tertinggi)</option>
              <option value="firetruck">Mobil Pemadam Kebakaran</option>
            </select>
          </div>
          <div class="evp-form-group">
            <label for="modal-evp-direction">Arah Pendekat Asal</label>
            <select id="modal-evp-direction" v-model="evpForm.direction" :disabled="Boolean(activeEvp) || evpLoading">
              <option value="WEST">Barat (Jl. Ibrahim Adjie Barat)</option>
              <option value="NORTH">Utara (Jl. Ibrahim Adjie Utara)</option>
              <option value="EAST">Timur (Arah Mall Tenth Avenue)</option>
              <option value="SOUTH">Selatan (Jl. Ibrahim Adjie Selatan)</option>
            </select>
          </div>
          <button type="submit" class="evp-trigger-btn" :disabled="Boolean(activeEvp) || evpLoading">
            <AppIcon name="shield" :size="15" />
            {{ evpLoading ? 'Mengaktifkan...' : 'Aktifkan EVP' }}
          </button>
        </form>

        <h3 class="evp-section-heading"><AppIcon name="history" :size="16" />Riwayat Audit Log Transisi EVP</h3>
        <div class="evp-log-table-wrap">
          <table class="evp-log-table">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Mode Awal</th>
                <th>Mode Baru</th>
                <th>Keterangan Transisi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in evpLogs" :key="log.id">
                <td style="white-space: nowrap; color: #718096;">
                  {{ new Date(log.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) }} WIB
                </td>
                <td><span class="badge badge-neutral">{{ log.previous_mode }}</span></td>
                <td>
                  <span class="badge" :class="log.new_mode === 'EVP_ACTIVE' ? 'badge-blue' : 'badge-neutral'" :style="log.new_mode === 'EVP_ACTIVE' ? 'background: #fff5f5; color: #c53030; border-color: #feb2b2;' : ''">
                    {{ log.new_mode }}
                  </span>
                </td>
                <td>{{ log.reason }}</td>
              </tr>
              <tr v-if="!evpLogs.length">
                <td colspan="4" class="evp-empty-logs">Belum ada catatan log aktivitas EVP pada sesi ini.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </dialog>
  </div>
</template>
