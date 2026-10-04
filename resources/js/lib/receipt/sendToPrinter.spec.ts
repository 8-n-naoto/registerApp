import { afterEach, describe, expect, it, vi } from 'vitest'
import { parseResponse, printerUrl, sendToPrinter, SEND_TIMEOUT_MS } from '@/lib/receipt/sendToPrinter'

/** 実機（mC-Print2、2026-10-05）の応答の形：<Response> の中に <root> がエスケープされた文字列で入る */
const xml = (success: string, code: string, status: string): string =>
  '<?xml version="1.0"?>\n<StarWebPrint xmlns="http://www.star-m.jp" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">\n'
  + `<Response>&lt;root&gt;&lt;success&gt;${success}&lt;/success&gt;&lt;code&gt;${code}&lt;/code&gt;&lt;status&gt;${status}&lt;/status&gt;&lt;/root&gt;</Response>\n</StarWebPrint>\n`

describe('15 §7.3 応答の判定（T9）', () => {
  it.each([
    [['true', '0', '298A020000000002000000060000'], { ok: true }],
    [['true', '0', '23 86 00 00 00 00 00 00 00'], { ok: true }],
    [['false', 'ERROR', '23860000000800000000'], { ok: false, reason: 'paper_empty' }],
    [['false', 'ERROR', '23862000000000000000'], { ok: false, reason: 'cover_open' }],
    [['false', '1100', ''], { ok: false, reason: 'offline' }],
    [['false', 'ERROR', '23860800000000000000'], { ok: false, reason: 'offline' }],
    [['false', '2001', '23860000000000000000'], { ok: false, reason: 'busy' }],
    [['false', '9999', ''], { ok: false, reason: 'unknown' }],
  ] as const)('%j', ([success, code, status], expected) => {
    expect(parseResponse(xml(success, code, status))).toEqual(expected)
  })

  it('XML でない応答は unknown', () => {
    expect(parseResponse('<html')).toEqual({ ok: false, reason: 'unknown' })
  })
})

describe('宛先', () => {
  it('https のページからは https、http のページ（開発環境）からは http', () => {
    expect(printerUrl('192.168.1.50', 'https:')).toBe('https://192.168.1.50/StarWebPRNT/SendMessage')
    expect(printerUrl('192.168.1.50', 'http:')).toBe('http://192.168.1.50/StarWebPRNT/SendMessage')
  })
})

describe('送信（T10）', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('宛先・本文の形で 1 回だけ送る', async () => {
    const fetchImpl = vi.fn<typeof fetch>().mockResolvedValue(new Response(xml('true', '0', '298A020000000002000000060000')))
    expect(await sendToPrinter('192.168.1.50', '<initialization/>', fetchImpl)).toEqual({ ok: true })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
    const [url, init] = fetchImpl.mock.calls[0] ?? []
    expect(url).toBe(printerUrl('192.168.1.50'))
    expect(init?.method).toBe('POST')
    expect(init?.body).toBe('<StarWebPrint xmlns="http://www.star-m.jp" xmlns:i="http://www.w3.org/2001/XMLSchema-instance"><Request>&lt;root&gt;&lt;initialization/&gt;&lt;/root&gt;</Request></StarWebPrint>')
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
