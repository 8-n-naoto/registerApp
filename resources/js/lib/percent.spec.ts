import { describe, expect, it } from 'vitest'
import { percentToPermille, permilleToPercent } from '@/lib/percent'

describe('税率の % 表記', () => {
  it('千分率を % の文字にする', () => {
    expect(permilleToPercent(100)).toBe('10')
    expect(permilleToPercent(80)).toBe('8')
    expect(permilleToPercent(85)).toBe('8.5')
    expect(permilleToPercent(0)).toBe('0')
    expect(permilleToPercent(1000)).toBe('100')
  })

  it('% の文字を千分率にする', () => {
    expect(percentToPermille('10')).toBe(100)
    expect(percentToPermille(' 8.5 ')).toBe(85)
    expect(percentToPermille('１０')).toBe(100)
    expect(percentToPermille('100')).toBe(1000)
    expect(percentToPermille('0')).toBe(0)
  })

  it('範囲外・小数 2 桁・数字以外は null', () => {
    for (const bad of ['', '100.1', '101', '8.25', '-1', 'abc', '1e1', '.5']) {
      expect(percentToPermille(bad)).toBeNull()
    }
  })
})
