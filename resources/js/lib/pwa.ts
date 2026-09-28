import { ref } from 'vue'
import { registerSW } from 'virtual:pwa-register'

/** 新しい版の Service Worker が待機中なら true（S02 以外で［更新］を出す。08 §9、04 §4.1） */
export const needRefresh = ref(false)

let updateServiceWorker: ((reloadPage?: boolean) => Promise<void>) | null = null

export function setupPwa(): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
    return
  }
  updateServiceWorker = registerSW({
    onNeedRefresh() {
      needRefresh.value = true
    },
  })
}

/** ［更新］が押されたときに呼ぶ。待機中の版を有効にして再読み込みする */
export async function applyUpdate(): Promise<void> {
  needRefresh.value = false
  await updateServiceWorker?.(true)
}
