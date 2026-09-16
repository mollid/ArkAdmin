import request from '@/app/api/request'

/* ark:crud 生成（表 cms_notices） */

export interface NoticeRow {
  title: string
  body: string
  is_pinned: boolean
}

export const noticeApi = {
  // 列表不含长文本列（编辑经 show 取全量）
  list: (params: Record<string, unknown>) =>
    request.get<never, { list: NoticeRow[]; total: number }>('/addon/cms/notices', { params }),
  show: (id: number) => request.get<never, NoticeRow>(`/addon/cms/notices/${id}`),
  store: (data: Partial<NoticeRow>) => request.post<never, { id: number }>('/addon/cms/notices', data),
  update: (id: number, data: Partial<NoticeRow>) => request.put<never, unknown>(`/addon/cms/notices/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/addon/cms/notices/${id}`),
}
