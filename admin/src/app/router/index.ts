import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import type { MenuItem } from '../api/auth'
import { useUserStore } from '../stores/user'

const appViews = import.meta.glob('/src/app/views/**/*.vue')

export const Layout = () => import('@/app/views/layout/index.vue')

export function mapMenusToRoutes(menus: MenuItem[]): RouteRecordRaw[] {
  const routes: RouteRecordRaw[] = []
  for (const m of menus) {
    if (m.children?.length) {
      routes.push({
        path: m.route_path || `/${m.name}`,
        component: Layout,
        children: mapMenusToRoutes(m.children),
      })
    } else if (m.view_path) {
      const key = `/src/app/views/${m.view_path}.vue`
      routes.push({
        path: m.route_path || `/${m.name}`,
        name: m.name,
        component: (appViews as Record<string, () => Promise<unknown>>)[key]
          ?? (() => import('@/app/views/missing/index.vue')),
        meta: { title: m.title },
      })
    }
  }
  return routes
}

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/app/views/login/index.vue') },
    { path: '/:pathMatch(.*)*', name: 'notfound', redirect: '/dashboard' },
  ],
})

router.beforeEach(async (to) => {
  const store = useUserStore()
  if (to.path === '/login') return true
  if (!store.token) return { path: '/login' }
  if (!store.routesAdded) {
    await store.fetchMe()
    for (const r of mapMenusToRoutes(store.menus)) {
      router.addRoute(r)
    }
    store.routesAdded = true
    return { ...to, replace: true }
  }
  return true
})

export default router
