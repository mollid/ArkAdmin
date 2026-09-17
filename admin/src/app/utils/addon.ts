export interface AddonRow {
  name: string
  title: string
  description: string
  version: string
  installed_version: string | null
  installed: boolean
  enabled: boolean
  upgradable: boolean
  system: boolean
  dependencies: string[]
  missing_dependencies: string[]
  dependents: string[]
  install_time: string | null
  disk_missing?: boolean
}

export interface AddonStatus {
  text: string
  type: 'success' | 'warning' | 'info' | 'danger' | 'primary'
}

/** 行状态标签：磁盘缺失 > 缺依赖 > 未安装 > 可升级 > 已启用 > 已禁用（判定顺序即展示优先级） */
export function addonStatus(row: AddonRow): AddonStatus {
  if (row.disk_missing) return { text: '磁盘缺失', type: 'danger' }
  if (row.missing_dependencies.length > 0) {
    return { text: `缺少依赖 ${row.missing_dependencies.join('、')}`, type: 'danger' }
  }
  if (!row.installed) return { text: '未安装', type: 'info' }
  if (row.upgradable) return { text: '可升级', type: 'primary' }
  return row.enabled ? { text: '已启用', type: 'success' } : { text: '已禁用', type: 'warning' }
}

/** 写操作响应 → 顶部持久提示（插件前端同步后需重新构建才能生效于生产 dist） */
export function buildNotice(resp: { needs_build?: boolean } | null): string {
  return resp?.needs_build ? '插件前端已同步，请执行 cd admin && npm run build 重新构建后台' : ''
}
