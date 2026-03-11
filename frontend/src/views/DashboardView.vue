<script setup>
import { ref } from 'vue'

const totalDevices = ref(0)
const onlineDevices = ref(0)
const offlineDevices = ref(0)
</script>

<template>
  <div>
    <h2 class="text-2xl font-semibold mb-6 text-slate-800">ITSM Dashboard Overview</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
      <div class="bg-white rounded-lg shadow-sm border border-slate-100 p-5 border-t-4 border-t-blue-500 hover:shadow-md transition">
        <div class="text-slate-500 text-sm font-medium mb-1">Total Devices</div>
        <div class="text-3xl font-bold text-slate-800">{{ totalDevices }}</div>
      </div>
      <div class="bg-white rounded-lg shadow-sm border border-slate-100 p-5 border-t-4 border-t-green-500 hover:shadow-md transition">
        <div class="text-slate-500 text-sm font-medium mb-1">Online</div>
        <div class="text-3xl font-bold text-slate-800">{{ onlineDevices }}</div>
      </div>
      <div class="bg-white rounded-lg shadow-sm border border-slate-100 p-5 border-t-4 border-t-red-500 hover:shadow-md transition">
        <div class="text-slate-500 text-sm font-medium mb-1">Offline</div>
        <div class="text-3xl font-bold text-slate-800">{{ offlineDevices }}</div>
      </div>
      <div class="bg-white rounded-lg shadow-sm border border-slate-100 p-5 border-t-4 border-t-amber-500 hover:shadow-md transition">
        <div class="text-slate-500 text-sm font-medium mb-1">Pending Changes</div>
        <div class="text-3xl font-bold text-slate-800">0</div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-8 flex flex-col items-center justify-center min-h-[300px]">
      <svg class="w-16 h-16 text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
      <h3 class="text-lg font-medium text-slate-700">Network Telemetry is Empty</h3>
      <p class="text-slate-400 mt-2 text-center max-w-md">No devices have been added to the inventory yet. Head over to the Device Inventory to start building your network CMDB.</p>
    </div>
  </div>
</template>
