import axios from 'axios'
import { ElMessage } from 'element-plus'

export const TOKEN_KEY = 'ark_token'

// 生产部署（如 EdgeOne Makers）前后端不同源，用 VITE_API_BASE 注入绝对地址；
// 未配置时保持相对路径走同源（dev 由 vite proxy 把 /api 转发到 :8080）
const baseURL = import.meta.env.VITE_API_BASE || '/api/admin'

const request = axios.create({ baseURL, timeout: 15000 })

request.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

request.interceptors.response.use(
  (resp) => {
    const body = resp.data
    if (body.code !== 0) {
      ElMessage.error(body.msg || '请求失败')
      return Promise.reject(new Error(body.msg))
    }
    return body.data
  },
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      if (location.pathname !== '/login') location.href = '/login'
    } else if (err.response?.status === 422) {
      const msg: string = err.response.data?.msg || '参数错误'
      ElMessage.error(msg)
    } else {
      ElMessage.error(err.response?.data?.msg || '网络错误')
    }
    return Promise.reject(err)
  },
)

export default request
