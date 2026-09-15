import type { MenuItem } from '../api/auth'

const appViews = import.meta.glob('/src/app/views/**/*.vue')
const addonViews = import.meta.glob('/src/addons/**/views/**/*.vue')

// 共享缺失兜底 loader：miss 断言与运行时行为一致（§7.2 组件缺失提示页）
export const missingView = () => import('@/app/views/missing/index.vue')

export type ViewMap = Record<string, () => Promise<unknown>>

/**
 * 菜单 view_path → 组件 loader。addon_key 非空查插件目录（§7.2）：
 * /src/addons/<key>/views/<view_path>.vue；框架菜单查 /src/app/views/。miss → missingView。
 * glob 以默认参数注入，测试可传 fake map 而不落文件系统。
 */
export function resolveView(
  m: MenuItem,
  appV: ViewMap = appViews as ViewMap,
  addonV: ViewMap = addonViews as ViewMap,
): () => Promise<unknown> {
  const key = m.addon_key
    ? `/src/addons/${m.addon_key}/views/${m.view_path}.vue`
    : `/src/app/views/${m.view_path}.vue`
  const map = m.addon_key ? addonV : appV
  return map[key] ?? missingView
}
