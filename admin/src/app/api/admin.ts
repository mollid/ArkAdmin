import request from './request'
import type { MenuItem } from './auth'

export interface AdminRow { id: number; username: string; name: string; status: number; roles: number[]; created_at?: string }

export const adminApi = {
  list: (params: Record<string, unknown>) => request.get<never, { list: AdminRow[]; total: number }>('/admins', { params }),
  store: (data: Record<string, unknown>) => request.post<never, unknown>('/admins', data),
  update: (id: number, data: Record<string, unknown>) => request.put<never, unknown>(`/admins/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/admins/${id}`),
}

export interface RoleRow { id: number; name: string; permissions: string[] }

export const roleApi = {
  list: () => request.get<never, RoleRow[]>('/roles'),
  permissions: () => request.get<never, Record<string, { name: string; module: string }[]>>('/roles/permissions'),
  store: (data: { name: string; permissions: string[] }) => request.post<never, unknown>('/roles', data),
  update: (id: number, data: { name: string; permissions: string[] }) => request.put<never, unknown>(`/roles/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/roles/${id}`),
}

export const menuApi = {
  list: () => request.get<never, MenuItem[]>('/menus'),
  store: (data: Partial<MenuItem>) => request.post<never, unknown>('/menus', data),
  update: (id: number, data: Partial<MenuItem>) => request.put<never, unknown>(`/menus/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/menus/${id}`),
}
