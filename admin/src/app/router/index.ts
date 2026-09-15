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
    // 注意：catch-all 不能 redirect 到动态注册的路由——redirect 在守卫之前解析，
    // 动态路由注册前会无限递归；必须渲染真实组件让 beforeEach 有机会执行
    { path: '/:pathMatch(.*)*', name: 'notfound', component: () => import('@/app/views/notfound/index.vue') },
  ],
})

router.beforeEach(async (to) => {
  const store = useUserStore()
  if (to.path === '/login') return true
  if (!store.token) return { path: '/login' }
  if (to.path === '/') return { path: '/dashboard', replace: true }
  if (!store.routesAdded) {
    await store.fetchMe()
    for (const r of mapMenusToRoutes(store.menus)) {
      router.addRoute(r)
    }
    store.routesAdded = true
    // 必须构造全新 location：扩散 to 会带上 catch-all 的 name/params，
    // 按 name 解析会再次命中 catch-all
    return { path: to.path, query: to.query, hash: to.hash, replace: true }
  }
  return true
})

export default router
