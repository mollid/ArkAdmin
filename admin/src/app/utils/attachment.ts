/** 文件大小人性化展示：B/KB/MB/GB，KB 起保留一位小数 */
export function formatFileSize(bytes: number): string {
  if (!bytes || bytes <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  const v = bytes / 1024 ** i
  return `${i === 0 ? Math.round(v) : v.toFixed(1)} ${units[i]}`
}
