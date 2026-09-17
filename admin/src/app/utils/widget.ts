/** Widget 组件寻址（harness 规格 §3.1）：与菜单 resolveView 同构的纯函数，便于单测 */

export function widgetComponentKey(addon: string, component: string): string {
  return `/src/addons/${addon}/views/widgets/${component}.vue`
}
