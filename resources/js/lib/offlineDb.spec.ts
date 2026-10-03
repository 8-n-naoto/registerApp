import { beforeEach, describe, expect, it } from 'vitest'
import { kvDelete, kvGet, kvList, kvSet, resetOfflineDbForTest } from '@/lib/offlineDb'

// テスト環境（jsdom）には IndexedDB が無いので、localStorage に書く経路を確かめる
describe('offlineDb（14 §7.1）', () => {
  beforeEach(() => {
    localStorage.clear()
    resetOfflineDbForTest()
  })

  it('書いた値を読み、prefix で一覧し、消せる', async () => {
    await kvSet('outbox:1:a', { n: 1 })
    await kvSet('outbox:1:b', { n: 2 })
    await kvSet('outbox:2:c', { n: 3 })

    expect(await kvGet('outbox:1:a')).toEqual({ n: 1 })
    expect(await kvGet('none')).toBeUndefined()
    const list = await kvList('outbox:1:')
    expect([...list.keys()].sort()).toEqual(['outbox:1:a', 'outbox:1:b'])

    await kvDelete('outbox:1:a')
    expect(await kvGet('outbox:1:a')).toBeUndefined()
    expect([...(await kvList('outbox:1:')).keys()]).toEqual(['outbox:1:b'])
  })

  it('書けなければ例外を返す（送信待ちの保存に失敗したら会計を確定扱いにしない）', async () => {
    const original = Storage.prototype.setItem
    Storage.prototype.setItem = () => {
      throw new Error('QuotaExceededError')
    }
    try {
      await expect(kvSet('outbox:1:a', { n: 1 })).rejects.toThrow('QuotaExceededError')
    } finally {
      Storage.prototype.setItem = original
    }
  })

  it('壊れた値は読まない', async () => {
    localStorage.setItem('regi-offline:outbox:1:x', '{broken')
    expect(await kvGet('outbox:1:x')).toBeUndefined()
    expect((await kvList('outbox:1:')).size).toBe(0)
  })
})
