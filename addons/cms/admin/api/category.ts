import request from '@/app/api/request'

export interface CategoryNode {
  id: number
  parent_id: number
  name: string
  description: string
  sort: number
  is_show: boolean
  article_count: number
  children: CategoryNode[]
}

export interface CategoryPayload {
  parent_id?: number
  name: string
  description?: string
  sort?: number
  is_show?: boolean
}

export const categoryApi = {
  list: () => request.get<never, CategoryNode[]>('/addon/cms/categories'),
  store: (data: CategoryPayload) => request.post<never, { id: number }>('/addon/cms/categories', data),
  update: (id: number, data: CategoryPayload) => request.put<never, unknown>(`/addon/cms/categories/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/addon/cms/categories/${id}`),
}
