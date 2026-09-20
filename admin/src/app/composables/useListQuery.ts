import { onMounted, reactive, ref, type Ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

/** 查询对象 → 路由 query：丢弃空值键（undefined/null/''），其余字符串化 */
export function serializeQuery(query: Record<string, unknown>): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null || v === '') continue
    out[k] = String(v)
  }
  return out
}

/** 路由 query → 查询对象：仅取 defaults 声明的键；数字键安全转型（NaN/空回退默认）。
 * page 由 useListQuery 内部管理但恒参与 URL 同步：defaults 未声明时按默认 1 隐含处理 */
export function parseQuery(
  url: Record<string, unknown>,
  defaults: Record<string, unknown>,
): Record<string, unknown> {
  const dflts = Object.prototype.hasOwnProperty.call(defaults, 'page')
    ? defaults
    : { ...defaults, page: 1 }
  const out: Record<string, unknown> = {}
  for (const [k, dflt] of Object.entries(dflts)) {
    const raw = url[k]
    if (raw === undefined || raw === null || raw === '') { out[k] = dflt; continue }
    if (typeof dflt === 'number') {
      const n = Number(raw)
      out[k] = Number.isFinite(n) ? n : dflt
    } else {
      out[k] = raw
    }
  }
  return out
}

/** 组装参与 URL 同步的查询对象：page 恒参与，其余仅取 urlKeys 白名单键 */
export function pickUrlKeys(
  query: Record<string, unknown>,
  urlKeys: string[],
): Record<string, unknown> {
  const picked: Record<string, unknown> = { page: query.page }
  for (const k of urlKeys) picked[k] = query[k]
  return picked
}

export interface UseListQueryOptions {
  /** 筛选默认值（不含 page；page 由内部管理且默认参与 URL 同步） */
  defaults: Record<string, unknown>
  /** 参与 URL 同步的筛选键（page 恒同步） */
  urlKeys: string[]
}

/**
 * 服务端分页列表的统一查询状态：URL 同步（筛选+分页，Q11A）+ 首载/切换 loading 两态。
 * - firstLoading：进程内首次拉取（骨架屏用，true 时页面可不渲染旧内容）
 * - loading：任意请求中（分页/筛选切换保留旧内容，仅细条 loading）
 */
export function useListQuery<T>(
  fetcher: (params: Record<string, unknown>) => Promise<{ list: T[]; total: number }>,
  options: UseListQueryOptions,
): {
  rows: Ref<T[]>
  total: Ref<number>
  query: Record<string, unknown> & { page: number }
  firstLoading: Ref<boolean>
  loading: Ref<boolean>
  load: (page?: number) => Promise<void>
  reset: () => Promise<void>
} {
  const route = useRoute()
  const router = useRouter()
  const rows = ref<T[]>([]) as Ref<T[]>
  const total = ref(0)
  const firstLoading = ref(true)
  const loading = ref(false)

  const restored = parseQuery(route.query as Record<string, unknown>, options.defaults)
  const query = reactive({ ...restored, page: Number(restored.page ?? 1) || 1 } as Record<string, unknown> & { page: number })

  let requestSeq = 0

  async function load(page?: number): Promise<void> {
    const seq = ++requestSeq
    if (page !== undefined) query.page = page
    loading.value = true
    try {
      const params = { ...query }
      const data = await fetcher(params)
      if (seq !== requestSeq) return // 旧响应晚到：丢弃，不覆盖新数据、不复位 loading
      rows.value = data.list
      total.value = data.total
      await router.replace({ query: serializeQuery(pickUrlKeys(query, options.urlKeys)) })
    } finally {
      if (seq === requestSeq) {
        loading.value = false
        firstLoading.value = false
      }
    }
  }

  async function reset(): Promise<void> {
    Object.assign(query, options.defaults, { page: 1 })
    await load()
  }

  onMounted(() => { void load() })

  return { rows, total, query, firstLoading, loading, load, reset }
}
