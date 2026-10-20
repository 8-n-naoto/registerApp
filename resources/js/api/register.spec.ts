import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createOfflineSale, type OfflineSaleInput } from '@/api/register'

const client = vi.hoisted(() => ({ http: { post: vi.fn() } }))
vi.mock('@/api/client', () => client)

function input(overrides: Partial<OfflineSaleInput> = {}): OfflineSaleInput {
  return {
    client_uuid: '00000000-0000-4000-8000-000000000001',
    tax_type_id: 1,
    payment_method_id: 1,
    items: [{ product_id: 1, quantity: 1, option_ids: [], unit_price: 500, option_prices: [] }],
    discount: null,
    received: null,
    customer_count: null,
    memo: null,
    device_name: null,
    expected_total: 500,
    order_ids: [],
    sold_at: '2026-10-21T10:00:00+09:00',
    operator_id: null,
    tax_rate_permille: 100,
    price_mode: 'tax_included',
    rounding: 'floor',
    ...overrides,
  }
}

describe('createOfflineSale（F-009：422 で端末に残らないよう制御文字を取り除いて送る）', () => {
  beforeEach(() => {
    client.http.post.mockReset()
    client.http.post.mockResolvedValue({ data: { id: 1 } })
  })

  it('メモは改行を残して他の制御文字を取り除き、端末名は制御文字をすべて取り除く', async () => {
    await createOfflineSale(input({ memo: '1行目\r\n2行目\t\u0000\u007F', device_name: 'レジ\t1\n' }))

    expect(client.http.post).toHaveBeenCalledWith('/sales/offline', expect.objectContaining({ memo: '1行目\r\n2行目', device_name: 'レジ1' }))
  })

  it('端末名が制御文字だけなら null にし、null はそのまま送る', async () => {
    await createOfflineSale(input({ memo: null, device_name: '\t\n' }))

    expect(client.http.post).toHaveBeenCalledWith('/sales/offline', expect.objectContaining({ memo: null, device_name: null }))
  })

  it('入力のオブジェクト（端末に保存した会計）は書き換えない', async () => {
    const saved = input({ memo: 'a\tb' })
    await createOfflineSale(saved)

    expect(saved.memo).toBe('a\tb')
  })
})
