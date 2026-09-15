import { describe, expect, it } from 'vitest'
import { missingView, resolveView } from '../src/app/router/resolveView'
import type { MenuItem } from '../src/app/api/auth'

const menu = (over: Partial<MenuItem>): MenuItem => ({
  id: 1, parent_id: 0, name: 'm', title: 'M', icon: '', route_path: '', view_path: '',
  permission: '', addon_key: '', sort: 0, children: [], ...over,
})

const appViews = { '/src/app/views/dashboard/index.vue': () => import('../src/app/views/dashboard/index.vue') }
const addonViews = { '/src/addons/demo/views/note/index.vue': () => import('../src/app/views/missing/index.vue') }

describe('resolveView', () => {
  it('框架菜单（addon_key 空）映射 app views', () => {
    const loader = resolveView(menu({ view_path: 'dashboard/index' }), appViews, {})
    expect(loader).toBe(appViews['/src/app/views/dashboard/index.vue'])
  })

  it('插件菜单映射 addons/<key>/views/<view_path>', () => {
    const loader = resolveView(
      menu({ addon_key: 'demo', view_path: 'note/index' }), appViews, addonViews,
    )
    expect(loader).toBe(addonViews['/src/addons/demo/views/note/index.vue'])
  })

  it('插件组件缺失落到 missing 兜底（未重构建场景）', () => {
    const loader = resolveView(
      menu({ addon_key: 'demo', view_path: 'ghost/index' }), appViews, addonViews,
    )
    expect(loader).toBe(missingView)
  })

  it('框架组件缺失同样落到 missing', () => {
    const loader = resolveView(menu({ view_path: 'ghost/index' }), appViews, addonViews)
    expect(loader).toBe(missingView)
  })
})
