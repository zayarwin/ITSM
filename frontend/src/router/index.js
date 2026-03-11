import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { requiresAuth: false }
    },
    {
      path: '/',
      name: 'dashboard',
      component: DashboardView,
      meta: { requiresAuth: true }
    },
    {
      path: '/inventory',
      name: 'inventory',
      component: () => import('../views/DeviceInventoryView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/change-requests',
      name: 'change-requests',
      component: () => import('../views/ChangeRequestsView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/cli',
      name: 'cli',
      component: () => import('../views/WebCliView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/users',
      name: 'users',
      component: () => import('../views/UsersView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
    },
  ],
})

// Navigation Guard
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('auth_token')
  const userStr = localStorage.getItem('user')
  const user = userStr ? JSON.parse(userStr) : null

  if (to.meta.requiresAuth && !token) {
    // If route requires auth and no token, go to login
    next({ name: 'login' })
  } else if (to.name === 'login' && token) {
    // If trying to go to login while already authenticated, redirect to home
    next({ name: 'dashboard' })
  } else if (to.meta.requiresAdmin && (!user || user.role !== 'admin')) {
    // If route requires admin but user is not admin
    next({ name: 'dashboard' })
  } else {
    next()
  }
})

export default router
