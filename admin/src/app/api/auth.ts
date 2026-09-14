import request from './request'

export interface LoginResp { token: string; admin: { id: number; username: string; name: string } }
export interface MeResp {
  admin: { id: number; username: string; name: string }
  roles: string[]
  permissions: string[]
  menus: MenuItem[]
}
export interface MenuItem {
  id: number; parent_id: number; name: string; title: string; icon: string
  route_path: string; view_path: string; permission: string; addon_key: string
  sort: number; children: MenuItem[]
  is_show?: boolean // 菜单管理接口（/menus）返回；/auth/me 的过滤后菜单树不含
}

export const authApi = {
  login: (data: { username: string; password: string }) =>
    request.post<never, LoginResp>('/auth/login', data),
  me: () => request.get<never, MeResp>('/auth/me'),
  logout: () => request.delete<never, null>('/auth/logout'),
}
