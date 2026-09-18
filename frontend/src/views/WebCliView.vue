<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../utils/api.js'

const route = useRoute()

const devices = ref([])
const devicesLoading = ref(true)
const deviceSearch = ref('')
const tabs = ref([])
const activeTabId = ref(null)
const hiddenInputRef = ref(null)

const tabPollers = new Map()
const sendTimers = new Map()
const outputContainerRefs = new Map()
const outputContainerCallbacks = new Map()

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

const scrollToBottom = (tabId) => {
  const el = outputContainerRefs.get(tabId)
  if (el) {
    el.scrollTop = el.scrollHeight
  }
}

const writeLine = (tabId, line = '') => {
  const tab = findTab(tabId)
  if (!tab) {
    return
  }

  tab.output += line + '\n'
  nextTick(() => scrollToBottom(tabId))
}

// Router-sent bytes include real terminal control characters (the router echoes back
// Backspace/DEL as you type, "--More--" paging uses bare \r to overwrite a line, etc).
// A plain string append can't represent "erase the previous character" or "return to
// column 0" — so those control bytes have to be interpreted here rather than just appended.
const appendTerminalText = (tab, text) => {
  const normalized = text.replace(/\r\n/g, '\n')

  for (const ch of normalized) {
    if (ch === '\x08' || ch === '\x7f') {
      const lastChar = tab.output[tab.output.length - 1]
      if (lastChar && lastChar !== '\n') {
        tab.output = tab.output.slice(0, -1)
      }
      continue
    }

    if (ch === '\r') {
      // Bare CR (no following \n): move to column 0, i.e. erase back to the last newline.
      const lastNewline = tab.output.lastIndexOf('\n')
      tab.output = lastNewline === -1 ? '' : tab.output.slice(0, lastNewline + 1)
      continue
    }

    tab.output += ch
  }
}

const writeOutput = (tabId, text = '') => {
  if (!text) {
    return
  }

  const tab = findTab(tabId)
  if (!tab) {
    return
  }

  appendTerminalText(tab, text)
  nextTick(() => scrollToBottom(tabId))
}

const focusInput = () => {
  hiddenInputRef.value?.focus()
}

// Memoized per tab so the scroll-container ref binding stays a stable function identity
// across re-renders instead of a fresh inline closure every time.
const setOutputRef = (tabId) => {
  if (!outputContainerCallbacks.has(tabId)) {
    outputContainerCallbacks.set(tabId, (el) => {
      if (el) {
        outputContainerRefs.set(tabId, el)
        return
      }

      outputContainerRefs.delete(tabId)
    })
  }

  return outputContainerCallbacks.get(tabId)
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
  output: '',
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

// Regular character input (handles composition/IME correctly via the native input event).
const handleInput = (event) => {
  const data = event.target.value
  event.target.value = ''

  if (data && activeTabId.value) {
    queueTerminalInput(activeTabId.value, data)
  }
}

// Control keys that don't fire a useful "input" event.
const handleKeydown = (event) => {
  if (!activeTabId.value) {
    return
  }

  const keyMap = {
    Enter: '\r',
    // Cisco IOS's line editor reliably recognizes BS (\x08); DEL (\x7f) is inconsistent
    // across IOS versions and can show up as a literal "^H" instead of erasing.
    Backspace: '\x08',
    Tab: '\t',
    ArrowUp: '\x1b[A',
    ArrowDown: '\x1b[B',
    ArrowRight: '\x1b[C',
    ArrowLeft: '\x1b[D',
    Escape: '\x1b',
  }

  if (event.ctrlKey && event.key.length === 1) {
    const code = event.key.toUpperCase().charCodeAt(0) - 64
    if (code >= 0 && code <= 31) {
      event.preventDefault()
      queueTerminalInput(activeTabId.value, String.fromCharCode(code))
      return
    }
  }

  if (keyMap[event.key]) {
    event.preventDefault()
    queueTerminalInput(activeTabId.value, keyMap[event.key])
  }
}

const initTabOutput = (tabId) => {
  const tab = findTab(tabId)
  if (!tab) {
    return
  }

  writeLine(tabId, 'ITSM Web CLI Workspace')
  writeLine(tabId, `Device selected: ${tab.device.hostname} (${tab.device.ip_address})`)
  writeLine(tabId, 'Click Connect Telnet to open the router session.')
  writeLine(tabId, 'After that, use this terminal exactly like Putty. The router controls login prompts and password masking.')
  writeLine(tabId, '')
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
      scrollToBottom(existingTab.id)
      focusInput()
    })
    return
  }

  const tab = createTab(device)
  tabs.value.push(tab)
  activeTabId.value = tab.id
  initTabOutput(tab.id)

  nextTick(() => {
    scrollToBottom(tab.id)
    focusInput()
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
  outputContainerRefs.delete(tabId)
  outputContainerCallbacks.delete(tabId)
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
    focusInput()
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

watch(() => route.query.deviceId, () => {
  ensureRouteDeviceTab()
})

watch(activeTabId, async (tabId) => {
  if (!tabId) {
    return
  }

  await nextTick()
  scrollToBottom(tabId)
  focusInput()
})

onMounted(() => {
  fetchDevices()
})

onUnmounted(() => {
  tabs.value.forEach(tab => {
    disconnectTab(tab, false)
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

          <div class="relative flex-1 overflow-hidden bg-slate-950 p-4">
            <div
              v-for="tab in tabs"
              :key="`terminal-${tab.id}`"
              v-show="activeTabId === tab.id"
              :ref="setOutputRef(tab.id)"
              class="h-full w-full cursor-text overflow-auto whitespace-pre-wrap break-words rounded-2xl border border-slate-800 bg-slate-950 p-3 font-mono text-sm leading-relaxed text-emerald-200"
              @click="focusInput"
            >{{ tab.output }}<span v-if="tab.isConnected && activeTabId === tab.id" class="inline-block h-4 w-2 animate-pulse bg-cyan-300 align-text-bottom"></span></div>

            <textarea
              ref="hiddenInputRef"
              class="absolute h-px w-px opacity-0"
              style="left: -9999px;"
              autocomplete="off"
              autocapitalize="off"
              autocorrect="off"
              spellcheck="false"
              @keydown="handleKeydown"
              @input="handleInput"
            ></textarea>
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
