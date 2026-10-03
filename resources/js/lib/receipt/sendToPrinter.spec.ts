import { afterEach, describe, expect, it, vi } from 'vitest'
import { parseResponse, printerUrl, sendToPrinter, SEND_TIMEOUT_MS } from '@/lib/receipt/sendToPrinter'

const xml = (attrs: string): string =>
  `<?xml version="1.0" encoding="UTF-8"?><StarWebPrint xmlns="http://www.star-m.jp"><Response><response ${attrs}/></Response></StarWebPrint>`

describe('15 §7.3 応答の判定（T9）', () => {
  it.each([
    ['success="true" code="OK" status="23 86 00 00 00 00 00 00 00"', { ok: true }],
    ['success="false" code="ERROR" status="23860000000800000000"', { ok: false, reason: 'paper_empty' }],
    ['success="false" code="ERROR" status="23862000000000000000"', { ok: false, reason: 'cover_open' }],
    ['success="false" code="1100" status=""', { ok: false, reason: 'offline' }],
    ['success="false" code="ERROR" status="23860800000000000000"', { ok: false, reason: 'offline' }],
    ['success="false" code="2001" status="23860000000000000000"', { ok: false, reason: 'busy' }],
    ['success="false" code="9999" status=""', { ok: false, reason: 'unknown' }],
  ])('%s', (attrs, expected) => {
    expect(parseResponse(xml(attrs))).toEqual(expected)
  })

  it('XML でない応答は unknown', () => {
    expect(parseResponse('<html')).toEqual({ ok: false, reason: 'unknown' })
  })
})

describe('送信（T10）', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('宛先・本文の形で 1 回だけ送る', async () => {
    const fetchImpl = vi.fn<typeof fetch>().mockResolvedValue(new Response(xml('success="true" code="OK" status="23860000000000000000"')))
    expect(await sendToPrinter('192.168.1.50', '<initialization/>', fetchImpl)).toEqual({ ok: true })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
    const [url, init] = fetchImpl.mock.calls[0] ?? []
    expect(url).toBe(printerUrl('192.168.1.50'))
    expect(url).toBe('https://192.168.1.50/StarWebPRNT/SendMessage')
    expect(init?.method).toBe('POST')
    expect(init?.body).toBe('<StarWebPrint xmlns="http://www.star-m.jp" xmlns:i="http://www.w3.org/2001/XMLSchema-instance"><Request>&lt;initialization/&gt;</Request></StarWebPrint>')
  })

  it('通信の失敗は unreachable で、自動で送り直さない', async () => {
    const fetchImpl = vi.fn<typeof fetch>().mockRejectedValue(new TypeError('Failed to fetch'))
    expect(await sendToPrinter('192.168.1.50', '', fetchImpl)).toEqual({ ok: false, reason: 'unreachable' })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('10 秒で打ち切って timeout', async () => {
    vi.useFakeTimers()
    const fetchImpl = vi.fn<typeof fetch>().mockImplementation((_url, init) => new Promise((_resolve, reject) => {
      init?.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
    }))
    const pending = sendToPrinter('192.168.1.50', '', fetchImpl)
    await vi.advanceTimersByTimeAsync(SEND_TIMEOUT_MS)
    expect(await pending).toEqual({ ok: false, reason: 'timeout' })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('HTTP のエラーは unknown', async () => {
    const fetchImpl = vi.fn<typeof fetch>().mockResolvedValue(new Response('', { status: 500 }))
    expect(await sendToPrinter('192.168.1.50', '', fetchImpl)).toEqual({ ok: false, reason: 'unknown' })
  })
})
