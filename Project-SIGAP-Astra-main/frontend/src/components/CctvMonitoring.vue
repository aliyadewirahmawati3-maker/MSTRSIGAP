<script setup>
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'

const props = defineProps({
  cameras: {
    type: Array,
    default: () => [],
  },
  isOnline: {
    type: Boolean,
    default: false,
  },
  isCctvHealthy: {
    type: Boolean,
    default: false,
  },
})

const defaultCameras = [
  { id: 1, direction: 'Barat', name: 'CCTV Barat', code: 'CAM-W-01', status: 'UNCONFIGURED', resolution: '1920x1080' },
  { id: 2, direction: 'Utara', name: 'CCTV Utara', code: 'CAM-N-01', status: 'UNCONFIGURED', resolution: '1920x1080' },
  { id: 3, direction: 'Timur', name: 'CCTV Timur', code: 'CAM-E-01', status: 'UNCONFIGURED', resolution: '1920x1080' },
  { id: 4, direction: 'Selatan', name: 'CCTV Selatan', code: 'CAM-S-01', status: 'UNCONFIGURED', resolution: '1920x1080' },
]

const displayCameras = computed(() => {
  if (props.cameras && props.cameras.length > 0) {
    return props.cameras.map((cam, idx) => ({
      id: cam.id || idx + 1,
      name: cam.name || `CCTV ${cam.direction || ''}`,
      code: cam.code || `CAM-0${idx + 1}`,
      direction: cam.direction || '',
      status: cam.status || 'UNCONFIGURED',
      resolution: cam.resolution || '1920x1080',
      stream_url: cam.stream_url || null,
    }))
  }
  return defaultCameras
})

const connectionStatusText = computed(() => {
  if (!props.isOnline) return 'Belum terhubung (API Offline)'
  if (props.isCctvHealthy) return 'CCTV Aktif'
  return `${displayCameras.value.length} Kamera Terdaftar (UNCONFIGURED)`
})
</script>

<template>
  <section id="live-monitoring" class="card cctv-card" tabindex="-1" aria-labelledby="cctv-title">
    <div class="card-heading">
      <div>
        <h2 id="cctv-title">Live Monitoring CCTV <span class="title-separator">—</span> 4 Arah</h2>
        <p>Pemantauan visual seluruh pendekat persimpangan dari konfigurasi database</p>
      </div>
      <span class="camera-connection">
        <i class="status-dot" :class="{ green: isCctvHealthy, blue: isOnline && !isCctvHealthy }"></i>
        {{ connectionStatusText }}
      </span>
    </div>
    <div class="cctv-grid">
      <article
        v-for="(camera, index) in displayCameras"
        :key="camera.code || index"
        class="camera-card"
        :aria-label="`${camera.name} (${camera.code}), Status: ${camera.status}, stream belum terhubung`"
      >
        <div class="camera-heading">
          <h3><AppIcon name="camera" :size="16" />{{ camera.name }}</h3>
          <span class="camera-number">{{ camera.code }}</span>
        </div>
        <div class="camera-frame">
          <span class="camera-standby">
            <i class="status-dot" :class="{ green: camera.status === 'ONLINE' }"></i>
            {{ camera.status }}
          </span>
          <div class="camera-empty">
            <AppIcon name="cameraOff" :size="30" />
            <p>{{ camera.stream_url ? 'STREAMING AKTIF' : 'STREAM CCTV BELUM TERHUBUNG' }}</p>
            <span>{{ camera.stream_url || 'Konfigurasi awal: stream null' }}</span>
          </div>
          <span class="frame-corner top-left"></span>
          <span class="frame-corner bottom-right"></span>
        </div>
        <dl class="lane-info">
          <div><dt>Resolusi</dt><dd>{{ camera.resolution }}</dd></div>
          <div><dt>Lajur pantauan</dt><dd>Luar & Dalam</dd></div>
        </dl>
      </article>
    </div>
  </section>
</template>
