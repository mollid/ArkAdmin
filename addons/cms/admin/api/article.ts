import request from '@/app/api/request'

export interface ArticleRow {
  id: number
  category_id: number
  title: string
  summary: string
  cover: string
  cover_url: string
  tags: string[]
  status: number
  published_at: string | null
  category?: { id: number; name: string }
  content?: string
}

export interface ArticlePayload {
  category_id: number
  title: string
  summary?: string
  content?: string
  cover?: string
  tags?: string[]
  status: number
}

export const articleApi = {
  // 列表不含 content（编辑经 show 取全量）
  list: (params: Record<string, unknown>) =>
    request.get<never, { list: ArticleRow[]; total: number }>('/addon/cms/articles', { params }),
  show: (id: number) => request.get<never, ArticleRow>(`/addon/cms/articles/${id}`),
  store: (data: ArticlePayload) => request.post<never, { id: number }>('/addon/cms/articles', data),
  update: (id: number, data: ArticlePayload) => request.put<never, unknown>(`/addon/cms/articles/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/addon/cms/articles/${id}`),
}
