<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from './utils/api.js'

const route = useRoute()
const router = useRouter()
const isSidebarOpen = ref(false)

const user = ref(null)

// Refresh user on mount and route change
const refreshUser = () => {
  const userStr = localStorage.getItem('user')
  user.value = userStr ? JSON.parse(userStr) : null
}
onMounted(refreshUser)
router.afterEach(refreshUser)

const navigation = computed(() => {
  const nav = [
    { name: 'Dashboard', to: '/', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>' },
    { name: 'Device Inventory', to: '/inventory', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path>' },
    { name: 'Change Requests', to: '/change-requests', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>' },
    { name: 'Web CLI', to: '/cli', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>' },
  ]
  if (user.value && user.value.role === 'admin') {
    nav.push({ name: 'User Management', to: '/users', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>' })
  }
  return nav
})

const currentRouteName = computed(() => {
  const current = navigation.value.find(n => n.to === route.path)
  return current ? current.name : 'NetAuto ITSM'
})

const handleLogout = async () => {
  try {
    await api.post('/logout')
  } catch (e) {
    console.error(e)
  } finally {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('user')
    user.value = null
    router.push('/login')
  }
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 flex">
    
    <template v-if="route.path !== '/login'">
      <!-- Mobile sidebar backdrop -->
      <div v-if="isSidebarOpen" @click="isSidebarOpen = false" class="fixed inset-0 z-20 bg-slate-900/50 md:hidden"></div>

      <!-- Sidebar -->
      <div :class="[
          'fixed inset-y-0 left-0 z-30 w-64 bg-slate-900 text-white flex flex-col transition-transform duration-300 transform md:relative md:translate-x-0',
          isSidebarOpen ? 'translate-x-0' : '-translate-x-full'
        ]">
        <div class="p-4 border-b border-slate-700 flex justify-between items-center bg-slate-950">
          <h1 class="text-xl font-bold flex items-center gap-2">
            <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            ITSM
          </h1>
          <button @click="isSidebarOpen = false" class="md:hidden text-slate-400 hover:text-white">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>
        <nav class="flex-1 py-4 space-y-1">
          <router-link v-for="item in navigation" :key="item.name" :to="item.to"
            class="block px-4 py-3 mx-3 rounded-lg transition-colors font-medium text-sm flex items-center gap-3"
            :class="route.path === item.to ? 'bg-blue-600 text-white shadow-md shadow-blue-900/20' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'"
            @click="isSidebarOpen = false">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="item.icon"></svg>
            {{ item.name }}
          </router-link>
        </nav>
        <div class="p-4 border-t border-slate-800 text-sm font-medium flex flex-col gap-3 text-slate-300 bg-slate-950/50">
          <div class="flex items-center gap-3" v-if="user">
            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold shadow-inner uppercase">
              {{ user.name.charAt(0) }}
            </div>
            <div class="flex flex-col">
              <span class="text-white truncate w-32">{{ user.name }}</span>
              <span class="text-xs text-slate-500 capitalize">{{ user.role }}</span>
            </div>
          </div>
          <button @click="handleLogout" class="w-full text-left text-red-400 hover:text-red-300 transition py-1 flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
            Sign out
          </button>
        </div>
      </div>

      <!-- Main Content -->
      <div class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50">
        <!-- Top navbar -->
        <header class="bg-white/80 backdrop-blur-md shadow-sm border-b border-slate-200 px-6 py-4 flex justify-between items-center z-10 w-full sticky top-0">
          <div class="flex items-center gap-4">
            <button @click="isSidebarOpen = !isSidebarOpen" class="md:hidden text-slate-500 hover:text-slate-800 transition">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <h2 class="text-xl font-bold text-slate-800 truncate">{{ currentRouteName }}</h2>
          </div>
        </header>

        <!-- Main working area -->
        <main class="flex-1 overflow-auto p-6 md:p-8">
          <router-view></router-view>
        </main>
      </div>
    </template>

    <template v-else>
      <router-view></router-view>
    </template>
  </div>
</template>
