<script setup>
import { ref, onMounted } from 'vue'
import api from '../utils/api.js'

const totalDevices = ref(0)
const onlineDevices = ref(0)
const offlineDevices = ref(0)
const devices = ref([])

onMounted(async () => {
  try {
    const response = await api.get('/devices')
    const fetchedDevices = response.data
    totalDevices.value = fetchedDevices.length
    
    // Add reactivity properties
    devices.value = fetchedDevices.map(d => ({ ...d, status: 'checking' }))

    // Concurrently ping all devices
    devices.value.forEach(async (device) => {
      try {
        const pingResponse = await api.get(`/devices/${device.id}/ping`)
        device.status = pingResponse.data.status
        if (device.status === 'online') {
          onlineDevices.value++
        } else {
          offlineDevices.value++
        }
      } catch (err) {
        device.status = 'error'
        offlineDevices.value++ // Count errors as offline
      }
    })
  } catch (error) {
    console.error('Failed to fetch devices for dashboard:', error)
  }
})
</script>

<template>
  <div>
    <h2 class="text-2xl font-semibold mb-6 text-slate-800">ITSM Dashboard Overview</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
      <div class="bg-white rounded-lg shadow-sm border border-slate-100 p-5 border-t-4 border-t-blue-500 hover:shadow-md transition">
        <div class="text-slate-500 text-sm font-medium mb-1">Total Devices</div>
        <div class="text-3xl font-bold text-slate-800">{{ totalDevices }}</div>
      </div>
      <div class="bg-white rounded-lg shadow-sm border border-slate-100 p-5 border-t-4 border-t-green-500 hover:shadow-md transition relative overflow-hidden">
        <div class="text-slate-500 text-sm font-medium mb-1">Online</div>
        <div class="text-3xl font-bold text-slate-800 flex items-center gap-2">
          {{ onlineDevices }}
        </div>
      </div>
      <div class="bg-white rounded-lg shadow-sm border border-slate-100 p-5 border-t-4 border-t-red-500 hover:shadow-md transition text-slate-800 flex flex-col justify-between relative overflow-hidden">
        <div class="text-slate-500 text-sm font-medium mb-1">Offline</div>
        <div class="text-3xl font-bold text-slate-800 flex items-center gap-2">
          {{ offlineDevices }}
        </div>
      </div>
      <div class="bg-white rounded-lg shadow-sm border border-slate-100 p-5 border-t-4 border-t-amber-500 hover:shadow-md transition">
        <div class="text-slate-500 text-sm font-medium mb-1">Pending Changes</div>
        <div class="text-3xl font-bold text-slate-800">0</div>
      </div>
    </div>

    <!-- Pie Chart Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
      <div class="lg:col-span-1 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 class="text-lg font-semibold text-slate-800 mb-6">Device Status Distribution</h3>
        <div v-if="totalDevices > 0" class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-slate-700">Online</span>
            <span class="text-lg font-bold text-green-600">{{ onlineDevices }} ({{ ((onlineDevices / totalDevices) * 100).toFixed(0) }}%)</span>
          </div>
          <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
            <div class="bg-green-500 h-full" :style="{ width: `${(onlineDevices / totalDevices) * 100}%` }"></div>
          </div>
          
          <div class="flex items-center justify-between mt-4">
            <span class="text-sm font-medium text-slate-700">Offline</span>
            <span class="text-lg font-bold text-red-600">{{ offlineDevices }} ({{ ((offlineDevices / totalDevices) * 100).toFixed(0) }}%)</span>
          </div>
          <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
            <div class="bg-red-500 h-full" :style="{ width: `${(offlineDevices / totalDevices) * 100}%` }"></div>
          </div>
        </div>
        <div v-else class="p-6 text-center text-slate-500">
          <p class="text-sm">No devices yet</p>
        </div>
      </div>

      <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 class="text-lg font-semibold text-slate-800 mb-6">Status Summary</h3>
        <div class="space-y-4">
          <div class="flex items-center justify-between p-4 bg-green-50 rounded-lg border border-green-100">
            <div class="flex items-center gap-3">
              <div class="w-4 h-4 rounded-full bg-green-500"></div>
              <span class="font-medium text-slate-700">Online Devices</span>
            </div>
            <span class="text-2xl font-bold text-green-600">{{ onlineDevices }}</span>
          </div>
          <div class="flex items-center justify-between p-4 bg-red-50 rounded-lg border border-red-100">
            <div class="flex items-center gap-3">
              <div class="w-4 h-4 rounded-full bg-red-500"></div>
              <span class="font-medium text-slate-700">Offline Devices</span>
            </div>
            <span class="text-2xl font-bold text-red-600">{{ offlineDevices }}</span>
          </div>
          <div class="flex items-center justify-between p-4 bg-blue-50 rounded-lg border border-blue-100 mt-6">
            <span class="font-medium text-slate-700">Total Devices</span>
            <span class="text-2xl font-bold text-blue-600">{{ totalDevices }}</span>
          </div>
          <div v-if="totalDevices > 0" class="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200 mt-6">
            <span class="font-medium text-slate-700">Uptime Rate</span>
            <span class="text-2xl font-bold text-slate-600">{{ ((onlineDevices / totalDevices) * 100).toFixed(1) }}%</span>
          </div>
        </div>
      </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 flex-1 overflow-hidden flex flex-col mt-4">
      <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
        <h3 class="text-lg font-semibold text-slate-800">Live Network Telemetry</h3>
      </div>
      
      <div v-if="devices.length === 0" class="p-8 flex flex-col items-center justify-center min-h-[200px]">
        <svg class="w-16 h-16 text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
        <h3 class="text-lg font-medium text-slate-700">Network Telemetry is Empty</h3>
        <p class="text-slate-400 mt-2 text-center max-w-md">No devices have been added to the inventory yet. Head over to the Device Inventory to start building your network CMDB.</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 text-sm uppercase tracking-wider text-slate-500">
              <th class="p-4 font-semibold">Device</th>
              <th class="p-4 font-semibold">IP Address</th>
              <th class="p-4 font-semibold">Location</th>
              <th class="p-4 font-semibold">Live Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <tr v-for="device in devices" :key="device.id" class="hover:bg-slate-50 transition">
              <td class="p-4 font-medium text-slate-900 border-l-4" :class="device.status === 'online' ? 'border-l-green-500' : (device.status === 'offline' || device.status === 'error' ? 'border-l-red-500' : 'border-l-slate-300')">
                  {{ device.hostname }}
                  <div class="text-xs font-normal text-slate-500">{{ device.model || 'Unknown Model' }}</div>
              </td>
              <td class="p-4 font-mono text-sm text-slate-600">{{ device.ip_address }}</td>
              <td class="p-4 text-sm text-slate-600">
                {{ device.location || 'Unassigned' }}
              </td>
              <td class="p-4">
                <div class="flex items-center gap-2">
                  <!-- Checking Spinner -->
                  <svg v-if="device.status === 'checking'" class="animate-spin h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  
                  <!-- Online Dot -->
                  <div v-else-if="device.status === 'online'" class="w-2.5 h-2.5 rounded-full bg-green-500 animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.6)]"></div>
                  
                  <!-- Offline Dot -->
                  <div v-else class="w-2.5 h-2.5 rounded-full bg-red-500"></div>
                  
                  <span class="text-sm font-medium capitalize" :class="{
                      'text-slate-500': device.status === 'checking',
                      'text-green-600': device.status === 'online',
                      'text-red-600': device.status === 'offline' || device.status === 'error'
                  }">
                    {{ device.status }}
                  </span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
