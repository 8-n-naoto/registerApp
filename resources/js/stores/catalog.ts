import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import { fetchBootstrap, type RegisterBootstrap } from '@/api/register'
import { isNetworkError } from '@/lib/apiError'
import { kvGet, kvSet } from '@/lib/offlineDb'

/** 14 §7.3 店舗ごとに端末へ残す、最後に受け取った bootstrap */
export function catalogCacheKey(storeId: number): string {
  return `catalog:${storeId}`
}

function isBootstrap(v: unknown, storeId: number): v is RegisterBootstrap {
  if (typeof v !== 'object' || v === null) return false
  const r = v as Record<string, unknown>
  const store = r.store as Record<string, unknown> | null | undefined
  return typeof store === 'object' && store !== null && store.id === storeId
    && Array.isArray(r.products) && Array.isArray(r.tax_types) && Array.isArray(r.payment_methods) && Array.isArray(r.categories)
}

/**
 * GET /register/bootstrap の共有（レジ S02 と注文の入力 S13 が同じ応答を使う）。
 * - 同時に呼ばれたら 1 回の通信にまとめる
 * - 最後に受け取った応答を持ち、まだ自分の分を持たない画面はそれを先に描いてから最新を取り直す
 *   （画面を移ったときに「読み込み中」を出さない。価格・在庫の最終判定はサーバーの 409・422）
 * - 14 §7.3 受け取った応答を端末に残し、通信できないときは（cacheStoreId の店舗の）残した応答を返す
 */
export const useCatalogStore = defineStore('catalog', () => {
  const data = shallowRef<RegisterBootstrap | null>(null)
  /** 端末に残した応答で動いている（通信できなかった） */
  const fromCache = ref(false)
  /** 通信できないときに読む店舗（ログイン中の店舗。main.ts が設定する） */
  const cacheStoreId = ref<number | null>(null)
  let inflight: Promise<RegisterBootstrap> | null = null
  /** clear() のたびに進める。ログアウト前に出した要求の応答を、次にログインした人の分として残さない */
  let generation = 0

  async function fallback(err: unknown, gen: number): Promise<RegisterBootstrap> {
    const storeId = cacheStoreId.value
    if (!isNetworkError(err) || storeId === null) throw err
    if (data.value?.store.id === storeId) {
      fromCache.value = true
      return data.value
    }
    let cached: unknown
    try {
      cached = await kvGet(catalogCacheKey(storeId))
    } catch {
      throw err
    }
    if (!isBootstrap(cached, storeId)) throw err
    if (gen === generation) {
      data.value = cached
      fromCache.value = true
    }
    return cached
  }

  function fetch(): Promise<RegisterBootstrap> {
    if (inflight) return inflight
    const gen = generation
    const request = fetchBootstrap()
      .then((res) => {
        if (gen === generation) {
          data.value = res
          fromCache.value = false
          kvSet(catalogCacheKey(res.store.id), res).catch(() => undefined) // 残せなくても画面は動く
        }
        return res
      })
      .catch((err: unknown) => fallback(err, gen))
      .finally(() => {
        if (inflight === request) inflight = null
      })
    inflight = request
    return request
  }

  /** 画面を開く前の先読み。失敗は画面側の読み込みに任せる */
  function prefetch(): void {
    if (data.value === null) fetch().catch(() => undefined)
  }

  /** ログアウト・店舗の切替。端末に残した応答は消さない（同じ店舗で通信できないまま起動したときに使う） */
  function clear(): void {
    generation += 1
    inflight = null
    data.value = null
    fromCache.value = false
  }

  return { data, fromCache, cacheStoreId, fetch, prefetch, clear }
})
