import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useAdminStore } from '@/stores/admin'

describe('admin store', () => {
  beforeEach(() => {
    sessionStorage.clear()
    setActivePinia(createPinia())
  })

  it('閲覧中の店舗名を帯の表示にする', () => {
    const admin = useAdminStore()
    expect(admin.viewingLabel(3)).toBe('閲覧中（閲覧のみ）')
    admin.view(3, '駅前店')
    expect(admin.viewingLabel(3)).toBe('閲覧中：駅前店（閲覧のみ）')
    expect(admin.viewingLabel(4)).toBe('閲覧中（閲覧のみ）')
    expect(admin.viewingLabel(null)).toBe('全店舗（閲覧のみ）')
  })

  it('再読み込みしても店舗名を復元する', () => {
    useAdminStore().view(5, '本店')
    setActivePinia(createPinia())
    expect(useAdminStore().viewingLabel(5)).toBe('閲覧中：本店（閲覧のみ）')
  })
})
