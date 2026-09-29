import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fmt, ja } from '@/i18n/ja'

const STORAGE_KEY = 'regi:admin:viewing'

function restore(): { id: number; name: string } | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)
    if (raw === null) return null
    const value: unknown = JSON.parse(raw)
    if (typeof value === 'object' && value !== null && 'id' in value && 'name' in value) {
      const { id, name } = value
      if (typeof id === 'number' && typeof name === 'string') return { id, name }
    }
  } catch {
    // sessionStorage が使えない・壊れている場合は店舗名なしで表示する
  }
  return null
}

/**
 * admin が閲覧中の店舗（08 §7.3）。閲覧系 API の store_id は各画面の ?store_id= から付け、
 * ここは帯に出す店舗名を覚えておく（再読み込みしても出るよう sessionStorage にも置く）
 */
export const useAdminStore = defineStore('admin', () => {
  const saved = restore()
  const viewingStoreId = ref<number | null>(saved?.id ?? null)
  const viewingStoreName = ref<string | null>(saved?.name ?? null)

  function view(id: number, name: string): void {
    viewingStoreId.value = id
    viewingStoreName.value = name
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ id, name }))
    } catch {
      // 保存できなくても閲覧はできる
    }
  }

  /** 帯の表示：「閲覧中：〇〇店（閲覧のみ）」。店舗名が分からなければ「閲覧中（閲覧のみ）」 */
  function viewingLabel(storeId: number | null): string {
    if (storeId === null) return ja.viewing.allStores
    return storeId === viewingStoreId.value && viewingStoreName.value !== null
      ? fmt(ja.viewing.named, { name: viewingStoreName.value })
      : ja.viewing.unnamed
  }

  return { viewingStoreId, viewingStoreName, view, viewingLabel }
})
