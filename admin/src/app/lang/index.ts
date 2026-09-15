import { createI18n } from 'vue-i18n'

const i18n = createI18n({
  legacy: false,
  locale: 'zh-cn',
  messages: {
    'zh-cn': {
      app: { title: 'ArkAdmin 方舟后台' },
      login: { title: '登录', username: '用户名', password: '密码', submit: '登 录',
        usernameRequired: '请输入用户名', passwordRequired: '请输入密码' },
      common: { confirm: '确定', cancel: '取消', create: '新增', edit: '编辑', delete: '删除',
        search: '搜索', reset: '重置', success: '操作成功' },
    },
  },
})

export default i18n
