import request from '@/app/api/request'

export interface Note {
  id: number
  admin_id: number
  content: string
  created_at: string
  updated_at: string
}

export const noteApi = {
  list: () => request.get<never, { list: Note[] }>('/addon/demo/notes'),
  store: (data: { content: string }) => request.post<never, { id: number }>('/addon/demo/notes', data),
}
