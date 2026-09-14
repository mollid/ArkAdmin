import { defineStore } from 'pinia'
import { authApi, type MenuItem, type MeResp } from '../api/auth'
import { TOKEN_KEY } from '../api/request'

export const useUserStore = defineStore('user', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    adminInfo: null as MeResp['admin'] | null,
    permissions: [] as string[],
    menus: [] as MenuItem[],
    routesAdded: false,
  }),
  getters: {
    isSuper: (s) => s.permissions.includes('*'),
    has: (s) => (perm: string) => s.permissions.includes('*') || s.permissions.includes(perm),
  },
  actions: {
    async login(username: string, password: string) {
      const resp = await authApi.login({ username, password })
      this.token = resp.token
      localStorage.setItem(TOKEN_KEY, resp.token)
    },
    async fetchMe() {
      const resp = await authApi.me()
      this.adminInfo = resp.admin
      this.permissions = resp.permissions
      this.menus = resp.menus
    },
    async logout() {
      try { await authApi.logout() } finally {
        this.token = ''
        this.permissions = []
        this.menus = []
        this.routesAdded = false
        localStorage.removeItem(TOKEN_KEY)
      }
    },
  },
})
