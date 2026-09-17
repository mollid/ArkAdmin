import { describe, expect, it } from 'vitest'
import { addonStatus, buildNotice, type AddonRow } from '../src/app/utils/addon'

function row(overrides: Partial<AddonRow>): AddonRow {
  return {
    name: 'demo', title: '演示', description: '', version: '0.1.0', installed_version: '0.1.0',
    installed: true, enabled: false, upgradable: false, system: false,
    dependencies: [], missing_dependencies: [], dependents: [], install_time: null,
    ...overrides,
  }
}

describe('addonStatus', () => {
  it('未安装/缺依赖/已停用/已启用/可升级/磁盘缺失 按序判定', () => {
    expect(addonStatus(row({ installed: false }))).toEqual({ text: '未安装', type: 'info' })
    expect(addonStatus(row({ installed: false, missing_dependencies: ['dep1'] })))
      .toEqual({ text: '缺少依赖 dep1', type: 'danger' })
    expect(addonStatus(row({}))).toEqual({ text: '已禁用', type: 'warning' })
    expect(addonStatus(row({ enabled: true }))).toEqual({ text: '已启用', type: 'success' })
    expect(addonStatus(row({ enabled: true, upgradable: true })))
      .toEqual({ text: '可升级', type: 'primary' })
    expect(addonStatus(row({ disk_missing: true }))).toEqual({ text: '磁盘缺失', type: 'danger' })
  })

  it('多个缺失依赖以顿号连接', () => {
    expect(addonStatus(row({ installed: false, missing_dependencies: ['a', 'b'] })).text)
      .toBe('缺少依赖 a、b')
  })
})

describe('buildNotice', () => {
  it('needs_build 时给出持久提示文案', () => {
    expect(buildNotice({ needs_build: true })).toContain('npm run build')
    expect(buildNotice({ needs_build: false })).toBe('')
    expect(buildNotice({})).toBe('')
  })
})
