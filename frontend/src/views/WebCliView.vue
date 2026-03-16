<script setup>
import { FitAddon } from '@xterm/addon-fit'
import { Terminal } from '@xterm/xterm'
import '@xterm/xterm/css/xterm.css'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../utils/api.js'

const route = useRoute()

const devices = ref([])
const devicesLoading = ref(true)
const deviceSearch = ref('')
const tabs = ref([])
const activeTabId = ref(null)
const terminalMountRefs = ref({})

const tabPollers = new Map()
const terminalInstances = new Map()
const fitAddons = new Map()
const terminalDisposables = new Map()
const sendTimers = new Map()

const filteredDevices = computed(() => {
  const keyword = deviceSearch.value.trim().toLowerCase()

  if (!keyword) {
    return devices.value
  }

  return devices.value.filter(device => {
    const haystack = [device.hostname, device.ip_address, device.location, device.model]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return haystack.includes(keyword)
  })
})

const activeTab = computed(() => tabs.value.find(tab => tab.id === activeTabId.value) || null)

const writeLine = (tabId, line = '') => {
  const terminal = terminalInstances.get(tabId)
  if (!terminal) {
    return
  }

  terminal.writeln(line)
}

const writeOutput = (tabId, text = '') => {
  if (!text) {
    return
  }

  const terminal = terminalInstances.get(tabId)
  if (!terminal) {
    return
  }

  terminal.write(text.replace(/\r?\n/g, '\r\n'))
}

const fitTerminal = (tabId) => {
  const fitAddon = fitAddons.get(tabId)
  if (fitAddon) {
    fitAddon.fit()
  }
}

const focusTerminal = (tabId) => {
  const terminal = terminalInstances.get(tabId)
  if (terminal) {
    terminal.focus()
  }
}

const createTab = (device) => ({
  id: `device-${device.id}`,
  device,
  port: 23,
  sessionId: null,
  isLoading: false,
  isConnected: false,
  isWriting: false,
  pendingInput: '',
})

const findTab = (tabId) => tabs.value.find(tab => tab.id === tabId)

const flushPendingInput = async (tab) => {
  if (!tab.sessionId || !tab.pendingInput || tab.isWriting) {
    return
  }

  const pending = tab.pendingInput
  tab.pendingInput = ''
  tab.isWriting = true

  const timer = sendTimers.get(tab.id)
  if (timer) {
    clearTimeout(timer)
    sendTimers.delete(tab.id)
  }

  try {
    const response = await api.post(`/devices/${tab.device.id}/telnet/write`, {
      session_id: tab.sessionId,
      data: pending,
    })

    if (response.data.output) {
      writeOutput(tab.id, response.data.output)
    }

    if (response.data.closed) {
      tab.isConnected = false
      tab.sessionId = null
      stopPolling(tab.id)
      writeLine(tab.id, '')
      writeLine(tab.id, '[SESSION CLOSED] Router closed the telnet session.')
    }
  } catch (error) {
    writeLine(tab.id, '')
    writeLine(tab.id, '[ERROR] Failed to send telnet input.')
    if (error.response?.data?.message) {
      writeLine(tab.id, error.response.data.message)
    }
  } finally {
    tab.isWriting = false
    if (tab.pendingInput) {
      flushPendingInput(tab)
    }
  }
}

const queueTerminalInput = (tabId, data) => {
  const tab = findTab(tabId)
  if (!tab) {
    return
  }

  if (!tab.isConnected || !tab.sessionId) {
    if (data === '\r') {
      writeLine(tabId, '')
      writeLine(tabId, '[INFO] Connect telnet before typing.')
    }
    return
  }

  const normalizedData = data.replace(/\r/g, '\r\n')
  tab.pendingInput += normalizedData

  if (normalizedData.includes('\r') || normalizedData.includes('\n')) {
    flushPendingInput(tab)
    return
  }

  if (sendTimers.has(tabId)) {
    return
  }

  sendTimers.set(tabId, setTimeout(() => {
    sendTimers.delete(tabId)
    flushPendingInput(tab)
  }, 35))
}

const ensureTerminal = async (tabId) => {
  const tab = findTab(tabId)
  const mountElement = terminalMountRefs.value[tabId]

  if (!tab || !mountElement || terminalInstances.has(tabId)) {
    return
  }

  await nextTick()

  const terminal = new Terminal({
    convertEol: true,
    cursorBlink: true,
    fontFamily: 'Consolas, "Courier New", monospace',
    fontSize: 14,
    scrollback: 5000,
    theme: {
      background: '#020617',
      foreground: '#b6f0d0',
      cursor: '#67e8f9',
      black: '#020617',
      green: '#86efac',
      brightGreen: '#bbf7d0',
      yellow: '#fde68a',
      brightYellow: '#fef08a',
      red: '#fca5a5',
      brightRed: '#fecaca',
      blue: '#93c5fd',
      brightBlue: '#bfdbfe',
    },
  })

  const fitAddon = new FitAddon()
  terminal.loadAddon(fitAddon)
  terminal.open(mountElement)

  terminalInstances.set(tabId, terminal)
  fitAddons.set(tabId, fitAddon)
  terminalDisposables.set(tabId, terminal.onData(data => queueTerminalInput(tabId, data)))

  fitTerminal(tabId)
  writeLine(tabId, 'ITSM Web CLI Workspace')
  writeLine(tabId, `Device selected: ${tab.device.hostname} (${tab.device.ip_address})`)
  writeLine(tabId, 'Click Connect Telnet to open the router session.')
  writeLine(tabId, 'After that, use this terminal exactly like Putty. The router controls login prompts and password masking.')
  writeLine(tabId, '')

  if (activeTabId.value === tabId) {
    focusTerminal(tabId)
  }
}

const disposeTerminal = (tabId) => {
  const timer = sendTimers.get(tabId)
  if (timer) {
    clearTimeout(timer)
    sendTimers.delete(tabId)
  }

  const disposable = terminalDisposables.get(tabId)
  if (disposable) {
    disposable.dispose()
    terminalDisposables.delete(tabId)
  }

  const terminal = terminalInstances.get(tabId)
  if (terminal) {
    terminal.dispose()
    terminalInstances.delete(tabId)
  }

  fitAddons.delete(tabId)
}

const setTerminalRef = (tabId) => (el) => {
  if (el) {
    terminalMountRefs.value[tabId] = el
    ensureTerminal(tabId)
    return
  }

  delete terminalMountRefs.value[tabId]
}

const fetchDevices = async () => {
  devicesLoading.value = true

  try {
    const response = await api.get('/devices')
    devices.value = response.data
    ensureRouteDeviceTab()
  } catch (error) {
    devices.value = []
  } finally {
    devicesLoading.value = false
  }
}

const openDeviceTab = (device) => {
  const existingTab = tabs.value.find(tab => tab.device.id === device.id)

  if (existingTab) {
    activeTabId.value = existingTab.id
    nextTick(() => {
      fitTerminal(existingTab.id)
      focusTerminal(existingTab.id)
    })
    return
  }

  const tab = createTab(device)
  tabs.value.push(tab)
  activeTabId.value = tab.id

  nextTick(() => {
    ensureTerminal(tab.id)
    fitTerminal(tab.id)
    focusTerminal(tab.id)
  })
}

const stopPolling = (tabId) => {
  const poller = tabPollers.get(tabId)
  if (poller) {
    clearInterval(poller)
    tabPollers.delete(tabId)
  }
}

const disconnectTab = async (tab, appendMessage = true) => {
  stopPolling(tab.id)

  const timer = sendTimers.get(tab.id)
  if (timer) {
    clearTimeout(timer)
    sendTimers.delete(tab.id)
  }

  const pendingSessionId = tab.sessionId
  tab.sessionId = null
  tab.isConnected = false
  tab.pendingInput = ''

  if (pendingSessionId) {
    try {
      await api.post(`/devices/${tab.device.id}/telnet/close`, {
        session_id: pendingSessionId,
      })
    } catch (error) {
      // Session may already be closed.
    }
  }

  if (appendMessage) {
    writeLine(tab.id, '')
    writeLine(tab.id, '[DISCONNECTED] Telnet session closed.')
  }
}

const closeTab = (tabId) => {
  const index = tabs.value.findIndex(tab => tab.id === tabId)
  if (index === -1) {
    return
  }

  disconnectTab(tabs.value[index], false)
  disposeTerminal(tabId)
  tabs.value.splice(index, 1)

  if (activeTabId.value === tabId) {
    activeTabId.value = tabs.value[index]?.id || tabs.value[index - 1]?.id || null
  }
}

const pollOutput = async (tab) => {
  if (!tab.sessionId) {
    return
  }

  try {
    const response = await api.post(`/devices/${tab.device.id}/telnet/read`, {
      session_id: tab.sessionId,
    })

    if (response.data.output) {
      writeOutput(tab.id, response.data.output)
    }

    if (response.data.closed) {
      tab.isConnected = false
      tab.sessionId = null
      stopPolling(tab.id)
      writeLine(tab.id, '')
      writeLine(tab.id, '[SESSION CLOSED] Router closed the telnet session.')
    }
  } catch (error) {
    tab.isConnected = false
    tab.sessionId = null
    stopPolling(tab.id)
    writeLine(tab.id, '')
    writeLine(tab.id, '[ERROR] Lost telnet session.')
  }
}

const startPolling = (tab) => {
  stopPolling(tab.id)
  tabPollers.set(tab.id, setInterval(() => {
    pollOutput(tab)
  }, 300))
}

const connectTab = async (tab) => {
  if (tab.isLoading || tab.isConnected) {
    return
  }

  tab.isLoading = true

  try {
    const response = await api.post(`/devices/${tab.device.id}/telnet/connect`, {
      port: tab.port,
    })

    tab.sessionId = response.data.session_id
    tab.isConnected = true
    writeLine(tab.id, `[CONNECTED] Telnet ${tab.device.ip_address}:${tab.port}`)

    if (response.data.output) {
      writeOutput(tab.id, response.data.output)
    }

    startPolling(tab)
    focusTerminal(tab.id)
  } catch (error) {
    writeLine(tab.id, '[ERROR] Failed to open telnet session.')
    if (error.response?.data?.message) {
      writeLine(tab.id, error.response.data.message)
    }
    if (error.response?.data?.details?.detail) {
      writeLine(tab.id, error.response.data.details.detail)
    }
  } finally {
    tab.isLoading = false
  }
}

const ensureRouteDeviceTab = () => {
  if (!route.query.deviceId || !devices.value.length) {
    return
  }

  const matchedDevice = devices.value.find(device => String(device.id) === String(route.query.deviceId))
  if (matchedDevice) {
    openDeviceTab(matchedDevice)
  }
}

const fitActiveTerminal = () => {
  if (!activeTabId.value) {
    return
  }

  fitTerminal(activeTabId.value)
}

watch(() => route.query.deviceId, () => {
  ensureRouteDeviceTab()
})

watch(activeTabId, async (tabId) => {
  if (!tabId) {
    return
  }

  await nextTick()
  ensureTerminal(tabId)
  fitTerminal(tabId)
  focusTerminal(tabId)
})

onMounted(() => {
  fetchDevices()
  window.addEventListener('resize', fitActiveTerminal)
})

onUnmounted(() => {
  window.removeEventListener('resize', fitActiveTerminal)
  tabs.value.forEach(tab => {
    disconnectTab(tab, false)
    disposeTerminal(tab.id)
  })
})
</script>

<template>
  <div class="h-full flex flex-col gap-5 p-4 lg:p-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <h2 class="text-2xl font-semibold text-slate-800">Web CLI Workspace</h2>
        <p class="text-sm text-slate-500">Open multiple device tabs, search the inventory, and let each engineer enter credentials directly in the session.</p>
      </div>
      <router-link to="/inventory" class="text-sm font-medium text-blue-600 hover:text-blue-800 transition">&larr; Back to Inventory</router-link>
    </div>

    <div class="flex min-h-[720px] flex-1 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <aside class="flex w-full max-w-sm flex-col border-b border-slate-200 bg-slate-50 lg:border-b-0 lg:border-r">
        <div class="border-b border-slate-200 p-4">
          <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Device Explorer</p>
          <div class="relative mt-3">
            <input
              v-model="deviceSearch"
              type="text"
              class="w-full rounded-2xl border border-slate-200 bg-white py-2.5 pl-10 pr-4 text-sm text-slate-700 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
              placeholder="Search hostname, IP, model, location"
            >
            <svg class="absolute left-3 top-3 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
          </div>
        </div>

        <div class="flex-1 overflow-y-auto p-3">
          <div v-if="devicesLoading" class="rounded-2xl border border-dashed border-slate-200 bg-white p-4 text-sm text-slate-400">
            Loading device inventory...
          </div>

          <div v-else-if="filteredDevices.length === 0" class="rounded-2xl border border-dashed border-slate-200 bg-white p-4 text-sm text-slate-400">
            No devices matched the current search.
          </div>

          <button
            v-for="device in filteredDevices"
            :key="device.id"
            type="button"
            class="mb-3 w-full rounded-2xl border px-4 py-3 text-left transition"
            :class="activeTab?.device.id === device.id ? 'border-blue-500 bg-blue-50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-100'"
            @click="openDeviceTab(device)"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="font-semibold text-slate-800">{{ device.hostname }}</p>
                <p class="mt-1 font-mono text-xs text-slate-500">{{ device.ip_address }}</p>
              </div>
              <span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ device.device_type }}</span>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
              <span>{{ device.location || 'No location' }}</span>
              <span>{{ device.model || 'Model n/a' }}</span>
            </div>
          </button>
        </div>
      </aside>

      <section class="flex flex-1 flex-col overflow-hidden bg-slate-950">
        <div class="border-b border-slate-800 bg-slate-900 px-3 py-3">
          <div v-if="tabs.length" class="flex gap-2 overflow-x-auto pb-1">
            <div
              v-for="tab in tabs"
              :key="tab.id"
              class="flex min-w-[220px] items-center justify-between gap-3 rounded-2xl border px-4 py-3 text-left transition"
              :class="activeTabId === tab.id ? 'border-cyan-400 bg-slate-950 text-white' : 'border-slate-700 bg-slate-800 text-slate-300 hover:border-slate-600'"
            >
              <button type="button" class="min-w-0 flex-1 text-left" @click="activeTabId = tab.id">
                <p class="truncate text-sm font-semibold">{{ tab.device.hostname }}</p>
                <p class="truncate font-mono text-xs text-slate-400">{{ tab.device.ip_address }}</p>
              </button>
              <span class="flex items-center gap-2">
                <span v-if="tab.isLoading" class="h-2.5 w-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                <button type="button" class="rounded-full p-1 text-slate-400 hover:bg-slate-700 hover:text-white" @click.stop="closeTab(tab.id)">
                  <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
              </span>
            </div>
          </div>
          <div v-else class="rounded-2xl border border-dashed border-slate-700 px-4 py-6 text-sm text-slate-400">
            Choose a device from the left pane to open the first CLI tab.
          </div>
        </div>

        <div v-if="activeTab" class="flex flex-1 flex-col overflow-hidden">
          <div class="grid gap-4 border-b border-slate-800 bg-slate-900/80 px-4 py-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div>
              <div class="flex flex-wrap items-center gap-3">
                <h3 class="text-lg font-semibold text-white">{{ activeTab.device.hostname }}</h3>
                <span class="rounded-full border border-slate-700 px-2.5 py-1 font-mono text-xs text-slate-300">{{ activeTab.device.ip_address }}</span>
                <span class="rounded-full border border-slate-700 px-2.5 py-1 text-xs text-slate-400">{{ activeTab.device.device_type }}</span>
              </div>
              <p class="mt-2 text-sm text-slate-400">This tab opens a raw telnet session only. The terminal behaves like Putty: the router controls prompts, echo, and hidden password entry.</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[120px_auto_auto] lg:items-end">
              <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Port</label>
                <input v-model.number="activeTab.port" type="number" min="1" max="65535" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500/20">
              </div>
              <button v-if="!activeTab.isConnected" type="button" class="rounded-xl bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-400" @click="connectTab(activeTab)">Connect Telnet</button>
              <button v-else type="button" class="rounded-xl bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-400" @click="disconnectTab(activeTab)">Disconnect</button>
              <div class="text-right text-xs font-medium uppercase tracking-wide" :class="activeTab.isConnected ? 'text-emerald-300' : 'text-slate-500'">
                {{ activeTab.isConnected ? 'Connected' : 'Disconnected' }}
              </div>
            </div>
          </div>

          <div class="flex-1 overflow-hidden bg-slate-950 p-4">
            <div
              v-for="tab in tabs"
              :key="`terminal-${tab.id}`"
              v-show="activeTabId === tab.id"
              :ref="setTerminalRef(tab.id)"
              class="h-full w-full overflow-hidden rounded-2xl border border-slate-800 bg-slate-950 p-2"
            ></div>
          </div>
        </div>

        <div v-else class="flex flex-1 items-center justify-center bg-[radial-gradient(circle_at_top,_rgba(34,211,238,0.12),_transparent_35%),linear-gradient(180deg,_#020617,_#0f172a)] px-8 text-center">
          <div class="max-w-md">
            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-cyan-300">Operator Workspace</p>
            <h3 class="mt-3 text-3xl font-semibold text-white">Open multiple device sessions without leaving the page.</h3>
            <p class="mt-3 text-sm leading-6 text-slate-400">Use the searchable inventory on the left to open tabs for routers, switches, or firewalls. Each tab has its own engineer-entered credentials and command history.</p>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
