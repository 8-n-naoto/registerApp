import axios, { AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios'
import { basePath } from '@/lib/basePath'
import type { ApiErrorBody } from '@/types/api'

declare module 'axios' {
  interface InternalAxiosRequestConfig {
    _csrfRetried?: boolean
  }
}

/** 唯一 URL を組み立てる場所（06 §1.1） */
export const apiBaseUrl = `${basePath}/api`
const csrfCookieUrl = `${basePath}/sanctum/csrf-cookie`

export const http: AxiosInstance = axios.create({
  baseURL: apiBaseUrl,
  withCredentials: true,
  withXSRFToken: true, // XSRF-TOKEN Cookie → X-XSRF-TOKEN ヘッダー（06 §1.2）
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  timeout: 15000,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

/** 起動時に 1 回呼ぶ。XSRF-TOKEN Cookie を受け取る */
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get(csrfCookieUrl, { withCredentials: true, headers: { Accept: 'application/json' } })
}

/** ルーター・ストアへの依存を持たないよう、401 / 停止時・通信エラーの処理は外から登録する */
type Handler = (err: AxiosError<ApiErrorBody>) => void
let onUnauthorized: Handler = () => {}
let onSuspended: Handler = () => {}
let onNetworkError: (failed: boolean) => void = () => {}
export function setAuthHandlers(h: { unauthorized: Handler; suspended: Handler; network: (failed: boolean) => void }): void {
  onUnauthorized = h.unauthorized
  onSuspended = h.suspended
  onNetworkError = h.network
}

http.interceptors.response.use(
  (res) => {
    onNetworkError(false)
    return res
  },
  async (error: AxiosError<ApiErrorBody>) => {
    // 応答が無い（通信できない・タイムアウト）：帯を出す。自動の再送はしない（08 §8）
    if (!error.response) {
      onNetworkError(true)
      return Promise.reject(error)
    }
    onNetworkError(false)

    const status = error.response?.status
    const config = error.config as InternalAxiosRequestConfig | undefined

    // 419：csrf-cookie を取り直して 1 回だけ再送
    if (status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true
      await ensureCsrfCookie()
      return http.request(config)
    }

    // 401：ログイン画面へ（GET /me の起動時判定はガード側で扱うので呼び出し側で握る）
    if (status === 401) {
      onUnauthorized(error)
    }

    // 403 の停止系：ログアウト扱いにしてログイン画面でメッセージを出す
    const code = error.response?.data?.code
    if (status === 403 && (code === 'STORE_SUSPENDED' || code === 'ACCOUNT_DISABLED')) {
      onSuspended(error)
    }

    return Promise.reject(error)
  },
)
