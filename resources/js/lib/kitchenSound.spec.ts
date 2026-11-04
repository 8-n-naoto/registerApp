import { beforeEach, describe, expect, it, vi } from 'vitest'

type Kitchen = typeof import('@/lib/kitchenSound')

function fakeParam() {
  return { value: 0, setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() }
}

class FakeAudioContext {
  static instances: FakeAudioContext[] = []
  static startsRunning = true
  state: AudioContextState = 'suspended'
  currentTime = 10
  destination = {}
  oscillators: { type: string; frequency: { value: number }; connect: ReturnType<typeof vi.fn>; start: ReturnType<typeof vi.fn>; stop: ReturnType<typeof vi.fn> }[] = []
  gains: { gain: ReturnType<typeof fakeParam>; connect: ReturnType<typeof vi.fn> }[] = []

  constructor() {
    FakeAudioContext.instances.push(this)
  }

  resume(): Promise<void> {
    if (FakeAudioContext.startsRunning) this.state = 'running'
    return Promise.resolve()
  }

  createOscillator() {
    const gainNode = { gain: fakeParam(), connect: vi.fn() }
    this.gains.push(gainNode)
    const osc = { type: '', frequency: { value: 0 }, connect: vi.fn(() => gainNode), start: vi.fn(), stop: vi.fn() }
    this.oscillators.push(osc)
    return osc
  }

  createGain() {
    const gainNode = this.gains[this.gains.length - 1]
    if (!gainNode) throw new Error('createOscillator が先')
    return gainNode
  }
}

async function load(): Promise<Kitchen> {
  vi.resetModules() // AudioContext をモジュールが持つので、試験ごとに読み直す
  return import('@/lib/kitchenSound')
}

describe('kitchenSound', () => {
  beforeEach(() => {
    localStorage.clear()
    FakeAudioContext.instances = []
    FakeAudioContext.startsRunning = true
    vi.stubGlobal('AudioContext', FakeAudioContext)
  })

  it('音の種類の既定は標準の音。保存した値を読み、知らない値は既定に戻す', async () => {
    const s = await load()
    expect(s.loadSoundType()).toBe('pipi')
    s.saveSoundType('pirorin')
    expect(s.loadSoundType()).toBe('pirorin')
    localStorage.setItem('regi:kitchen-sound-type', 'unknown')
    expect(s.loadSoundType()).toBe('pipi')
  })

  it('標準の音は変更前と同じ（880Hz の sine、0.12 秒を 0.2 秒間隔で 2 回）', async () => {
    const s = await load()
    await s.enableSound()
    s.playSound('pipi')
    const ctx = FakeAudioContext.instances[0]
    expect(ctx?.oscillators.map((o) => [o.type, o.frequency.value])).toEqual([
      ['sine', 880],
      ['sine', 880],
    ])
    expect(ctx?.oscillators.map((o) => o.start.mock.calls[0]?.[0])).toEqual([10, 10.2])
    expect(ctx?.oscillators[0]?.stop.mock.calls[0]?.[0]).toBeCloseTo(10.13)
    expect(ctx?.gains[0]?.gain.exponentialRampToValueAtTime.mock.calls[0]).toEqual([0.4, 10.01])
  })

  it('すべての種類が音を鳴らし、種類ごとに波形か音の並びが違う', async () => {
    const s = await load()
    await s.enableSound()
    const ctx = FakeAudioContext.instances[0]
    const seen = new Set<string>()
    for (const type of s.SOUND_TYPES) {
      if (ctx) ctx.oscillators = []
      s.playSound(type)
      expect(ctx?.oscillators.length).toBeGreaterThan(0)
      seen.add(JSON.stringify(ctx?.oscillators.map((o) => [o.type, o.frequency.value])))
    }
    expect(seen.size).toBe(s.SOUND_TYPES.length)
  })

  it('有効にする前は鳴らさず isSoundReady も false。AudioContext を作り直さない', async () => {
    const s = await load()
    expect(s.isSoundReady()).toBe(false)
    s.playSound('pipi')
    expect(FakeAudioContext.instances).toHaveLength(0)

    await s.enableSound()
    await s.enableSound()
    expect(FakeAudioContext.instances).toHaveLength(1)
    expect(s.isSoundReady()).toBe(true)
  })

  it('ブラウザが再開を許さないときは鳴らさず、isSoundReady は false のまま', async () => {
    FakeAudioContext.startsRunning = false
    const s = await load()
    await s.enableSound()
    s.playSound('pipi')
    expect(s.isSoundReady()).toBe(false)
    expect(FakeAudioContext.instances[0]?.oscillators).toHaveLength(0)
  })

  it('AudioContext の無いブラウザでは何もしない', async () => {
    vi.stubGlobal('AudioContext', undefined)
    const s = await load()
    await expect(s.enableSound()).resolves.toBeUndefined()
    expect(s.isSoundReady()).toBe(false)
  })
})
