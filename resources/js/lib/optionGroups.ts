// オプションのグループ（docs/10「オプションのグループ」）。レジ・注文入力（OptionPicker）とお客さんの注文（C01）で共通の選び方
// 「1つ選ぶ」グループは 1 つだけ（「最初に選ぶ」オプションを既定で選んでおく）、「いくつでも」とグループなしは自由に選ぶ

import type { OptionSelection } from '@/types/api'

export interface OptionLike { id: number; group_id: number | null; is_default: boolean }
export interface GroupLike { id: number; name: string; selection: OptionSelection }

export interface OptionSection<O extends OptionLike, G extends GroupLike> {
  /** null はグループなし（見出しを出さず、いくつでも選べる） */
  group: G | null
  options: O[]
}

/** グループの順に並べ、グループなしを最後に置く。オプションのないグループは出さない。options・groups は表示の順で渡す */
export function buildSections<O extends OptionLike, G extends GroupLike>(options: O[], groups: G[]): OptionSection<O, G>[] {
  const known = new Set(groups.map((g) => g.id))
  const sections: OptionSection<O, G>[] = groups
    .map((group) => ({ group, options: options.filter((o) => o.group_id === group.id) }))
    .filter((s) => s.options.length > 0)
  const loose = options.filter((o) => o.group_id === null || !known.has(o.group_id))
  if (loose.length > 0) sections.push({ group: null, options: loose })
  return sections
}

export function isSingle<G extends GroupLike>(group: G | null): group is G {
  return group !== null && group.selection === 'single'
}

/** 開いたときの選択：「1つ選ぶ」グループの「最初に選ぶ」オプション */
export function initialSelection<O extends OptionLike, G extends GroupLike>(sections: OptionSection<O, G>[]): number[] {
  return sections.flatMap((s) => (isSingle(s.group) ? s.options.filter((o) => o.is_default).slice(0, 1).map((o) => o.id) : []))
}

/** 押したときの選択。「1つ選ぶ」は同じグループのほかを外して選ぶ（選び直しだけで、外すことはしない） */
export function toggleOption<O extends OptionLike, G extends GroupLike>(selected: number[], section: OptionSection<O, G>, id: number): number[] {
  if (isSingle(section.group)) {
    const siblings = new Set(section.options.map((o) => o.id))
    return [...selected.filter((v) => !siblings.has(v)), id]
  }
  return selected.includes(id) ? selected.filter((v) => v !== id) : [...selected, id]
}

/** まだ選んでいない「1つ選ぶ」グループ（追加の前に選んでもらう） */
export function missingGroups<O extends OptionLike, G extends GroupLike>(sections: OptionSection<O, G>[], selected: number[]): G[] {
  const chosen = new Set(selected)
  return sections.flatMap((s) => (isSingle(s.group) && !s.options.some((o) => chosen.has(o.id)) ? [s.group] : []))
}

/** 選んだ ID を表示の順に並べる（明細のオプションの並びをグループの順にそろえる） */
export function orderedSelection<O extends OptionLike, G extends GroupLike>(sections: OptionSection<O, G>[], selected: number[]): number[] {
  const chosen = new Set(selected)
  return sections.flatMap((s) => s.options.filter((o) => chosen.has(o.id)).map((o) => o.id))
}
