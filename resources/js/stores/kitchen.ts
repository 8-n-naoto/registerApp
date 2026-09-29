import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { fetchKitchenOrders } from '@/api/orders'
import type { Order, PollingState } from '@/types/api'

export const POLL_MS = 10_000 // 12 §6.1.1 間隔は 10 秒で固定
export const HIGHLIGHT_MS = 5_000 // 12 §6.1.3 手順 7
export const OFFLINE_AFTER = 3 // 12 §6.1.3 手順 6 連続 3 回の失敗で帯を出す
export const MAX_DONE = 30
/** setTimeout の上限（約 24.8 日）を超えないように */
const MAX_DELAY_MS = 2_147_000_000

/**
 * S14 厨房の状態（12 §6.1.3・§8.10）。画面が start() / stop() を呼び、タイマーと visibilitychange はここで持つ。
 * 自動更新の ON / OFF はサーバーの polling に従う（端末の時計は next_change_at までの待ち時間にだけ使い、サーバー時刻との差を補正する）
 */
export const useKitchenStore = defineStore('kitchen', () => {
  const inProgress = ref<Order[]>([])
  const done = ref<Order[]>([])
  const pendingCount = ref(0)
  const polling = ref<PollingState | null>(null)
  const etag = ref<string | null>(null)
  /** サーバー時刻 − 端末の時刻（ミリ秒） */
  const clockOffset = ref(0)
  /** 最後に応答を受け取った時刻（端末の時刻 + 補正） */
  const lastUpdatedAt = ref<number | null>(null)
  const failures = ref(0)
  const loaded = ref(false)
  const loading = ref(false)
  /** 5 秒間強調する新しい注文 */
  const highlighted = ref<Set<number>>(new Set())

  const offline = computed(() => failures.value >= OFFLINE_AFTER)

  let running = false
  let timer: ReturnType<typeof setTimeout> | null = null
  let inflight: Promise<void> | null = null
  let onNewOrders: (ids: number[]) => void = () => {}
  const highlightTimers = new Map<number, ReturnType<typeof setTimeout>>()

  function serverNow(): number {
    return Date.now() + clockOffset.value
  }

  function clearTimer(): void {
    if (timer !== null) clearTimeout(timer)
    timer = null
  }

  function highlight(ids: number[]): void {
    if (ids.length === 0) return
    highlighted.value = new Set([...highlighted.value, ...ids])
    for (const id of ids) {
      const old = highlightTimers.get(id)
      if (old !== undefined) clearTimeout(old)
      highlightTimers.set(id, setTimeout(() => {
        highlightTimers.delete(id)
        const next = new Set(highlighted.value)
        next.delete(id)
        highlighted.value = next
      }, HIGHLIGHT_MS))
    }
  }

  async function fetchOnce(): Promise<void> {
    try {
      const res = await fetchKitchenOrders(etag.value)
      etag.value = res.etag
      if (res.changed) {
        const data = res.data
        const known = new Set([...inProgress.value, ...done.value].map((o) => o.id))
        const fresh = loaded.value ? data.in_progress.filter((o) => !known.has(o.id)).map((o) => o.id) : []
        inProgress.value = data.in_progress
        done.value = data.done
        pendingCount.value = data.pending_count
        polling.value = data.polling
        const serverTime = Date.parse(data.server_time)
        if (Number.isFinite(serverTime)) clockOffset.value = serverTime - Date.now()
        loaded.value = true
        highlight(fresh)
        if (fresh.length > 0) onNewOrders(fresh)
      }
      failures.value = 0
      lastUpdatedAt.value = serverNow()
    } catch {
      failures.value += 1
    }
  }

  /** 1 回取得する（重なったら実行中のものを待つ） */
  function refresh(): Promise<void> {
    if (inflight) return inflight
    loading.value = true
    inflight = fetchOnce().finally(() => {
      inflight = null
      loading.value = false
    })
    return inflight
  }

  /** 次に呼ぶ時刻を決める（12 §6.1.3 手順 2・3・6） */
  function schedule(): void {
    clearTimer()
    if (!running || document.visibilityState === 'hidden') return
    const state = polling.value
    if (state?.active === true || (failures.value > 0 && !loaded.value)) {
      // ON のとき、または一度も読めていないときは 10 秒後
      timer = setTimeout(tick, POLL_MS)
      return
    }
    if (state?.next_change_at) {
      const at = Date.parse(state.next_change_at)
      if (Number.isFinite(at)) {
        timer = setTimeout(tick, Math.min(Math.max(at - serverNow(), 0) + 1000, MAX_DELAY_MS))
      }
    }
  }

  async function tick(): Promise<void> {
    timer = null
    await refresh()
    schedule()
  }

  /** ［更新］：ON / OFF に関係なく 1 回呼ぶ（手順 5） */
  async function refreshNow(): Promise<void> {
    await refresh()
    schedule()
  }

  function onVisibility(): void {
    if (document.visibilityState === 'hidden') clearTimer()
    else void tick()
  }

  function start(options: { onNew?: (ids: number[]) => void } = {}): void {
    if (running) return
    running = true
    onNewOrders = options.onNew ?? (() => {})
    document.addEventListener('visibilitychange', onVisibility)
    void tick()
  }

  function stop(): void {
    running = false
    onNewOrders = () => {}
    clearTimer()
    document.removeEventListener('visibilitychange', onVisibility)
    for (const t of highlightTimers.values()) clearTimeout(t)
    highlightTimers.clear()
    highlighted.value = new Set()
  }

  /** 操作（#53・#54）の応答で 1 件を置き換える。完了したら完了へ、未提供に戻したら調理中へ移す */
  function applyOrder(order: Order): void {
    const rest = inProgress.value.filter((o) => o.id !== order.id)
    const restDone = done.value.filter((o) => o.id !== order.id)
    if (order.status !== 'active') {
      inProgress.value = rest
      done.value = restDone
    } else if (order.served_at !== null) {
      inProgress.value = rest
      done.value = [order, ...restDone].slice(0, MAX_DONE)
    } else {
      done.value = restDone
      inProgress.value = [...rest, order].sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id - b.id)
    }
  }

  return {
    inProgress, done, pendingCount, polling, etag, clockOffset, lastUpdatedAt, failures, loaded, loading, highlighted, offline,
    serverNow, refreshNow, start, stop, applyOrder,
  }
})
