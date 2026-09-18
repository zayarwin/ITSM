<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '../utils/api.js'

const incidents = ref([])
const loading = ref(true)
const selected = ref(null)
let pollTimer = null

// One filter value per table column.
const deviceFilter = ref('')
const facilityFilter = ref('')
const severityFilter = ref('')
const eventFilter = ref('')
const detectedFilter = ref('') // yyyy-mm-dd, matched against the detected_at date
const statusFilter = ref('')

const facilityOptions = computed(() => {
  const set = new Set(incidents.value.map(i => i.facility).filter(Boolean))
  return [...set].sort()
})

const severityOptions = [0, 1, 2, 3, 4, 5, 6, 7]
const statusOptions = ['detected', 'analyzing', 'analyzed', 'failed']

const filteredIncidents = computed(() => {
  const device = deviceFilter.value.trim().toLowerCase()
  const event = eventFilter.value.trim().toLowerCase()

  return incidents.value.filter(incident => {
    if (device) {
      const deviceHaystack = [incident.device?.hostname, incident.device?.ip_address]
        .filter(Boolean).join(' ').toLowerCase()
      if (!deviceHaystack.includes(device)) return false
    }

    if (facilityFilter.value && incident.facility !== facilityFilter.value) return false
    if (severityFilter.value !== '' && incident.severity !== Number(severityFilter.value)) return false

    if (event) {
      const eventHaystack = [incident.mnemonic, incident.previous_state, incident.new_state, incident.raw_message]
        .filter(Boolean).join(' ').toLowerCase()
      if (!eventHaystack.includes(event)) return false
    }

    if (detectedFilter.value) {
      const incidentDate = incident.detected_at ? incident.detected_at.slice(0, 10) : ''
      if (incidentDate !== detectedFilter.value) return false
    }

    if (statusFilter.value && incident.status !== statusFilter.value) return false

    return true
  })
})

const clearFilters = () => {
  deviceFilter.value = ''
  facilityFilter.value = ''
  severityFilter.value = ''
  eventFilter.value = ''
  detectedFilter.value = ''
  statusFilter.value = ''
}

const hasActiveFilters = computed(() =>
  deviceFilter.value || facilityFilter.value || severityFilter.value !== '' ||
  eventFilter.value || detectedFilter.value || statusFilter.value
)

const statusBadge = (status) => ({
  detected: 'bg-slate-100 text-slate-700',
  analyzing: 'bg-amber-100 text-amber-800',
  analyzed: 'bg-emerald-100 text-emerald-800',
  failed: 'bg-red-100 text-red-700',
}[status] || 'bg-slate-100 text-slate-700')

const facilityBadge = (facility) => ({
  OSPF: 'bg-blue-100 text-blue-800',
  BGP: 'bg-purple-100 text-purple-800',
  LINK: 'bg-amber-100 text-amber-800',
  LINEPROTO: 'bg-amber-100 text-amber-800',
  SYS: 'bg-slate-200 text-slate-800',
}[facility] || 'bg-slate-100 text-slate-700')

const severityBadge = (severity) => {
  if (severity === null || severity === undefined) return 'bg-slate-100 text-slate-500'
  if (severity <= 2) return 'bg-red-100 text-red-700'
  if (severity === 3) return 'bg-orange-100 text-orange-700'
  if (severity === 4) return 'bg-amber-100 text-amber-800'
  return 'bg-slate-100 text-slate-600'
}

const severityLabel = (severity) => ({
  0: 'Emergency', 1: 'Alert', 2: 'Critical', 3: 'Error',
  4: 'Warning', 5: 'Notice', 6: 'Info', 7: 'Debug',
}[severity] ?? 'Unknown')

const fetchIncidents = async () => {
  try {
    const response = await api.get('/incidents')
    incidents.value = response.data

    if (selected.value) {
      const refreshed = incidents.value.find(i => i.id === selected.value.id)
      if (refreshed) {
        selected.value = refreshed
      }
    }
  } catch (error) {
    console.error('Failed to load incidents:', error)
  } finally {
    loading.value = false
  }
}

const openIncident = (incident) => {
  selected.value = incident
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

onMounted(() => {
  fetchIncidents()
  pollTimer = setInterval(fetchIncidents, 10000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <div class="h-full flex flex-col">
    <div class="flex justify-between items-center mb-6">
      <div>
        <h2 class="text-2xl font-semibold text-slate-800">Incidents</h2>
        <p class="text-sm text-slate-500 mt-1">Operationally significant events detected via syslog, investigated automatically by AI.</p>
      </div>
      <button @click="fetchIncidents" class="border border-slate-200 hover:bg-slate-50 text-slate-600 px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
        Refresh
      </button>
    </div>

    <div class="flex items-center justify-end gap-3 mb-2">
      <button v-if="hasActiveFilters" @click="clearFilters" class="text-sm font-medium text-blue-600 hover:text-blue-800 transition">Clear filters</button>
      <span class="text-xs text-slate-400">{{ filteredIncidents.length }} of {{ incidents.length }}</span>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 flex-1 overflow-hidden flex flex-col">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 text-sm uppercase tracking-wider text-slate-500">
              <th class="p-4 font-semibold">Device</th>
              <th class="p-4 font-semibold">Facility</th>
              <th class="p-4 font-semibold">Severity</th>
              <th class="p-4 font-semibold">Event</th>
              <th class="p-4 font-semibold">Detected</th>
              <th class="p-4 font-semibold">Status</th>
              <th class="p-4 font-semibold text-right">Actions</th>
            </tr>
            <tr class="border-b border-slate-200 bg-white">
              <th class="p-2">
                <input v-model="deviceFilter" type="text" placeholder="Filter device/IP" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs font-normal text-slate-700 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-200">
              </th>
              <th class="p-2">
                <select v-model="facilityFilter" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs font-normal text-slate-700 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-200">
                  <option value="">All</option>
                  <option v-for="f in facilityOptions" :key="f" :value="f">{{ f }}</option>
                </select>
              </th>
              <th class="p-2">
                <select v-model="severityFilter" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs font-normal text-slate-700 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-200">
                  <option value="">All</option>
                  <option v-for="s in severityOptions" :key="s" :value="s">{{ severityLabel(s) }}</option>
                </select>
              </th>
              <th class="p-2">
                <input v-model="eventFilter" type="text" placeholder="Filter event/message" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs font-normal text-slate-700 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-200">
              </th>
              <th class="p-2">
                <input v-model="detectedFilter" type="date" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs font-normal text-slate-700 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-200">
              </th>
              <th class="p-2">
                <select v-model="statusFilter" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs font-normal capitalize text-slate-700 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-200">
                  <option value="">All</option>
                  <option v-for="s in statusOptions" :key="s" :value="s" class="capitalize">{{ s }}</option>
                </select>
              </th>
              <th class="p-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <tr v-if="loading">
              <td colspan="7" class="p-8 text-center text-slate-400">Loading incidents...</td>
            </tr>
            <tr v-else-if="incidents.length === 0">
              <td colspan="7" class="p-8 text-center text-slate-400">No incidents detected yet.</td>
            </tr>
            <tr v-else-if="filteredIncidents.length === 0">
              <td colspan="7" class="p-8 text-center text-slate-400">No incidents match the current filters.</td>
            </tr>
            <tr v-else v-for="incident in filteredIncidents" :key="incident.id" class="hover:bg-slate-50 transition">
              <td class="p-4 font-medium text-slate-900">
                <div>{{ incident.device?.hostname || 'Unknown device' }}</div>
                <div class="text-xs text-slate-500 font-mono">{{ incident.device?.ip_address }}</div>
              </td>
              <td class="p-4">
                <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium uppercase', facilityBadge(incident.facility)]">
                  {{ incident.facility }}
                </span>
              </td>
              <td class="p-4">
                <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', severityBadge(incident.severity)]">
                  {{ severityLabel(incident.severity) }}
                </span>
              </td>
              <td class="p-4 font-mono text-sm">
                <template v-if="incident.new_state">
                  <span class="text-slate-400">{{ incident.previous_state || '?' }}</span>
                  <span class="mx-1 text-slate-300">&rarr;</span>
                  <span class="font-semibold text-red-600">{{ incident.new_state }}</span>
                </template>
                <span v-else class="text-slate-500">{{ incident.mnemonic || '—' }}</span>
              </td>
              <td class="p-4 text-sm text-slate-500">{{ formatDate(incident.detected_at) }}</td>
              <td class="p-4">
                <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize', statusBadge(incident.status)]">
                  {{ incident.status }}
                </span>
              </td>
              <td class="p-4 text-right">
                <button @click="openIncident(incident)" class="text-blue-600 hover:text-blue-800 p-2 hover:bg-blue-50 rounded transition text-sm font-medium">View</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detail Modal -->
    <div v-if="selected" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="selected = null"></div>
      <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl relative z-10 overflow-hidden flex flex-col max-h-[85vh]">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <h3 class="text-xl font-semibold text-slate-800">{{ selected.device?.hostname }} &mdash; {{ selected.facility }}-{{ selected.mnemonic }}</h3>
            <p class="text-xs text-slate-500 mt-1">Detected {{ formatDate(selected.detected_at) }}</p>
          </div>
          <button @click="selected = null" class="text-slate-400 hover:text-slate-600 transition">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>

        <div class="p-5 space-y-5 overflow-y-auto">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Raw Syslog Message</p>
            <pre class="bg-slate-900 text-emerald-200 text-xs p-3 rounded-lg overflow-x-auto whitespace-pre-wrap">{{ selected.raw_message }}</pre>
          </div>

          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">AI Analysis</p>
            <div v-if="selected.status === 'detected' || selected.status === 'analyzing'" class="flex items-center gap-2 text-sm text-slate-500 bg-slate-50 border border-slate-200 rounded-lg p-4">
              <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
              Investigating&hellip; this updates automatically.
            </div>
            <div v-else-if="selected.ai_analysis" class="bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-slate-700 whitespace-pre-wrap leading-relaxed">{{ selected.ai_analysis }}</div>
            <div v-else class="text-sm text-slate-400">No analysis available.</div>
          </div>

          <div v-if="selected.diagnostic_context">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Diagnostic Commands Collected</p>
            <pre class="bg-slate-900 text-slate-200 text-xs p-3 rounded-lg overflow-x-auto whitespace-pre-wrap max-h-64">{{ selected.diagnostic_context }}</pre>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
