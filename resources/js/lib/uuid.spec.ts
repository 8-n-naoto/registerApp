import { afterEach, describe, expect, it, vi } from 'vitest'
import { uuidV4 } from '@/lib/uuid'

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('lib/uuid', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('UUID v4 の形で、毎回違う', () => {
    const a = uuidV4()
    expect(a).toMatch(V4)
    expect(uuidV4()).not.toBe(a)
  })

  it('randomUUID が無い（http の端末）ときは getRandomValues から作る', () => {
    const real = globalThis.crypto
    vi.stubGlobal('crypto', { getRandomValues: (a: Uint8Array<ArrayBuffer>) => real.getRandomValues(a) })
    const a = uuidV4()
    expect(a).toMatch(V4)
    expect(uuidV4()).not.toBe(a)
  })
})
