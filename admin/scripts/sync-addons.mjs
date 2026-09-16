#!/usr/bin/env node
/**
 * 构建前同步插件前端：addons/<key>/admin/ → admin/src/addons/<key>/（§6.6）
 *
 * 与后端 `php artisan addon:install` 的 AddonInstaller::syncFrontend 同源同产物。
 * CI（EdgeOne Makers / GitHub Actions）从干净克隆构建时 admin/src/addons/ 不存在
 * （见根 .gitignore：插件前端是安装期产物），若不先物化，vite 的
 * 前端两处 glob（视图与语言包，见 resolveView.ts / lang/index.ts）会扫不到
 * 插件视图与语言包 → 所有插件页面静默落进「组件缺失」兜底页。
 *
 * 幂等：每次全量覆盖，并清理已从 addons/ 移除的插件残留产物。
 */
import { cpSync, existsSync, mkdirSync, readdirSync, rmSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

// 路径一律相对本脚本解析，不依赖 cwd（EdgeOne/GitHub Actions 的 cwd 各不相同）
const adminDir = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const addonsRoot = resolve(adminDir, '..', 'addons')
const targetRoot = join(adminDir, 'src', 'addons')

/** 插件名规则与 AddonInfo 一致：^[a-z][a-z0-9_]*$（目录名即插件身份） */
const ADDON_NAME = /^[a-z][a-z0-9_]*$/

const names = existsSync(addonsRoot)
  ? readdirSync(addonsRoot, { withFileTypes: true })
      .filter((e) => e.isDirectory() && ADDON_NAME.test(e.name))
      .filter((e) => existsSync(join(addonsRoot, e.name, 'admin')))
      .map((e) => e.name)
      .sort()
  : []

mkdirSync(targetRoot, { recursive: true })

for (const entry of readdirSync(targetRoot, { withFileTypes: true })) {
  if (entry.isDirectory() && !names.includes(entry.name)) {
    rmSync(join(targetRoot, entry.name), { recursive: true, force: true })
    console.log(`[addons] 清理无源码残留：src/addons/${entry.name}`)
  }
}

for (const name of names) {
  const dest = join(targetRoot, name)
  rmSync(dest, { recursive: true, force: true })
  cpSync(join(addonsRoot, name, 'admin'), dest, { recursive: true })
  console.log(`[addons] ${name}: addons/${name}/admin → admin/src/addons/${name}`)
}

console.log(
  names.length
    ? `[addons] 同步完成，共 ${names.length} 个：${names.join(', ')}`
    : '[addons] addons/ 下没有带 admin/ 前端的插件，跳过',
)
