import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { createOfflineSale, type OfflineSaleInput } from '@/api/register'
import { ja } from '@/i18n/ja'
import { errorBody, errorStatus, isNetworkError } from '@/lib/apiError'
import { kvDelete, kvGet, kvList, kvSet, requestPersistentStorage } from '@/lib/offlineDb'
import type { Sale } from '@/types/api'

/**
 * 14 §7.2 オフライン会計の送信待ち（outbox）。
 * - 1 件 = 1 キー（outbox:<店舗 ID>:<client_uuid>）。サーバーが 200 / 201 を返すまで消さない
 * - 送るのは古い順に 1 件ずつ。通信できない・5xx・429 はそこで止めて後で再送（30 秒ごと・online・起動時）
 * - 401 / 419（ログイン切れ）は「ログインし直すと送る」状態にして止める
 * - 422 / 409 / 404 / 403 は自動では再送しない（failed）。［もう一度送る］か［書き出す］で扱う
 */

export const OUTBOX_VERSION = 1
export const RETRY_INTERVAL_MS = 30_000

export type OutboxStatus = 'pending' | 'failed'

export interface OutboxEntry {
  version: number
  store_id: number
  client_uuid: string
  created_at: string // 端末で記録した時刻（ISO 8601）
  input: OfflineSaleInput
  /** 完了の表示・一覧用に端末で組み立てた会計（id は 0） */
  sale: Sale
  status: OutboxStatus
  attempts: number
  last_error: string | null
}

function keyOf(storeId: number, uuid: string): string {
  return `outbox:${storeId}:${uuid}`
}

function isEntry(v: unknown): v is OutboxEntry {
  if (typeof v !== 'object' || v === null) return false
  const r = v as Record<string, unknown>
  return Number.isSafeInteger(r.store_id) && typeof r.client_uuid === 'string' && typeof r.created_at === 'string'
    && typeof r.input === 'object' && r.input !== null && typeof r.sale === 'object' && r.sale !== null
    && (r.status === 'pending' || r.status === 'failed')
}

export const useOutboxStore = defineStore('outbox', () => {
  const storeId = ref<number | null>(null)
  const entries = ref<OutboxEntry[]>([])
  const syncing = ref(false)
  /** ログインが切れていて送れない（ログインし直すと送る） */
  const authNeeded = ref(false)
  /** 最後に送れなかった理由（通信できない等。failed の個別の理由は各エントリ） */
  const lastError = ref<string | null>(null)
  /** 送れた会計の client_uuid → サーバーの会計 ID（直後の［取り消す］で使う。端末を閉じたら忘れてよい） */
  const synced = new Map<string, number>()
  /** 送信中の client_uuid と、その送信の終わり */
  const inflight = new Map<string, Promise<void>>()
  let timer: ReturnType<typeof setInterval> | null = null
  let listening = false

  const pending = computed(() => entries.value.filter((e) => e.status === 'pending'))
  const failed = computed(() => entries.value.filter((e) => e.status === 'failed'))
  const count = computed(() => entries.value.length)

  function sortEntries(list: OutboxEntry[]): OutboxEntry[] {
    return [...list].sort((a, b) => a.created_at.localeCompare(b.created_at) || a.client_uuid.localeCompare(b.client_uuid))
  }

  /** 端末に残っている送信待ちを読み直す */
  async function reload(): Promise<void> {
    const id = storeId.value
    if (id === null) {
      entries.value = []
      return
    }
    const map = await kvList(`outbox:${id}:`)
    if (storeId.value !== id) return
    entries.value = sortEntries([...map.values()].filter(isEntry))
  }

  const onOnline = (): void => {
    void syncNow()
  }

  /** ログインした店舗で使い始める（店舗が変われば読み直す）。null で止める（送信待ちは端末に残る） */
  async function init(id: number | null): Promise<void> {
    if (storeId.value === id && (id === null || timer !== null)) return
    storeId.value = id
    authNeeded.value = false
    lastError.value = null
    if (id === null) {
      stop()
      entries.value = []
      return
    }
    void requestPersistentStorage()
    if (!listening && typeof window !== 'undefined') {
      window.addEventListener('online', onOnline)
      listening = true
    }
    timer ??= setInterval(() => {
      if (pending.value.length > 0) void syncNow()
    }, RETRY_INTERVAL_MS)
    await reload()
    if (pending.value.length > 0) void syncNow()
  }

  function stop(): void {
    if (timer !== null) clearInterval(timer)
    timer = null
    if (listening && typeof window !== 'undefined') window.removeEventListener('online', onOnline)
    listening = false
  }

  /** 送信待ちに加える。端末に書けなければ例外（呼び出し側は会計を確定扱いにしない） */
  async function add(entry: OutboxEntry): Promise<void> {
    await kvSet(keyOf(entry.store_id, entry.client_uuid), entry)
    if (entry.store_id === storeId.value) {
      entries.value = sortEntries([...entries.value.filter((e) => e.client_uuid !== entry.client_uuid), entry])
    }
  }

  async function save(entry: OutboxEntry): Promise<void> {
    try {
      await kvSet(keyOf(entry.store_id, entry.client_uuid), entry)
    } catch {
      // 状態の書き換えに失敗しても、元の内容は端末に残っている
    }
  }

  async function removeEntry(entry: OutboxEntry): Promise<void> {
    await kvDelete(keyOf(entry.store_id, entry.client_uuid))
    entries.value = entries.value.filter((e) => e.client_uuid !== entry.client_uuid)
  }

  /** 1 件送る。続けて送ってよいかを返す */
  async function sendOne(entry: OutboxEntry): Promise<boolean> {
    try {
      const sale = await createOfflineSale(entry.input)
      synced.set(entry.client_uuid, sale.id)
      await removeEntry(entry)
      return true
    } catch (err) {
      const status = errorStatus(err)
      if (isNetworkError(err) || status === null || status >= 500 || status === 429) {
        lastError.value = ja.outbox.networkError
        return false
      }
      if (status === 401 || status === 419) {
        authNeeded.value = true
        return false
      }
      // 内容に問題がある（422 / 409）か、送れない（403 / 404）：自動では送り直さない
      entry.status = 'failed'
      entry.attempts += 1
      entry.last_error = errorBody(err)?.message ?? `HTTP ${status}`
      entries.value = [...entries.value]
      await save(entry)
      return true
    }
  }

  /** 送信待ちを古い順に送る。同時には 1 回だけ */
  async function syncNow(): Promise<void> {
    if (syncing.value || storeId.value === null) return
    syncing.value = true
    lastError.value = null
    authNeeded.value = false
    try {
      for (const entry of [...pending.value]) {
        if (!entries.value.includes(entry)) continue // 送る前に［取り消す］で消された
        let done!: () => void
        inflight.set(entry.client_uuid, new Promise<void>((resolve) => (done = resolve)))
        let next: boolean
        try {
          entry.attempts += 1
          next = await sendOne(entry)
        } finally {
          inflight.delete(entry.client_uuid)
          done()
        }
        if (!next) break
      }
    } finally {
      syncing.value = false
    }
  }

  /** failed をもう一度送る（内容を直さずに送り直す。サーバーのマスタが戻っていれば通る） */
  async function retryFailed(): Promise<void> {
    for (const entry of failed.value) {
      entry.status = 'pending'
      entry.last_error = null
      await save(entry)
    }
    entries.value = [...entries.value]
    await syncNow()
  }

  /**
   * 直後の［取り消す］：まだ送っていなければ端末から消して 'removed'、送れていればサーバーの会計 ID、どちらでもなければ null。
   * 送信中なら終わるのを待ってから決める
   */
  async function discard(store: number, uuid: string): Promise<'removed' | number | null> {
    const running = inflight.get(uuid)
    if (running) await running
    const saleId = synced.get(uuid)
    if (saleId !== undefined) return saleId
    const entry = entries.value.find((e) => e.client_uuid === uuid)
    if (entry) {
      await removeEntry(entry)
      return 'removed'
    }
    // 一覧をまだ読んでいない（店舗の切替の直後など）：端末に残っていれば消す
    const key = keyOf(store, uuid)
    if (!isEntry(await kvGet(key))) return null
    await kvDelete(key)
    return 'removed'
  }

  /** 送信待ちを JSON で書き出す（端末が壊れる前の控え。14 §8） */
  function exportJson(): string {
    return JSON.stringify({
      kind: 'regi-offline-sales',
      version: OUTBOX_VERSION,
      exported_at: new Date().toISOString(),
      store_id: storeId.value,
      entries: entries.value.map((e) => ({ status: e.status, last_error: e.last_error, created_at: e.created_at, input: e.input })),
    }, null, 2)
  }

  /** failed を端末から消す（書き出してから。画面で確認してから呼ぶ） */
  async function deleteFailed(uuid: string): Promise<void> {
    const entry = failed.value.find((e) => e.client_uuid === uuid)
    if (entry) await removeEntry(entry)
  }

  return {
    storeId, entries, syncing, authNeeded, lastError, pending, failed, count,
    init, stop, reload, add, syncNow, retryFailed, discard, exportJson, deleteFailed,
  }
})
