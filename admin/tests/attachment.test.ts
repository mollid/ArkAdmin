import { describe, expect, it } from 'vitest'
import { formatFileSize } from '../src/app/utils/attachment'

describe('formatFileSize', () => {
  it.each([
    [0, '0 B'],
    [1, '1 B'],
    [999, '999 B'],
    [1024, '1.0 KB'],
    [1536, '1.5 KB'],
    [1048576, '1.0 MB'],
    [1572864, '1.5 MB'],
    [1073741824, '1.0 GB'],
    [5 * 1024 ** 4, '5120.0 GB'], // 超出单位表封顶 GB
  ])('%i -> %s', (bytes, expected) => {
    expect(formatFileSize(bytes)).toBe(expected)
  })
})
