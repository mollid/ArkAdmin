import type { LocaleMessages } from 'vue-i18n'

/** 单个 locale 的语言包类型（vue-i18n 只导出复数形式，索引访问取值类型） */
export type LocalePack = LocaleMessages<any>[string]

/** 框架保留命名空间：插件语言包不得覆盖，否则会静默改掉框架文案 */
export const FRAMEWORK_NAMESPACES = ['app', 'login', 'common'] as const

/**
 * 合并插件语言包（§7.3）：admin/src/addons/<key>/lang/zh-cn.ts 的 default 导出
 * 整体并入 zh-cn.<key> 命名空间。冲突/形状非法一律告警跳过，绝不静默覆盖。
 */
export function mergeAddonLangs(
  base: LocalePack,
  entries: Record<string, { default?: unknown }>,
): LocalePack {
  for (const [path, mod] of Object.entries(entries)) {
    const key = /\/addons\/([^/]+)\/lang\//.exec(path)?.[1]
    if (!key) {
      console.warn(`[i18n] 无法从路径解析插件名，已跳过：${path}`)
      continue
    }
    if ((FRAMEWORK_NAMESPACES as readonly string[]).includes(key)) {
      console.warn(`[i18n] 插件 ${key} 与框架保留命名空间同名，已跳过以免覆盖框架文案`)
      continue
    }
    const pack = mod?.default
    if (pack === null || typeof pack !== 'object' || Array.isArray(pack)) {
      console.warn(`[i18n] 插件 ${key} 的语言包 default 导出不是对象，已跳过`)
      continue
    }
    base[key] = pack as LocalePack[string]
  }
  return base
}
