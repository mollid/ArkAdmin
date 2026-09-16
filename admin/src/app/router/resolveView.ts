import type { MenuItem } from '../api/auth'

const appViews = import.meta.glob('/src/app/views/**/*.vue')
const addonViews = import.meta.glob('/src/addons/**/views/**/*.vue')

// 共享缺失兜底 loader：miss 断言与运行时行为一致（§7.2 组件缺失提示页）
export const missingView = () => import('@/app/views/missing/index.vue')

export type ViewMap = Record<string, () => Promise<unknown>>

/** 菜单 view_path 是后台表单里的自由文本：去空白、前导斜杠与 .vue 后缀 */
export function normalizeViewPath(viewPath: string): string {
  return viewPath.trim().replace(/^\/+/, '').replace(/\.vue$/i, '')
}

/** 菜单 → glob key（§7.2）：插件查 /src/addons/<key>/views/，框架查 /src/app/views/ */
export function viewKeyOf(m: MenuItem): string {
  const path = normalizeViewPath(m.view_path)
  return m.addon_key
    ? `/src/addons/${m.addon_key}/views/${path}.vue`
    : `/src/app/views/${path}.vue`
}

/**
 * 菜单 → 组件 loader。miss → missingView。
 * glob 以默认参数注入，测试可传 fake map 而不落文件系统。
 */
export function resolveView(
  m: MenuItem,
  appV: ViewMap = appViews as ViewMap,
  addonV: ViewMap = addonViews as ViewMap,
): () => Promise<unknown> {
  const map = m.addon_key ? addonV : appV
  return map[viewKeyOf(m)] ?? missingView
}
