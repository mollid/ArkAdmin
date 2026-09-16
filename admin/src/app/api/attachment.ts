import request from './request'

export interface AttachmentRow {
  id: number
  name: string
  path: string
  disk: string
  mime: string
  size: number
  width: number | null
  height: number | null
  url: string
  created_at?: string
}

export const attachmentApi = {
  list: (params: Record<string, unknown>) =>
    request.get<never, { list: AttachmentRow[]; total: number }>('/attachments', { params }),
  upload: (file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    // axios 对 FormData 自动设置 multipart 边界，勿手动指定 Content-Type
    return request.post<never, AttachmentRow>('/attachments', fd)
  },
  destroy: (id: number) => request.delete<never, unknown>(`/attachments/${id}`),
}
