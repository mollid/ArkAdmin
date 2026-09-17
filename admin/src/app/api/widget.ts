import request from './request'

export interface WidgetItem {
  key: string
  addon: string
  component: string
  title: string
  permission?: string
  sort?: number
}

/** Widget 清单（已按当前管理员权限过滤，harness 规格 §3.1） */
export const widgetApi = {
  list: () => request.get<never, WidgetItem[]>('/widgets'),
}
