import { onBeforeUnmount, onMounted } from 'vue'

/**
 * 08 §5.3 C 案：表示している間、画面のスリープを防ぐ。画面を離れたら解放し、タブが裏から戻ったら取り直す。
 * 非対応のブラウザ（iOS 16.3 以前など）・拒否された場合は何もしない
 */
export function useWakeLock(): void {
  let sentinel: WakeLockSentinel | null = null
  let active = false

  async function request(): Promise<void> {
    if (!active || sentinel !== null || !('wakeLock' in navigator) || document.visibilityState !== 'visible') return
    try {
      const s = await navigator.wakeLock.request('screen')
      if (!active) {
        await s.release()
        return
      }
      sentinel = s
      s.addEventListener('release', () => {
        if (sentinel === s) sentinel = null
      })
    } catch {
      // 省電力モード・権限なしなど。画面は通常どおり暗くなる
    }
  }

  function onVisibility(): void {
    if (document.visibilityState === 'visible') void request()
  }

  onMounted(() => {
    active = true
    document.addEventListener('visibilitychange', onVisibility)
    void request()
  })

  onBeforeUnmount(() => {
    active = false
    document.removeEventListener('visibilitychange', onVisibility)
    const s = sentinel
    sentinel = null
    void s?.release().catch(() => {})
  })
}
