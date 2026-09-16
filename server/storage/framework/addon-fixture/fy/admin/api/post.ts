import request from '@/app/api/request'

/* ark:crud 生成（表 fy_posts） */

export interface PostRow {
  title: string
}

export const postApi = {
  // 列表不含长文本列（编辑经 show 取全量）
  list: (params: Record<string, unknown>) =>
    request.get<never, { list: PostRow[]; total: number }>('/addon/fy/posts', { params }),
  show: (id: number) => request.get<never, PostRow>(`/addon/fy/posts/${id}`),
  store: (data: Partial<PostRow>) => request.post<never, { id: number }>('/addon/fy/posts', data),
  update: (id: number, data: Partial<PostRow>) => request.put<never, unknown>(`/addon/fy/posts/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/addon/fy/posts/${id}`),
}
