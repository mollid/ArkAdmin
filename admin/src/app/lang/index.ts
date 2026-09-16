import { createI18n } from 'vue-i18n'
import { mergeAddonLangs, type LocalePack } from './mergeAddonLangs'

const zhCn: LocalePack = {
  app: { title: 'ArkAdmin 方舟后台' },
  login: { title: '登录', username: '用户名', password: '密码', submit: '登 录',
    usernameRequired: '请输入用户名', passwordRequired: '请输入密码' },
  common: { confirm: '确定', cancel: '取消', create: '新增', edit: '编辑', delete: '删除',
    search: '搜索', reset: '重置', success: '操作成功', upload: '上传', copy: '复制链接',
    copied: '已复制', copyFailed: '复制失败，请手动复制', choose: '选择', preview: '预览', tip: '提示',
    deleteConfirm: '确认删除 {name}？', attachmentLibrary: '素材库' },
}

// §7.3 插件语言包：admin/src/addons/<key>/lang/zh-cn.ts 整体并入 zh-cn.<key> 命名空间。
// eager 静态合入（构建期定死，无运行时开销）；未安装任何插件时 glob 为空
const addonLangs = import.meta.glob('/src/addons/*/lang/zh-cn.ts', { eager: true }) as Record<
  string,
  { default?: unknown }
>
mergeAddonLangs(zhCn, addonLangs)

const i18n = createI18n({
  legacy: false,
  locale: 'zh-cn',
  messages: { 'zh-cn': zhCn },
})

export default i18n
