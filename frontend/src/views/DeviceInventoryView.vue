<script setup>
import { ref, onMounted } from 'vue'
import api from '../utils/api.js'

const devices = ref([])
const loading = ref(true)
const showModal = ref(false)
const isEditing = ref(false)

const form = ref({
  id: null,
  hostname: '',
  ip_address: '',
  os_version: '',
  location: '',
  eol_date: '',
  model: ''
})

const fetchDevices = async () => {
  loading.value = true
  try {
    const response = await api.get('/devices')
    devices.value = response.data
  } catch (error) {
    console.error('Failed to load devices:', error)
  } finally {
    loading.value = false
  }
}

const openAddModal = () => {
  isEditing.value = false
  form.value = { id: null, hostname: '', ip_address: '', os_version: '', location: '', eol_date: '', model: '' }
  showModal.value = true
}

const openEditModal = (device) => {
  isEditing.value = true
  form.value = { ...device }
  showModal.value = true
}

const saveDevice = async () => {
  try {
    if (isEditing.value) {
      await api.put(`/devices/${form.value.id}`, form.value)
    } else {
      await api.post('/devices', form.value)
    }
    showModal.value = false
    await fetchDevices()
  } catch (error) {
    console.error('Failed to save device:', error)
    alert('Error saving device configuration.')
  }
}

const deleteDevice = async (id) => {
  if (confirm('Are you sure you want to permanently delete this device? This action cannot be undone.')) {
    try {
      await api.delete(`/devices/${id}`)
      await fetchDevices()
    } catch (error) {
      console.error('Failed to delete device:', error)
    }
  }
}

onMounted(() => {
  fetchDevices()
})
</script>

<template>
  <div class="h-full flex flex-col">
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-semibold text-slate-800">Device Inventory</h2>
      <button @click="openAddModal" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition shadow-sm flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
        Add Device
      </button>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 flex-1 overflow-hidden flex flex-col">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 text-sm uppercase tracking-wider text-slate-500">
              <th class="p-4 font-semibold">Hostname</th>
              <th class="p-4 font-semibold">IP Address</th>
              <th class="p-4 font-semibold">Model / OS</th>
              <th class="p-4 font-semibold">Location</th>
              <th class="p-4 font-semibold">Status</th>
              <th class="p-4 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <tr v-if="loading">
              <td colspan="6" class="p-8 text-center text-slate-400">Loading devices...</td>
            </tr>
            <tr v-else-if="devices.length === 0">
              <td colspan="6" class="p-8 text-center text-slate-400">No devices found. Add one to get started.</td>
            </tr>
            <tr v-else v-for="device in devices" :key="device.id" class="hover:bg-slate-50 transition">
              <td class="p-4 font-medium text-slate-900">{{ device.hostname }}</td>
              <td class="p-4"><span class="bg-blue-50 text-blue-700 font-mono text-sm px-2 py-1 rounded">{{ device.ip_address }}</span></td>
              <td class="p-4">
                <div class="flex flex-col">
                  <span>{{ device.model || 'Unknown Model' }}</span>
                  <span class="text-xs text-slate-500">OS: {{ device.os_version || 'N/A' }}</span>
                </div>
              </td>
              <td class="p-4">
                <span class="inline-flex items-center gap-1">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                  {{ device.location || 'Unassigned' }}
                </span>
              </td>
              <td class="p-4">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                  <span class="w-2 h-2 rounded-full bg-slate-400"></span> Unknown
                </span>
              </td>
              <td class="p-4 text-right">
                <button @click="openEditModal(device)" class="text-blue-600 hover:text-blue-800 p-2 hover:bg-blue-50 rounded transition mr-2">Edit</button>
                <button @click="deleteDevice(device.id)" class="text-red-500 hover:text-red-700 p-2 hover:bg-red-50 rounded transition">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Form -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showModal = false"></div>
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg relative z-10 overflow-hidden flex flex-col">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center">
          <h3 class="text-xl font-semibold text-slate-800">{{ isEditing ? 'Edit Device' : 'Add New Device' }}</h3>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 transition">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>
        <div class="p-5 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2">
              <label class="block text-sm font-medium text-slate-700 mb-1">Hostname <span class="text-red-500">*</span></label>
              <input v-model="form.hostname" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g. YGN-CORE-RTR01">
            </div>
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-700 mb-1">IP Address <span class="text-red-500">*</span></label>
              <input v-model="form.ip_address" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="10.0.0.1">
            </div>
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-700 mb-1">Location</label>
              <select v-model="form.location" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
                <option value="">Select Location</option>
                <option value="YGN">YGN (Yangon)</option>
                <option value="MDY">MDY (Mandalay)</option>
                <option value="NPT">NPT (Naypyidaw)</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-700 mb-1">Model</label>
              <input v-model="form.model" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g. CSR1000v">
            </div>
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-700 mb-1">OS Version</label>
              <input v-model="form.os_version" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g. 17.03.04a">
            </div>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-slate-700 mb-1">End of Life (EOL) Date</label>
              <input v-model="form.eol_date" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
          </div>
        </div>
        <div class="p-5 border-t border-slate-100 bg-slate-50 flex justify-end gap-3 rounded-b-xl">
          <button @click="showModal = false" class="px-4 py-2 text-slate-600 hover:text-slate-800 font-medium transition">Cancel</button>
          <button @click="saveDevice" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition shadow-sm">
            {{ isEditing ? 'Save Changes' : 'Add Device' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
