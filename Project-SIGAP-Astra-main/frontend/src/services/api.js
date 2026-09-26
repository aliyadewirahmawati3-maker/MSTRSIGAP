import axios from 'axios'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api'
const AI_BASE_URL = import.meta.env.VITE_AI_BASE_URL || 'http://localhost:8001'

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 5000,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

const aiClient = axios.create({
  baseURL: AI_BASE_URL,
  timeout: 5000,
  headers: {
    'Accept': 'application/json',
  },
})

/**
 * Memeriksa status kesehatan backend dan konektivitas database
 */
export async function fetchBackendHealth(signal) {
  const response = await apiClient.get('/health', { signal })
  return response.data
}

/**
 * Memeriksa status kesehatan layanan AI (FastAPI)
 */
export async function fetchAiHealth(signal) {
  const response = await aiClient.get('/health', { signal })
  return response.data
}

/**
 * Mengambil daftar seluruh persimpangan
 */
export async function fetchIntersections(signal) {
  const response = await apiClient.get('/intersections', { signal })
  return response.data?.data || []
}

/**
 * Mengambil detail satu simpang berdasarkan ID
 */
export async function fetchIntersectionDetail(id, signal) {
  const response = await apiClient.get(`/intersections/${id}`, { signal })
  return response.data?.data || null
}

/**
 * Mengambil konfigurasi 4 arah pendekat (approaches) dan lajur
 */
export async function fetchApproaches(id, signal) {
  const response = await apiClient.get(`/intersections/${id}/approaches`, { signal })
  return response.data?.data || []
}

/**
 * Mengambil konfigurasi 4 kamera CCTV pada simpang
 */
export async function fetchCameras(id, signal) {
  const response = await apiClient.get(`/intersections/${id}/cameras`, { signal })
  return response.data?.data || []
}

/**
 * Mengambil status operasional sistem dan ATCS terkini
 */
export async function fetchSystemStatus(id, signal) {
  const response = await apiClient.get(`/intersections/${id}/system-status`, { signal })
  return response.data?.data || null
}

/**
 * Mengambil konfigurasi fase lampu lalu lintas dan durasi simulasi
 */
export async function fetchSignalPhases(id, signal) {
  const response = await apiClient.get(`/intersections/${id}/signal-phases`, { signal })
  return response.data?.data || []
}

/**
 * Mengambil seluruh data konfigurasi simpang secara paralel dan terpadu
 * @param {string} targetCode - Kode simpang default: 'BDG-IBR-ADJ-01'
 * @param {AbortSignal} signal - Optional abort signal
 */
export async function fetchCompleteIntersectionData(targetCode = 'BDG-IBR-ADJ-01', signal) {
  // 1. Ambil daftar simpang
  const intersections = await fetchIntersections(signal)
  if (!intersections || intersections.length === 0) {
    throw new Error('Tidak ada data simpang yang terdaftar di database.')
  }

  // Cari simpang yang cocok atau ambil indeks pertama
  const target = intersections.find(item => item.code === targetCode) || intersections[0]
  const intersectionId = target.id

  // 2. Ambil seluruh data relasi secara paralel
  const [systemStatus, cameras, signalPhases, approaches, heuristicDecision] = await Promise.all([
    fetchSystemStatus(intersectionId, signal).catch(() => target.latest_status || target.current_system_status || null),
    fetchCameras(intersectionId, signal).catch(() => target.cameras || []),
    fetchSignalPhases(intersectionId, signal).catch(() => target.signal_phases || []),
    fetchApproaches(intersectionId, signal).catch(() => target.approaches || []),
    fetchLatestHeuristicDecision(intersectionId, signal).catch(() => null),
  ])

  return {
    intersection: target,
    systemStatus,
    cameras,
    signalPhases,
    approaches,
    heuristicDecision,
  }
}

/**
 * Mengambil rekomendasi keputusan heuristik terbaru dari backend
 */
export async function fetchLatestHeuristicDecision(intersectionId = 1, signal) {
  const response = await apiClient.get(`/intersections/${intersectionId}/heuristic-decisions/latest`, { signal })
  return response.data?.data || null
}

/**
 * Memicu kalkulasi evaluasi heuristik baru (dengan payload kustom atau nama skenario)
 */
export async function evaluateHeuristic(intersectionId = 1, input = 'default', signal) {
  const payload = typeof input === 'string' ? { scenario: input } : { measurements: input }
  const response = await apiClient.post(`/intersections/${intersectionId}/evaluate-heuristic`, payload, { signal })
  return response.data?.data || null
}

/**
 * Mengambil riwayat keputusan heuristik
 */
export async function fetchHeuristicHistory(intersectionId = 1, signal) {
  const response = await apiClient.get(`/intersections/${intersectionId}/heuristic-decisions`, { signal })
  return response.data?.data || []
}

/**
 * Memicu aktivasi Emergency Vehicle Priority (EVP)
 */
export async function triggerEvp(intersectionId = 1, { vehicleType = 'ambulance', direction = 'WEST' } = {}, signal) {
  const response = await apiClient.post(`/intersections/${intersectionId}/evp/trigger`, {
    vehicle_type: vehicleType,
    direction: direction,
  }, { signal })
  return response.data?.data || null
}

/**
 * Membatalkan prioritas darurat secara aman (Safe Cancel)
 */
export async function cancelEvp(intersectionId = 1, signal) {
  const response = await apiClient.post(`/intersections/${intersectionId}/evp/cancel`, {}, { signal })
  return response.data?.data || null
}

/**
 * Menyelesaikan prioritas darurat setelah kendaraan melintas (Safe Recovery)
 */
export async function completeEvp(intersectionId = 1, signal) {
  const response = await apiClient.post(`/intersections/${intersectionId}/evp/complete`, {}, { signal })
  return response.data?.data || null
}

/**
 * Mengambil status EVP terkini pada simpang
 */
export async function fetchEvpStatus(intersectionId = 1, signal) {
  const response = await apiClient.get(`/intersections/${intersectionId}/evp/status`, { signal })
  return response.data?.data || null
}

/**
 * Mengambil riwayat log audit kejadian EVP
 */
export async function fetchEvpLogs(intersectionId = 1, signal) {
  const response = await apiClient.get(`/intersections/${intersectionId}/evp/logs`, { signal })
  return response.data?.data || []
}

export default {
  fetchBackendHealth,
  fetchAiHealth,
  fetchIntersections,
  fetchIntersectionDetail,
  fetchApproaches,
  fetchCameras,
  fetchSystemStatus,
  fetchSignalPhases,
  fetchCompleteIntersectionData,
  fetchLatestHeuristicDecision,
  evaluateHeuristic,
  fetchHeuristicHistory,
  triggerEvp,
  cancelEvp,
  completeEvp,
  fetchEvpStatus,
  fetchEvpLogs,
}


