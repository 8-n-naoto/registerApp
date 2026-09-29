import { isAxiosError } from 'axios'
import type { ApiErrorBody } from '@/types/api'

/** API のエラーから、画面で使う値を取り出す（06 §1.3） */

export function errorStatus(err: unknown): number | null {
  return isAxiosError(err) ? (err.response?.status ?? null) : null
}

export function errorBody(err: unknown): ApiErrorBody | null {
  if (!isAxiosError<ApiErrorBody>(err)) return null
  const data = err.response?.data
  return data && typeof data === 'object' && typeof data.message === 'string' ? data : null
}

/** 422 の項目ごとの最初のメッセージ */
export function fieldErrors(err: unknown): Record<string, string> {
  const errors = errorBody(err)?.errors ?? {}
  const result: Record<string, string> = {}
  for (const [key, messages] of Object.entries(errors)) {
    const first = messages[0]
    if (first !== undefined) result[key] = first
  }
  return result
}

/** 429 の Retry-After（秒）。無ければ 60 */
export function retryAfterSeconds(err: unknown): number {
  const raw: unknown = isAxiosError(err) ? err.response?.headers['retry-after'] : undefined
  const sec = Number(raw)
  return Number.isFinite(sec) && sec > 0 ? Math.ceil(sec) : 60
}

/** 応答が無い（通信できない・タイムアウト） */
export function isNetworkError(err: unknown): boolean {
  return isAxiosError(err) && err.response === undefined
}
