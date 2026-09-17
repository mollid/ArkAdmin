import request from './request'
import type { AddonRow } from '../utils/addon'

export type AddonAction = 'enable' | 'disable' | 'upgrade'

export const addonApi = {
  list: () => request.get<never, AddonRow[]>('/addons'),
  install: (name: string) => request.post<never, { needs_build: boolean }>('/addons', { name }),
  update: (name: string, action: AddonAction, force = false) =>
    request.put<never, { upgraded: boolean; needs_build: boolean }>(`/addons/${name}`, { action, force }),
  uninstall: (name: string, keepData = false) =>
    request.delete<never, null>(`/addons/${name}`, { params: keepData ? { keep_data: 1 } : {} }),
}
