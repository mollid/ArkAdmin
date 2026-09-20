import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { parseQuery, pickUrlKeys, serializeQuery, useListQuery } from '../src/app/composables/useListQuery'

describe('useListQuery URL 同步纯函数', () => {
  it('serializeQuery 丢弃空值键，保留有值键', () => {
    expect(serializeQuery({ page: 2, keyword: 'foo', status: undefined, date_from: null, per_page: '' }))
      .toEqual({ page: '2', keyword: 'foo' })
  })
  it('parseQuery 还原 defaults 中声明的键并做数字转型，未知键忽略', () => {
    const q = parseQuery({ page: '3', status: '0', keyword: 'x', junk: '1' }, { status: -1, keyword: '' })
    expect(q).toEqual({ page: 3, status: 0, keyword: 'x' })
  })
  it('parseQuery 空串数字键安全回退默认值', () => {
    expect(parseQuery({ page: '' }, { status: -1 }).page).toBe(1)
  })
  it('pickUrlKeys 仅取 page 与 urlKeys 白名单键', () => {
    expect(pickUrlKeys({ page: 3, keyword: 'x', status: 1, secret: 's' }, ['keyword']))
      .toEqual({ page: 3, keyword: 'x' })
  })
})

// —— composable 行为：mock vue-router（node 环境无组件实例，onMounted 警告静默，load 手动调用）——
const { replaceMock } = vi.hoisted(() => ({ replaceMock: vi.fn().mockResolvedValue(undefined) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: replaceMock }),
}))

describe('useListQuery composable', () => {
  beforeEach(() => {
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    replaceMock.mockClear()
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('load 写 URL 仅含 page 与 urlKeys 白名单键', async () => {
    const fetcher = vi.fn(async () => ({ list: [] as number[], total: 0 }))
    const { load } = useListQuery<number>(fetcher, { defaults: { status: -1 }, urlKeys: ['status'] })
    await load()
    expect(replaceMock).toHaveBeenCalledWith({ query: { page: '1', status: '-1' } })
  })

  it('load 竞态守卫：慢请求晚到被丢弃，loading 由最新请求复位', async () => {
    let resolveSlow!: (v: { list: number[]; total: number }) => void
    const fetcher = vi.fn()
      .mockImplementationOnce(() => new Promise<{ list: number[]; total: number }>((res) => { resolveSlow = res }))
      .mockImplementationOnce(async () => ({ list: [2], total: 1 }))

    const { rows, loading, load } = useListQuery<number>(fetcher, { defaults: {}, urlKeys: [] })
    const slow = load(1)
    await load(2)
    expect(rows.value).toEqual([2]) // 快请求结果已生效
    resolveSlow({ list: [1, 1], total: 99 })
    await slow
    expect(rows.value).toEqual([2]) // 慢响应未覆盖新数据
    expect(loading.value).toBe(false) // 最新请求已复位 loading
  })
})
