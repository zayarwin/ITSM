<script setup>
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const isSidebarOpen = ref(false)
const user = ref('Admin')

const navigation = [
  { name: 'Dashboard', href: '/', icon: 'home' },
  { name: 'Device Inventory', href: '/inventory', icon: 'server' },
  { name: 'Change Requests', href: '#', icon: 'git-pull-request' },
  { name: 'Web CLI', href: '/cli', icon: 'terminal' },
]

const currentRouteName = computed(() => {
  const current = navigation.find(n => n.href === route.path)
  return current ? current.name : 'NetAuto ITSM'
})
</script>

<template>
  <div class="min-h-screen bg-gray-50 flex">
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
          NetAuto ITSM
        </h1>
        <button @click="isSidebarOpen = false" class="md:hidden text-slate-400 hover:text-white">
          <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
      </div>
      <nav class="flex-1 py-4 space-y-1">
        <router-link v-for="item in navigation" :key="item.name" :to="item.href"
          class="block px-4 py-3 mx-3 rounded-lg transition-colors font-medium text-sm flex items-center gap-3"
          :class="route.path === item.href ? 'bg-blue-600 text-white shadow-md shadow-blue-900/20' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'"
          @click="isSidebarOpen = false">
          {{ item.name }}
        </router-link>
      </nav>
      <div class="p-4 border-t border-slate-800 text-sm font-medium flex items-center gap-3 text-slate-400 bg-slate-950/50">
        <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold shadow-inner">
          {{ user.charAt(0) }}
        </div>
        Logged in as {{ user }}
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
        <div class="flex space-x-3 items-center">
          <button class="relative p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 rounded-full transition">
            <span class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full border-2 border-white"></span>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
          </button>
        </div>
      </header>

      <!-- Main working area -->
      <main class="flex-1 overflow-auto p-6 md:p-8">
        <router-view></router-view>
      </main>
    </div>
  </div>
</template>
