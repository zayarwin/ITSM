<script setup>
import { ref, onMounted } from 'vue'
import api from '../utils/api.js'

const users = ref([])
const loading = ref(true)
const showModal = ref(false)
const isEditing = ref(false)
const formError = ref('')

const form = ref({
  id: null,
  name: '',
  email: '',
  password: '',
  role: 'engineer'
})

const fetchUsers = async () => {
  loading.value = true
  try {
    const response = await api.get('/users')
    users.value = response.data
  } catch (error) {
    console.error('Failed to load users:', error)
  } finally {
    loading.value = false
  }
}

const openAddModal = () => {
  isEditing.value = false
  formError.value = ''
  form.value = { id: null, name: '', email: '', password: '', role: 'engineer' }
  showModal.value = true
}

const openEditModal = (user) => {
  isEditing.value = true
  formError.value = ''
  form.value = { ...user, password: '' }
  showModal.value = true
}

const saveUser = async () => {
  formError.value = ''
  try {
    if (isEditing.value) {
      await api.put(`/users/${form.value.id}`, form.value)
    } else {
      await api.post('/users', form.value)
    }
    showModal.value = false
    await fetchUsers()
  } catch (error) {
    console.error('Failed to save user:', error)
    formError.value = error.response?.data?.message || 'Error saving user.'
  }
}

const deleteUser = async (id) => {
  if (confirm('Are you sure you want to permanently delete this user?')) {
    try {
      await api.delete(`/users/${id}`)
      await fetchUsers()
    } catch (error) {
      console.error('Failed to delete user:', error)
      alert(error.response?.data?.message || 'Failed to delete user.')
    }
  }
}

onMounted(() => {
  fetchUsers()
})
</script>

<template>
  <div class="h-full flex flex-col">
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-semibold text-slate-800">User Management</h2>
      <button @click="openAddModal" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition shadow-sm flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
        Add User
      </button>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 flex-1 overflow-hidden flex flex-col">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 text-sm uppercase tracking-wider text-slate-500">
              <th class="p-4 font-semibold">Name</th>
              <th class="p-4 font-semibold">Email</th>
              <th class="p-4 font-semibold">Role</th>
              <th class="p-4 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <tr v-if="loading">
              <td colspan="4" class="p-8 text-center text-slate-400">Loading users...</td>
            </tr>
            <tr v-else-if="users.length === 0">
              <td colspan="4" class="p-8 text-center text-slate-400">No users found.</td>
            </tr>
            <tr v-else v-for="user in users" :key="user.id" class="hover:bg-slate-50 transition">
              <td class="p-4 font-medium text-slate-900 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 font-bold uppercase text-xs">
                  {{ user.name.charAt(0) }}
                </div>
                {{ user.name }}
              </td>
              <td class="p-4">{{ user.email }}</td>
              <td class="p-4">
                <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize', 
                  user.role === 'admin' ? 'bg-purple-100 text-purple-800' : 
                  user.role === 'manager' ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-800']">
                  {{ user.role }}
                </span>
              </td>
              <td class="p-4 text-right flex justify-end gap-2 text-sm">
                <button @click="openEditModal(user)" class="text-blue-600 hover:text-blue-800 p-2 hover:bg-blue-50 rounded transition">Edit</button>
                <button @click="deleteUser(user.id)" class="text-red-500 hover:text-red-700 p-2 hover:bg-red-50 rounded transition">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Form -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showModal = false"></div>
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md relative z-10 overflow-hidden flex flex-col">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <h3 class="text-xl font-semibold text-slate-800">{{ isEditing ? 'Edit User' : 'Add New User' }}</h3>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 transition">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>
        
        <div class="p-5 space-y-4">
          <div v-if="formError" class="bg-red-50 p-3 rounded-lg border border-red-100 flex items-center gap-2 text-sm text-red-600">
            {{ formError }}
          </div>

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Full Name <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="John Doe">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Email <span class="text-red-500">*</span></label>
              <input v-model="form.email" type="email" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="example@company.com">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Role <span class="text-red-500">*</span></label>
              <select v-model="form.role" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                <option value="engineer">Engineer</option>
                <option value="manager">Manager</option>
                <option value="admin">Administrator</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Password <span class="text-red-500" v-if="!isEditing">*</span></label>
              <input v-model="form.password" type="password" autocomplete="new-password" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500" :placeholder="isEditing ? 'Leave blank to keep current password' : '••••••••'">
            </div>
          </div>
        </div>
        <div class="p-5 border-t border-slate-100 bg-slate-50 flex justify-end gap-3 rounded-b-xl">
          <button @click="showModal = false" class="px-4 py-2 text-slate-600 hover:text-slate-800 font-medium transition">Cancel</button>
          <button @click="saveUser" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition shadow-sm">
            {{ isEditing ? 'Save Changes' : 'Create User' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
