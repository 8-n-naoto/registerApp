import { describe, expect, it } from 'vitest'
import { buildSections, initialSelection, missingGroups, orderedSelection, toggleOption } from '@/lib/optionGroups'
import type { OptionSelection } from '@/types/api'

function option(id: number, group_id: number | null, is_default = false) {
  return { id, group_id, is_default }
}
function group(id: number, name: string, selection: OptionSelection) {
  return { id, name, selection }
}

// 味噌煮込みうどん：麺の固さ（1つ選ぶ、普通が最初）、追加（いくつでも）、オプションのないグループ、グループなし
const groups = [group(1, '麺の固さ', 'single'), group(2, '追加', 'multi'), group(3, '空', 'single')]
const options = [option(9, null), option(11, 1), option(12, 1, true), option(13, 1), option(21, 2), option(22, 2)]
const sections = buildSections(options, groups)
const [hardness, extra, loose] = sections

describe('lib/optionGroups（docs/10「オプションのグループ」）', () => {
  it('グループの順に並べ、オプションのないグループは出さず、グループなしを最後に置く', () => {
    expect(sections.map((s) => s.group?.name ?? null)).toEqual(['麺の固さ', '追加', null])
    expect(sections.map((s) => s.options.map((o) => o.id))).toEqual([[11, 12, 13], [21, 22], [9]])
  })

  it('知らないグループのオプションはグループなしに入れる', () => {
    expect(buildSections([option(5, 99)], []).map((s) => [s.group, s.options.map((o) => o.id)])).toEqual([[null, [5]]])
  })

  it('最初の選択は「1つ選ぶ」の「最初に選ぶ」だけ', () => {
    expect(initialSelection(sections)).toEqual([12])
    expect(initialSelection(buildSections([option(21, 2, true)], groups))).toEqual([])
  })

  it('「1つ選ぶ」は押すと切り替わり外れない。「いくつでも」とグループなしは付け外し', () => {
    if (!hardness || !extra || !loose) throw new Error('sections')
    expect(toggleOption([12, 21], hardness, 11)).toEqual([21, 11])
    expect(toggleOption([11], hardness, 11)).toEqual([11])
    expect(toggleOption([12], extra, 21)).toEqual([12, 21])
    expect(toggleOption([12, 21], extra, 21)).toEqual([12])
    expect(toggleOption([], loose, 9)).toEqual([9])
  })

  it('選んでいない「1つ選ぶ」グループを返す', () => {
    expect(missingGroups(sections, [21]).map((g) => g.name)).toEqual(['麺の固さ'])
    expect(missingGroups(sections, [13])).toEqual([])
  })

  it('選んだ ID を表示の順に並べる', () => {
    expect(orderedSelection(sections, [9, 22, 13])).toEqual([13, 22, 9])
  })
})
