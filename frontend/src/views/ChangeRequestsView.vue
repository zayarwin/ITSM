<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../utils/api.js'

const changeRequests = ref([])
const devices = ref([])
const users = ref([])
const loading = ref(true)

// Main List search
const searchQuery = ref('')

// 'list', 'create', or 'view'
const viewMode = ref('list')
const selectedCR = ref(null)
const approvalComment = ref('')
const isApprovingReject = ref(false)

const currentUser = ref(JSON.parse(localStorage.getItem('user')) || null)

const form = ref({
  purpose: '',
  task: '',
  implementer_id: '',
  reviewer_id: '',
  device_ids: [],
  start_time: '',
  end_time: '',
})
const attachmentFile = ref(null)

// Search State for Devices
const deviceSearchQuery = ref('')
const intermediateSelectedDevices = ref([]) // Holds IDs checked in the search table before clicking 'Add'

// Devices already added to the CRQ (Full objects for display)
const stagedDevices = computed(() => {
    return devices.value.filter(d => form.value.device_ids.includes(d.id))
})

const handleFileUpload = (event) => {
  attachmentFile.value = event.target.files[0]
}

const calculatedDuration = computed(() => {
  if (form.value.start_time && form.value.end_time) {
    const start = new Date(form.value.start_time)
    const end = new Date(form.value.end_time)
    const diffMs = end - start
    
    if (diffMs < 0) return 'Invalid: End before Start'
    
    const diffMins = Math.floor(diffMs / 60000)
    const hours = Math.floor(diffMins / 60)
    const mins = diffMins % 60
    
    if (hours === 0) return `${mins} minutes`
    return `${hours} hours ${mins} minutes`
  }
  return 'Awaiting dates...'
})

const filteredDevices = computed(() => {
  if (!deviceSearchQuery.value.trim()) return devices.value
  
  const keywords = deviceSearchQuery.value.trim().toLowerCase().split(/\s+/)
  return devices.value.filter(device => {
    const hostname = String(device.hostname || '').toLowerCase()
    // Require ALL keywords to match the hostname
    return keywords.every(keyword => hostname.includes(keyword))
  })
})

const filteredCRQs = computed(() => {
    if (!searchQuery.value.trim()) return changeRequests.value
    
    const keywords = searchQuery.value.trim().toLowerCase().split(/\s+/)
    return changeRequests.value.filter(cr => {
        const textToSearch = [
            cr.crq_number || '',
            cr.purpose || '',
            cr.status || '',
            cr.implementer?.name || '',
            cr.requester?.name || ''
        ].join(' ').toLowerCase()
        
        return keywords.every(kw => textToSearch.includes(kw))
    })
})

const fetchChangeRequests = async () => {
  try {
    const response = await api.get('/change-requests')
    changeRequests.value = response.data
  } catch (error) {
    console.error('Failed to load CRs:', error)
  }
}

const fetchDependencies = async () => {
  try {
    const [devicesRes, usersRes] = await Promise.all([
      api.get('/devices'),
      api.get('/users')
    ])
    devices.value = devicesRes.data
    users.value = usersRes.data.filter(u => ['engineer', 'manager', 'admin'].includes(u.role))
  } catch (error) {
    console.error('Failed to load dependencies:', error)
  }
}

const openCreateView = () => {
  form.value = {
    purpose: '',
    task: '',
    implementer_id: '',
    reviewer_id: '',
    device_ids: [],
    start_time: '',
    end_time: '',
  }
  attachmentFile.value = null
  deviceSearchQuery.value = ''
  viewMode.value = 'create'
}

const closeCreateView = () => {
  viewMode.value = 'list'
  selectedCR.value = null
}

const viewCR = (cr) => {
  selectedCR.value = cr
  viewMode.value = 'view'
}

const toggleDeviceSelection = (deviceId) => {
    const index = intermediateSelectedDevices.value.indexOf(deviceId)
    if (index === -1) {
        intermediateSelectedDevices.value.push(deviceId)
    } else {
        intermediateSelectedDevices.value.splice(index, 1)
    }
}

const addSelectedDevices = () => {
    intermediateSelectedDevices.value.forEach(id => {
        if (!form.value.device_ids.includes(id)) {
            form.value.device_ids.push(id)
        }
    })
    intermediateSelectedDevices.value = [] // clear after adding
    deviceSearchQuery.value = '' // reset search
}

const removeStagedDevice = (deviceId) => {
    const index = form.value.device_ids.indexOf(deviceId)
    if (index !== -1) {
        form.value.device_ids.splice(index, 1)
    }
}

const saveCR = async () => {
  try {
    const formData = new FormData()
    formData.append('purpose', form.value.purpose)
    formData.append('task', form.value.task)
    formData.append('implementer_id', form.value.implementer_id)
    formData.append('reviewer_id', form.value.reviewer_id)
    formData.append('start_time', form.value.start_time)
    formData.append('end_time', form.value.end_time)
    
    form.value.device_ids.forEach((id) => {
        formData.append('device_ids[]', id)
    })
    
    if (attachmentFile.value) {
      formData.append('attachment', attachmentFile.value)
    }

    await api.post('/change-requests', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
    
    viewMode.value = 'list'
    await fetchChangeRequests()
  } catch (error) {
    console.error('Failed to save CR:', error)
    alert(error.response?.data?.message || 'Error saving Change Request.')
  }
}

const updateStatus = async (id, status) => {
  isApprovingReject.value = true
  try {
    const payload = {
      status,
      approval_comments: approvalComment.value || null
    }
    const response = await api.put(`/change-requests/${id}/status`, payload)
    approvalComment.value = ''
    
    // Refresh the list
    await fetchChangeRequests()
    
    // Close the view and return to list
    viewMode.value = 'list'
    selectedCR.value = null
  } catch (error) {
    console.error('Failed to update status:', error)
    alert(error.response?.data?.message || 'Error updating status.')
  } finally {
    isApprovingReject.value = false
  }
}

const downloadAttachment = (id, filename) => {
  api.get(`/change-requests/${id}/attachment`, { responseType: 'blob' })
    .then(response => {
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', filename);
      document.body.appendChild(link);
      link.click();
    })
    .catch(error => {
        console.error('Failed downloading attachment', error);
        alert('Failed downloading attachment.');
    })
}

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleString()
}

onMounted(async () => {
  loading.value = true
  await fetchDependencies()
  await fetchChangeRequests()
  loading.value = false
})
</script>

<template>
  <div class="h-full flex flex-col">
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-semibold text-slate-800">
        {{ viewMode === 'list' ? 'Change Requests' : 'Create Change Request' }}
      </h2>
      <button v-if="viewMode === 'list'" @click="openCreateView" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition shadow-sm flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
        Create New CR
      </button>
      <button v-else @click="closeCreateView" class="text-slate-500 hover:text-slate-700 font-medium transition flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
        Back to {{ viewMode === 'view' ? 'List' : 'List' }}
      </button>
    </div>

    <!-- LIST VIEW -->
    <div v-if="viewMode === 'list'" class="bg-white rounded-xl shadow-sm border border-slate-200 flex-1 overflow-hidden flex flex-col">
      <div class="p-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
          <div class="relative w-full max-w-md">
              <input v-model="searchQuery" type="text" class="w-full border border-slate-300 rounded-lg pl-10 pr-4 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm bg-white" placeholder="Search by CRQ Number, Purpose, Engineer...">
              <svg class="w-5 h-5 absolute left-3 top-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
          </div>
      </div>
      <div class="overflow-x-auto flex-1">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 text-sm uppercase tracking-wider text-slate-500">
              <th class="p-4 font-semibold">CRQ Number</th>
              <th class="p-4 font-semibold">Purpose</th>
              <th class="p-4 font-semibold">Status</th>
              <th class="p-4 font-semibold">Device Count</th>
              <th class="p-4 font-semibold">Start Time</th>
              <th class="p-4 font-semibold">End Time</th>
              <th class="p-4 font-semibold">Implementer</th>
              <th class="p-4 font-semibold">Reviewer</th>
              <th class="p-4 font-semibold">Attachment</th>
              <th class="p-4 font-semibold text-right" v-if="['admin', 'manager'].includes(currentUser?.role)">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <tr v-if="loading">
              <td colspan="11" class="p-8 text-center text-slate-400">Loading change requests...</td>
            </tr>
            <tr v-else-if="filteredCRQs.length === 0">
              <td colspan="11" class="p-8 text-center text-slate-400">No change requests found.</td>
            </tr>
            <tr v-else v-for="cr in filteredCRQs" :key="cr.id" class="hover:bg-slate-50 transition cursor-pointer group" @click="viewCR(cr)">
              <td class="p-4 align-middle font-bold text-blue-700 text-sm tracking-wider">
                {{ cr.crq_number }}
              </td>
              <td class="p-4 align-middle font-medium text-slate-900 truncate max-w-xs" :title="cr.purpose">
                {{ cr.purpose }}
              </td>
              <td class="p-4 align-middle">
                <span class="px-2.5 py-1 rounded-full font-bold shadow-sm border text-xs" :class="{
                    'bg-yellow-100 text-yellow-700 border-yellow-200': cr.status === 'pending',
                    'bg-green-100 text-green-700 border-green-200': cr.status === 'approved',
                    'bg-red-100 text-red-700 border-red-200': cr.status === 'rejected'
                }">{{ cr.status.toUpperCase() }}</span>
              </td>
              <td class="p-4 align-middle font-medium text-slate-700">
                {{ cr.devices.length }}
              </td>
              <td class="p-4 align-middle text-xs text-slate-600">
                {{ formatDate(cr.start_time) }}
              </td>
              <td class="p-4 align-middle text-xs text-slate-600">
                {{ formatDate(cr.end_time) }}
              </td>
              <td class="p-4 align-middle text-sm text-slate-700">
                {{ cr.implementer?.name || 'N/A' }}
              </td>
              <td class="p-4 align-middle text-sm text-slate-700">
                {{ cr.reviewer?.name || 'N/A' }}
              </td>
              <td class="p-4 align-middle" @click.stop>
                <button v-if="cr.attachment_path" @click="downloadAttachment(cr.id, cr.attachment_name)" class="text-blue-600 hover:text-blue-800 flex items-center gap-1 p-1 hover:bg-blue-50 rounded transition" :title="cr.attachment_name">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                </button>
                <span v-else class="text-slate-400 text-xs italic">N/A</span>
              </td>
              <td class="p-4 text-right align-middle" v-if="['admin', 'manager'].includes(currentUser?.role)" @click.stop>
                <div class="flex items-center justify-end gap-2">
                    <button v-if="cr.status === 'pending'" @click="updateStatus(cr.id, 'approved')" class="text-white bg-green-500 hover:bg-green-600 px-3 py-1.5 rounded shadow-sm transition font-medium text-xs">Approve</button>
                    <button v-if="cr.status === 'pending'" @click="updateStatus(cr.id, 'rejected')" class="text-white bg-red-500 hover:bg-red-600 px-3 py-1.5 rounded shadow-sm transition font-medium text-xs">Reject</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- CREATE PAGE VIEW -->
    <div v-if="viewMode === 'create'" class="bg-white rounded-xl shadow-sm border border-slate-200">
      
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 p-6 lg:p-8">
        
        <!-- Left Column: Details -->
        <div class="space-y-6">
          <div class="border-b border-slate-200 pb-4">
              <h3 class="text-lg font-semibold text-slate-800">1. Required Information</h3>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Purpose <span class="text-red-500">*</span></label>
            <input v-model="form.purpose" type="text" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm" placeholder="e.g. Upgrade IOS on Core Router">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Task Details <span class="text-red-500">*</span></label>
            <textarea v-model="form.task" rows="4" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm" placeholder="Describe the steps to be taken in detail..."></textarea>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">Implementation Engineer <span class="text-red-500">*</span></label>
              <select v-model="form.implementer_id" class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white shadow-sm">
                <option value="">Select Engineer</option>
                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} ({{ user.role }})</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">Review Engineer <span class="text-red-500">*</span></label>
              <select v-model="form.reviewer_id" class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white shadow-sm">
                <option value="">Select Engineer</option>
                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} ({{ user.role }})</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4 pt-2">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">Start Time <span class="text-red-500">*</span></label>
              <input v-model="form.start_time" type="datetime-local" class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">End Time <span class="text-red-500">*</span></label>
              <input v-model="form.end_time" type="datetime-local" class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm">
            </div>
          </div>

          <div class="bg-blue-50/50 p-4 rounded-lg border border-blue-100 flex justify-between items-center shadow-sm">
              <span class="text-sm font-medium text-slate-700">Calculated Duration:</span>
              <span class="text-sm font-bold text-blue-700">{{ calculatedDuration }}</span>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Attachment (MoP, Rollback Plan, etc)</label>
            <input type="file" @change="handleFileUpload" accept=".xlsx,.doc,.docx,.pdf,.csv,.xml,.txt" class="block w-full text-sm text-slate-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 border border-slate-300 rounded-lg shadow-sm bg-white"/>
            <p class="text-xs text-slate-500 mt-2">Accepted formats: Excel (.xlsx), Word (.doc), PDF, CSV, XML, TXT (Max 10MB)</p>
          </div>
        </div>

        <!-- Right Column: Device Search and Selection -->
        <div class="space-y-4 flex flex-col border-t lg:border-t-0 lg:border-l border-slate-200 pt-6 lg:pt-0 lg:pl-8">
            <div class="border-b border-slate-200 pb-2">
                <h3 class="text-lg font-semibold text-slate-800">2. Select Impacted Devices</h3>
            </div>
            
            <!-- Added Devices List (Staged) -->
            <div class="bg-white border text-sm border-blue-200 rounded-xl overflow-hidden shadow-sm mb-4" v-if="form.device_ids.length > 0">
                <div class="bg-blue-50 border-b border-blue-100 p-3 font-semibold text-blue-800 flex justify-between items-center">
                    <span>Devices Staged for CRQ</span>
                    <span class="bg-blue-200 text-blue-800 px-2 py-0.5 rounded-full text-xs">{{ form.device_ids.length }}</span>
                </div>
                <ul class="divide-y divide-slate-100 max-h-48 overflow-y-auto">
                    <li v-for="device in stagedDevices" :key="device.id" class="p-2.5 flex justify-between items-center hover:bg-slate-50">
                        <span class="font-medium text-slate-700">{{ device.hostname }} <span class="text-slate-400 font-normal text-xs ml-1">({{ device.ip_address }})</span></span>
                        <button @click="removeStagedDevice(device.id)" class="text-red-500 hover:text-red-700 hover:bg-red-50 p-1 rounded transition" title="Remove">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    </li>
                </ul>
            </div>
            <div v-else class="bg-slate-50 border border-slate-200 border-dashed rounded-xl p-6 text-center text-slate-500 mb-4 text-sm">
                No devices added yet. Search below and click "Add to CRQ".
            </div>

            <!-- Search Area -->
            <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 flex flex-col shadow-inner">
                <div class="relative mb-3">
                  <input v-model="deviceSearchQuery" type="text" class="w-full border border-slate-300 rounded-lg pl-9 pr-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm bg-white" placeholder="Search by Hostname...">
                  <svg class="w-4 h-4 absolute left-3 top-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>

                <div class="overflow-y-auto bg-white border border-slate-200 rounded-lg relative shadow-sm h-48">
                    <table class="w-full text-left border-collapse min-w-full">
                        <thead class="sticky top-0 bg-white shadow-sm z-10 border-b border-slate-200">
                            <tr>
                                <th class="p-2 w-10 text-center"></th>
                                <th class="p-2 text-xs font-semibold text-slate-600 uppercase tracking-wider">Search Results</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                            <tr v-if="filteredDevices.length === 0">
                                <td colspan="2" class="p-4 text-center text-slate-400 text-xs italic">Type above to search...</td>
                            </tr>
                            <tr v-for="device in filteredDevices" :key="device.id" class="hover:bg-blue-50/50 transition cursor-pointer" @click="toggleDeviceSelection(device.id)">
                                <td class="p-2 text-center align-middle" @click.stop>
                                    <input type="checkbox" :value="device.id" v-model="intermediateSelectedDevices" class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500">
                                </td>
                                <td class="p-2 text-slate-800 font-medium truncate max-w-[200px]" :class="form.device_ids.includes(device.id) ? 'text-slate-400 line-through' : ''" :title="form.device_ids.includes(device.id) ? 'Already Added' : ''">
                                    {{ device.hostname }}
                                    <span v-if="form.device_ids.includes(device.id)" class="text-[10px] ml-2 text-green-600 italic no-underline">Added</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">{{ intermediateSelectedDevices.length }} selected</span>
                    <button @click="addSelectedDevices" :disabled="intermediateSelectedDevices.length === 0" class="bg-slate-800 text-white hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed px-3 py-1.5 rounded disabled:hover:bg-slate-800 text-sm font-medium transition flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                        Add to CRQ
                    </button>
                </div>
            </div>
        </div>
        
      </div>

      <div class="p-6 border-t border-slate-200 bg-slate-50 flex justify-end gap-4 rounded-b-xl mt-auto">
        <button @click="closeCreateView" class="px-6 py-2.5 text-slate-600 hover:text-slate-900 hover:bg-slate-200 rounded-lg font-medium transition">Discard</button>
        <button @click="saveCR" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-lg font-medium transition shadow-sm flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            Save Change Request
        </button>
      </div>

    </div>

    <!-- READ ONLY VIEW PAGE -->
    <div v-if="viewMode === 'view' && selectedCR" class="bg-white rounded-xl shadow-sm border border-slate-200">
      <div class="p-6 lg:p-8 space-y-8">
        <!-- Header Info -->
        <div class="flex flex-col md:flex-row justify-between md:items-start gap-4 pb-6 border-b border-slate-100">
          <div>
            <div class="font-bold text-blue-600 mb-2 font-mono text-sm tracking-widest">{{ selectedCR.crq_number }}</div>
            <h3 class="text-2xl font-bold text-slate-800 mb-2">{{ selectedCR.purpose }}</h3>
            <div class="flex flex-wrap items-center gap-4 text-sm text-slate-500">
              <span class="flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                Requested by: <span class="font-semibold text-slate-700">{{ selectedCR.requester?.name || 'Unknown' }}</span>
              </span>
              <span class="flex items-center gap-1" v-if="selectedCR.status !== 'pending'">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Approved/Rejected by: <span class="font-semibold text-slate-700">{{ selectedCR.approver?.name || 'System' }}</span>
              </span>
            </div>
          </div>
          <div class="flex flex-col items-end gap-2">
            <span class="px-3 py-1 rounded-full font-bold shadow-sm border text-sm" :class="{
                'bg-yellow-100 text-yellow-700 border-yellow-200': selectedCR.status === 'pending',
                'bg-green-100 text-green-700 border-green-200': selectedCR.status === 'approved',
                'bg-red-100 text-red-700 border-red-200': selectedCR.status === 'rejected'
            }">{{ selectedCR.status.toUpperCase() }}</span>
            <div class="text-xs text-slate-400">Created: {{ formatDate(selectedCR.created_at) }}</div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          <!-- Left Main Content -->
          <div class="lg:col-span-2 space-y-6">
            <div>
              <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Task Details</h4>
              <div class="bg-slate-50 p-4 rounded-lg border border-slate-200 text-slate-700 whitespace-pre-wrap leading-relaxed shadow-inner font-mono text-sm">
                {{ selectedCR.task }}
              </div>
            </div>

            <div>
              <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Impacted Devices ({{ selectedCR.devices.length }})</h4>
              <div class="bg-white border border-slate-200 rounded-lg shadow-sm">
                <table class="w-full text-left border-collapse">
                  <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                      <th class="p-3 text-xs font-semibold text-slate-600 uppercase tracking-wider">Hostname</th>
                      <th class="p-3 text-xs font-semibold text-slate-600 uppercase tracking-wider">IP Address</th>
                      <th class="p-3 text-xs font-semibold text-slate-600 uppercase tracking-wider">Model</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="selectedCR.devices.length === 0">
                      <td colspan="3" class="p-4 text-center text-slate-400 text-sm italic">No devices selected.</td>
                    </tr>
                    <tr v-else v-for="device in selectedCR.devices" :key="device.id" class="hover:bg-slate-50 transition">
                      <td class="p-3 font-medium text-slate-800">{{ device.hostname }}</td>
                      <td class="p-3 font-mono text-sm text-slate-600">{{ device.ip_address }}</td>
                      <td class="p-3 text-sm text-slate-600">{{ device.model }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Right Sidebar -->
          <div class="space-y-6">
            <div>
              <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Schedule</h4>
              <div class="bg-blue-50/50 p-4 rounded-lg border border-blue-100 shadow-sm space-y-3">
                <div>
                  <div class="text-xs text-slate-500 font-medium mb-1">Start Time</div>
                  <div class="font-semibold text-slate-800">{{ formatDate(selectedCR.start_time) }}</div>
                </div>
                <div>
                  <div class="text-xs text-slate-500 font-medium mb-1">End Time</div>
                  <div class="font-semibold text-slate-800">{{ formatDate(selectedCR.end_time) }}</div>
                </div>
              </div>
            </div>

            <div>
              <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Personnel</h4>
              <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-start gap-3">
                  <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 font-bold uppercase text-xs shrink-0">I</div>
                  <div>
                    <div class="text-xs text-slate-500 font-medium mb-0.5">Implementation Engineer</div>
                    <div class="font-semibold text-slate-800">{{ selectedCR.implementer?.name || 'Unknown' }}</div>
                  </div>
                </div>
                <div class="flex items-start gap-3">
                  <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 font-bold uppercase text-xs shrink-0">R</div>
                  <div>
                    <div class="text-xs text-slate-500 font-medium mb-0.5">Review Engineer</div>
                    <div class="font-semibold text-slate-800">{{ selectedCR.reviewer?.name || 'Unknown' }}</div>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="selectedCR.approval_comments && selectedCR.status !== 'pending'">
              <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Manager Comments</h4>
              <div class="bg-blue-50 p-4 rounded-lg border border-blue-100 shadow-sm">
                <p class="text-slate-700 whitespace-pre-wrap text-sm leading-relaxed">{{ selectedCR.approval_comments }}</p>
                <p class="text-xs text-slate-500 mt-3" v-if="selectedCR.approver">Reviewed by: <span class="font-medium text-slate-700">{{ selectedCR.approver.name }}</span> on {{ formatDate(selectedCR.updated_at) }}</p>
              </div>
            </div>

            <div v-if="selectedCR.attachment_path">
              <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Attachment</h4>
              <button @click="downloadAttachment(selectedCR.id, selectedCR.attachment_name)" class="w-full text-blue-700 bg-blue-50 border border-blue-200 hover:bg-blue-100 focus:ring-4 focus:ring-blue-100 font-medium rounded-lg text-sm px-5 py-3 text-center inline-flex items-center justify-center gap-2 transition shadow-sm">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Download {{ selectedCR.attachment_name.length > 20 ? selectedCR.attachment_name.substring(0, 20) + '...' : selectedCR.attachment_name }}
              </button>
            </div>
            
            <div v-if="selectedCR.status === 'pending' && ['admin', 'manager'].includes(currentUser?.role)" class="pt-4 border-t border-slate-100">
                <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Manager Review & Approval</h4>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Comments (Optional)</label>
                        <textarea v-model="approvalComment" rows="3" placeholder="Add any comments or feedback before approving or rejecting..." class="w-full border border-slate-300 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm resize-none" :disabled="isApprovingReject"></textarea>
                        <p class="text-xs text-slate-400 mt-1">Max 1000 characters</p>
                    </div>
                    <div class="flex gap-3">
                        <button 
                            @click="updateStatus(selectedCR.id, 'approved')" 
                            :disabled="isApprovingReject"
                            class="flex-1 text-white bg-green-500 hover:bg-green-600 disabled:bg-green-400 disabled:cursor-not-allowed px-3 py-2.5 rounded-lg shadow-sm transition font-medium text-sm flex items-center justify-center gap-2">
                            <svg v-if="isApprovingReject" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ isApprovingReject ? 'Processing...' : 'Approve' }}
                        </button>
                        <button 
                            @click="updateStatus(selectedCR.id, 'rejected')" 
                            :disabled="isApprovingReject"
                            class="flex-1 text-white bg-red-500 hover:bg-red-600 disabled:bg-red-400 disabled:cursor-not-allowed px-3 py-2.5 rounded-lg shadow-sm transition font-medium text-sm flex items-center justify-center gap-2">
                            <svg v-if="isApprovingReject" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ isApprovingReject ? 'Processing...' : 'Reject' }}
                        </button>
                    </div>
                </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</template>
