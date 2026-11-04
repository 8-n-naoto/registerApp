/**
 * 12 §8.3 新しい注文の通知音。音声ファイルは持たず、Web Audio の発振音で鳴らす。音の種類は厨房画面で選び、端末ごとに保存する。
 * Safari は操作なしで音を鳴らせないため、利用者の操作の中で enableSound() を呼んで AudioContext を作る・再開する
 */
const STORAGE_KEY = 'regi:kitchen-sound'
const TYPE_STORAGE_KEY = 'regi:kitchen-sound-type'

/** at：鳴らし始め（秒）、freq：高さ（Hz）、length：長さ（秒） */
interface Note {
  at: number
  freq: number
  length: number
}

interface SoundPreset {
  wave: OscillatorType
  peak: number
  notes: readonly Note[]
}

/** square は同じ音量でも大きく聞こえるので peak を下げて揃える */
const PRESETS = {
  pipi: {
    wave: 'sine',
    peak: 0.4,
    notes: [
      { at: 0, freq: 880, length: 0.12 },
      { at: 0.2, freq: 880, length: 0.12 },
    ],
  },
  pinpon: {
    wave: 'sine',
    peak: 0.5,
    notes: [
      { at: 0, freq: 784, length: 0.35 },
      { at: 0.35, freq: 622, length: 0.6 },
    ],
  },
  pipipi: {
    wave: 'square',
    peak: 0.15,
    notes: [
      { at: 0, freq: 2000, length: 0.08 },
      { at: 0.14, freq: 2000, length: 0.08 },
      { at: 0.28, freq: 2000, length: 0.08 },
    ],
  },
  pirorin: {
    wave: 'triangle',
    peak: 0.5,
    notes: [
      { at: 0, freq: 660, length: 0.14 },
      { at: 0.14, freq: 880, length: 0.14 },
      { at: 0.28, freq: 1100, length: 0.32 },
    ],
  },
  pipo: {
    wave: 'square',
    peak: 0.2,
    notes: [
      { at: 0, freq: 1000, length: 0.18 },
      { at: 0.2, freq: 750, length: 0.18 },
      { at: 0.4, freq: 1000, length: 0.18 },
      { at: 0.6, freq: 750, length: 0.18 },
    ],
  },
} as const satisfies Record<string, SoundPreset>

export type SoundType = keyof typeof PRESETS

/** 画面の選択肢の並び順 */
export const SOUND_TYPES: readonly SoundType[] = ['pipi', 'pinpon', 'pipipi', 'pirorin', 'pipo']

const DEFAULT_SOUND_TYPE: SoundType = 'pipi'

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

function isSoundType(value: string | null): value is SoundType {
  return value !== null && (SOUND_TYPES as readonly string[]).includes(value)
}

/** 端末に保存した音の種類。無い・知らない値なら既定の音 */
export function loadSoundType(): SoundType {
  try {
    const saved = localStorage.getItem(TYPE_STORAGE_KEY)
    return isSoundType(saved) ? saved : DEFAULT_SOUND_TYPE
  } catch {
    return DEFAULT_SOUND_TYPE
  }
}

export function saveSoundType(type: SoundType): void {
  try {
    localStorage.setItem(TYPE_STORAGE_KEY, type)
  } catch {
    // 保存できなくても、この画面を開いている間は効く
  }
}

/** 利用者の操作の中で呼ぶ。非対応のブラウザでは何もしない。再開を待ってから解決する */
export async function enableSound(): Promise<void> {
  if (typeof window.AudioContext !== 'function') return
  try {
    context ??= new window.AudioContext()
    await context.resume()
  } catch {
    if (context?.state === 'closed') context = null
  }
}

/** 今すぐ音を出せる状態か。読み直した直後や、端末が音声を止めたあとは false */
export function isSoundReady(): boolean {
  return context?.state === 'running'
}

export function playSound(type: SoundType): void {
  const ctx = context
  if (ctx === null || ctx.state !== 'running') return
  const start = ctx.currentTime
  const preset: SoundPreset = PRESETS[type]
  for (const note of preset.notes) {
    const begin = start + note.at
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.type = preset.wave
    osc.frequency.value = note.freq
    gain.gain.setValueAtTime(0.0001, begin)
    gain.gain.exponentialRampToValueAtTime(preset.peak, begin + 0.01)
    gain.gain.exponentialRampToValueAtTime(0.0001, begin + note.length)
    osc.connect(gain).connect(ctx.destination)
    osc.start(begin)
    osc.stop(begin + note.length + 0.01)
  }
}
