<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../utils/api.js'

const devices = ref([])
const loading = ref(true)
const showModal = ref(false)
const isEditing = ref(false)

const searchQuery = ref('')
const searchColumn = ref('hostname')
const searchColumns = [
  { value: 'hostname', label: 'Hostname' },
  { value: 'ip_address', label: 'IP Address' },
  { value: 'model', label: 'Model' },
  { value: 'os_version', label: 'OS Version' },
  { value: 'location', label: 'Location' }
]

const filteredDevices = computed(() => {
  if (!searchQuery.value.trim()) return devices.value

  const keywords = searchQuery.value.trim().toLowerCase().split(/\s+/)

  return devices.value.filter(device => {
    const fieldValue = String(device[searchColumn.value] || '').toLowerCase()
    return keywords.some(keyword => fieldValue.includes(keyword))
  })
})

const form = ref({
  id: null,
  hostname: '',
  ip_address: '',
  os_version: '',
  location: '',
  eol_date: '',
  model: '',
  username: '',
  password: '',
  device_type: 'cisco_ios'
})

const fetchDevices = async () => {
  loading.value = true
  try {
    const response = await api.get('/devices')
    devices.value = response.data.map(d => ({ ...d, status: 'checking' }))
    
    // Concurrently ping all devices
    devices.value.forEach(async (device) => {
      try {
        const pingResponse = await api.get(`/devices/${device.id}/ping`)
        device.status = pingResponse.data.status
      } catch (err) {
        device.status = 'error'
      }
    })
  } catch (error) {
    console.error('Failed to load devices:', error)
  } finally {
    loading.value = false
  }
}

const openAddModal = () => {
  isEditing.value = false
  form.value = { id: null, hostname: '', ip_address: '', os_version: '', location: '', eol_date: '', model: '', username: '', password: '', device_type: 'cisco_ios' }
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
    alert(error.response?.data?.message || 'Error saving device configuration.')
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

    <!-- Search Bar -->
    <div class="mb-6 flex flex-col sm:flex-row gap-4 bg-white p-5 rounded-xl shadow-sm border border-slate-200 items-end">
      <div class="w-full sm:w-1/4">
        <label class="block text-sm font-medium text-slate-700 mb-1">Search Field</label>
        <select v-model="searchColumn" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white shadow-sm">
          <option v-for="col in searchColumns" :key="col.value" :value="col.value">{{ col.label }}</option>
        </select>
      </div>
      <div class="w-full sm:w-3/4">
        <label class="block text-sm font-medium text-slate-700 mb-1">Keywords (OR condition, space-separated)</label>
        <div class="relative">
          <input v-model="searchQuery" type="text" class="w-full border border-slate-200 rounded-lg pl-10 pr-3 py-2.5 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm" placeholder="e.g. router switch">
          <svg class="w-5 h-5 absolute left-3 top-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
        </div>
      </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 flex-1 overflow-hidden flex flex-col">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 text-sm uppercase tracking-wider text-slate-500">
              <th class="p-4 font-semibold">Hostname</th>
              <th class="p-4 font-semibold">IP Address</th>
              <th class="p-4 font-semibold">Model</th>
              <th class="p-4 font-semibold">OS Version</th>
              <th class="p-4 font-semibold">Location</th>
              <th class="p-4 font-semibold">Status</th>
              <th class="p-4 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <tr v-if="loading">
              <td colspan="7" class="p-8 text-center text-slate-400">Loading devices...</td>
            </tr>
            <tr v-else-if="devices.length === 0">
              <td colspan="7" class="p-8 text-center text-slate-400">No devices found. Add one to get started.</td>
            </tr>
            <tr v-else-if="filteredDevices.length === 0">
              <td colspan="7" class="p-8 text-center text-slate-400">No devices match your search criteria.</td>
            </tr>
            <tr v-else v-for="device in filteredDevices" :key="device.id" class="hover:bg-slate-50 transition">
              <td class="p-4 font-medium text-slate-900">{{ device.hostname }}</td>
              <td class="p-4"><span class="bg-blue-50 text-blue-700 font-mono text-sm px-2 py-1 rounded">{{ device.ip_address }}</span></td>
              <td class="p-4">
                <span>{{ device.model || 'Unknown' }}</span>
              </td>
              <td class="p-4">
                <span class="text-slate-600">{{ device.os_version || 'N/A' }}</span>
              </td>
              <td class="p-4">
                <span class="inline-flex items-center gap-1">
                  <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                  {{ device.location || 'Unassigned' }}
                </span>
              </td>
              <td class="p-4">
                <div class="flex items-center gap-2">
                  <svg v-if="device.status === 'checking'" class="animate-spin h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                  <div v-else-if="device.status === 'online'" class="w-2.5 h-2.5 rounded-full bg-green-500 animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.6)]"></div>
                  <div v-else class="w-2.5 h-2.5 rounded-full bg-red-500"></div>
                  <span class="text-sm font-medium capitalize" :class="{'text-slate-500': device.status === 'checking', 'text-green-600': device.status === 'online', 'text-red-600': device.status === 'offline' || device.status === 'error'}">{{ device.status }}</span>
                </div>
              </td>
              <td class="p-4 text-right flex justify-end gap-2 text-sm">
                <router-link :to="`/cli?deviceId=${device.id}`" class="text-emerald-600 hover:text-emerald-800 p-2 hover:bg-emerald-50 rounded transition flex items-center font-medium">
                  Connect CLI
                </router-link>
                <button @click="openEditModal(device)" class="text-blue-600 hover:text-blue-800 p-2 hover:bg-blue-50 rounded transition">Edit</button>
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
            
            <div class="col-span-2 border-t border-slate-100 pt-3 mt-1">
              <h4 class="text-sm font-semibold text-slate-800 mb-2">SSH Connection Credentials</h4>
            </div>
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
              <input v-model="form.username" type="text" autocomplete="off" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="admin">
            </div>
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
              <input v-model="form.password" type="password" autocomplete="new-password" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" :placeholder="isEditing ? 'Leave blank to keep current' : '••••••••'">
            </div>
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-700 mb-1">Device Type (Netmiko) <span class="text-red-500">*</span></label>
              <select v-model="form.device_type" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
                <option value="cisco_ios">Cisco IOS</option>
                <option value="cisco_nxos">Cisco NX-OS</option>
                <option value="cisco_xr">Cisco IOS-XR</option>
                <option value="arista_eos">Arista EOS</option>
                <option value="juniper_junos">Juniper JunOS</option>
                <option value="linux">Linux SSH</option>
              </select>
            </div>
            
            <div class="col-span-2 sm:col-span-1 border-t border-slate-100 pt-3 mt-1 sm:border-t-0 sm:pt-0 sm:mt-0">
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
