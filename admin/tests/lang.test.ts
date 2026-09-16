import { describe, expect, it, vi } from 'vitest'
import { FRAMEWORK_NAMESPACES, mergeAddonLangs } from '../src/app/lang/mergeAddonLangs'

describe('mergeAddonLangs', () => {
  it('插件语言包并入 zh-cn.<插件key> 命名空间', () => {
    const base = { common: { ok: '好的' } }
    mergeAddonLangs(base, {
      '/src/addons/demo/lang/zh-cn.ts': { default: { note: { title: '便签' } } },
    })
    expect((base as Record<string, unknown>).demo).toEqual({ note: { title: '便签' } })
    expect(base.common).toEqual({ ok: '好的' })
  })

  it.each([...FRAMEWORK_NAMESPACES])('拒绝用框架保留命名空间 %s 覆盖框架文案', (key) => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const base = { [key]: { title: '框架文案' } }
    mergeAddonLangs(base, {
      [`/src/addons/${key}/lang/zh-cn.ts`]: { default: { title: '插件劫持' } },
    })
    expect(base[key as 'app']).toEqual({ title: '框架文案' })
    expect(warn).toHaveBeenCalledOnce()
    warn.mockRestore()
  })

  it.each([
    ['default 缺失', {}],
    ['default 为字符串', { default: 'oops' }],
    ['default 为数组', { default: ['oops'] }],
    ['default 为 null', { default: null }],
  ])('语言包 %s 时告警并跳过', (_label, mod) => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const base: Record<string, unknown> = {}
    mergeAddonLangs(base, { '/src/addons/broken/lang/zh-cn.ts': mod })
    expect(base.broken).toBeUndefined()
    expect(warn).toHaveBeenCalledOnce()
    warn.mockRestore()
  })

  it('路径无法解析出插件名时告警并跳过', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const base: Record<string, unknown> = {}
    mergeAddonLangs(base, { '/somewhere/else.ts': { default: { a: 1 } } })
    expect(base).toEqual({})
    expect(warn).toHaveBeenCalledOnce()
    warn.mockRestore()
  })
})
