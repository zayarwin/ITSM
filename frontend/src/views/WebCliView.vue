<script setup>
import { ref, onMounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import api from '../utils/api.js'

const route = useRoute()
const deviceId = route.query.deviceId
const device = ref(null)

const terminalOutput = ref([
  'ITSM Web CLI Engine v1.0',
  'Initializing connection...',
])
const currentCommand = ref('')
const isLoading = ref(false)
const terminalContainer = ref(null)

const scrollToBottom = async () => {
  await nextTick()
  if (terminalContainer.value) {
    terminalContainer.value.scrollTop = terminalContainer.value.scrollHeight
  }
}

const loadDevice = async () => {
  try {
    const response = await api.get(`/devices/${deviceId}`)
    device.value = response.data
    terminalOutput.value.push(`Target device: ${device.value.hostname} (${device.value.ip_address})`)
    terminalOutput.value.push(`Ready for commands.`)
    scrollToBottom()
  } catch (error) {
    terminalOutput.value.push(`[ERROR] Failed to load device details.`)
  }
}

const executeCommand = async () => {
  if (!currentCommand.value.trim() || isLoading.value || !device.value) return

  const cmd = currentCommand.value
  terminalOutput.value.push(`\n${device.value.hostname}# ${cmd}`)
  currentCommand.value = ''
  isLoading.value = true
  scrollToBottom()

  try {
    const response = await api.post(`/devices/${deviceId}/run-command`, {
      command: cmd
    })
    
    // Netmiko output usually has a lot of newlines, let's just push it as one block, or line by line
    if (response.data.output) {
      terminalOutput.value.push(response.data.output)
    } else {
      terminalOutput.value.push('[NO OUTPUT RETURNED]')
    }
  } catch (error) {
    terminalOutput.value.push(`[ERROR] CLI Execution Failed`)
    if (error.response && error.response.data && error.response.data.message) {
      terminalOutput.value.push(error.response.data.message)
    }
    if (error.response && error.response.data && error.response.data.details) {
      terminalOutput.value.push(JSON.stringify(error.response.data.details))
    }
  } finally {
    isLoading.value = false
    scrollToBottom()
  }
}

onMounted(() => {
  if (deviceId) {
    loadDevice()
  } else {
    terminalOutput.value.push(`[ERROR] No device ID provided in URL.`)
  }
})
</script>

<template>
  <div class="h-full flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-5xl mb-4 flex justify-between items-end text-slate-700">
      <div>
        <h2 class="text-2xl font-semibold">Web CLI</h2>
        <p class="text-sm text-slate-500" v-if="device">Connected to: <span class="font-medium text-slate-800">{{ device.hostname }}</span></p>
      </div>
      <router-link to="/inventory" class="text-sm font-medium text-blue-600 hover:text-blue-800 transition">&larr; Back to Inventory</router-link>
    </div>

    <div class="bg-slate-900 rounded-lg shadow-xl w-full max-w-5xl h-[600px] flex flex-col overflow-hidden border border-slate-700">
      <div class="bg-slate-800 p-3 border-b border-slate-700 flex justify-between items-center text-slate-300 font-mono text-sm">
        <div class="flex gap-2 items-center">
          <div class="w-3 h-3 rounded-full bg-red-500"></div>
          <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
          <div class="w-3 h-3 rounded-full bg-green-500"></div>
          <span class="ml-4 font-semibold text-slate-400">
            {{ device ? `ssh ${device.username}@${device.ip_address}` : 'Terminal - Not Connected' }}
          </span>
        </div>
        <div v-if="isLoading" class="flex items-center gap-2 text-yellow-400 text-xs">
          <svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
          Executing...
        </div>
      </div>
      
      <div ref="terminalContainer" class="flex-1 p-4 font-mono text-emerald-400 overflow-y-auto whitespace-pre-wrap text-[15px] leading-relaxed select-text">
        <div v-for="(line, index) in terminalOutput" :key="index">{{ line }}</div>
        
        <form @submit.prevent="executeCommand" class="flex mt-2 items-center" v-if="device && !isLoading">
          <span class="mr-2 text-emerald-300">{{ device.hostname }}#</span>
          <input 
            v-model="currentCommand" 
            type="text" 
            class="flex-1 bg-transparent border-none outline-none text-emerald-400 font-mono focus:ring-0 p-0 shadow-none z-10 w-full" 
            autofocus 
            autocomplete="off"
            spellcheck="false"
          >
        </form>
        <span v-if="isLoading" class="animate-pulse opacity-50 block mt-2">_</span>
      </div>
    </div>
  </div>
</template>
