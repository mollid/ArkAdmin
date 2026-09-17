import { describe, expect, it } from 'vitest'
import { widgetComponentKey } from '../src/app/utils/widget'

describe('widgetComponentKey', () => {
  it('按插件命名空间寻址组件文件', () => {
    expect(widgetComponentKey('op_logs', 'today')).toBe('/src/addons/op_logs/views/widgets/today.vue')
  })

  it('不同插件同名组件互不冲突（路径含 addon 命名空间）', () => {
    expect(widgetComponentKey('a', 'card')).not.toBe(widgetComponentKey('b', 'card'))
  })
})
