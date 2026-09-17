import request from '@/app/api/request'

export interface OpLogRow {
  id: number
  admin_id: number | null
  route: string
  method: string
  params: Record<string, unknown>
  status_code: number
  duration_ms: number
  ip: string | null
  created_at: string | null
  admin?: { id: number; username: string } | null
}

export const opLogApi = {
  list: (params: Record<string, unknown>) =>
    request.get<never, { list: OpLogRow[]; total: number }>('/addon/op_logs/logs', { params }),
  today: () => request.get<never, { operations: number; logins: number }>('/addon/op_logs/today'),
}
