/**
 * 12 §8.3 新しい注文の通知音。音声ファイルは持たず、Web Audio の発振音を短く 2 回鳴らす。
 * Safari は操作なしで音を鳴らせないため、［音 ON］のタップの中で enable() を呼んで AudioContext を作る・再開する
 */
const STORAGE_KEY = 'regi:kitchen-sound'

let context: AudioContext | null = null

/** 端末に保存した設定（既定 OFF） */
export function loadSoundEnabled(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

export function saveSoundEnabled(enabled: boolean): void {
  try {
    localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0')
  } catch {
    // 保存できなくても、この画面を開いている間は効く
  }
}

/** 利用者の操作の中で呼ぶ。非対応のブラウザでは何もしない */
export function enableSound(): void {
  if (typeof window.AudioContext !== 'function') return
  try {
    context ??= new window.AudioContext()
    void context.resume().catch(() => {})
  } catch {
    context = null
  }
}

/** ピッ・ピッ（880Hz・0.12 秒 × 2） */
export function playBeep(): void {
  const ctx = context
  if (ctx === null || ctx.state !== 'running') return
  const start = ctx.currentTime
  for (const offset of [0, 0.2]) {
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.type = 'sine'
    osc.frequency.value = 880
    gain.gain.setValueAtTime(0.0001, start + offset)
    gain.gain.exponentialRampToValueAtTime(0.4, start + offset + 0.01)
    gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.12)
    osc.connect(gain).connect(ctx.destination)
    osc.start(start + offset)
    osc.stop(start + offset + 0.13)
  }
}
