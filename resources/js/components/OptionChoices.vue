<script setup lang="ts">
// オプションの選択（docs/10「オプションのグループ」）。レジ・注文入力（OptionPicker）とお客さんの注文（C01）で共通
// 「1つ選ぶ」グループは横並び（radio）、「いくつでも」とグループなしは縦の並び（checkbox）。グループの間に区切り線、下に 1 つあたりの金額
import { computed } from 'vue'
import { ja } from '@/i18n/ja'
import { formatYen } from '@/lib/money'
import { buildSections, isSingle, toggleOption } from '@/lib/optionGroups'
import type { OptionSelection } from '@/types/api'

interface ChoiceOption { id: number; name: string; price: number; group_id: number | null; is_default: boolean }
interface ChoiceGroup { id: number; name: string; selection: OptionSelection }

const props = defineProps<{
  options: ChoiceOption[]
  groups: ChoiceGroup[]
  /** 商品の単価（1 つあたりの金額に足す） */
  basePrice: number
  priceSuffix?: string
}>()
const selected = defineModel<number[]>({ required: true })

const t = ja.optionChoices

const sections = computed(() => buildSections(props.options, props.groups))

const unitPrice = computed(() => props.basePrice
  + props.options.filter((o) => selected.value.includes(o.id)).reduce((sum, o) => sum + o.price, 0))

function key(group: ChoiceGroup | null): string {
  return group === null ? 'loose' : String(group.id)
}
</script>

<template>
  <div class="choices">
    <template
      v-for="(section, index) in sections"
      :key="key(section.group)"
    >
      <div
        v-if="index > 0"
        class="divider"
      />
      <div class="group">
        <div
          v-if="section.group"
          class="group__head"
        >
          <span class="group__name">{{ section.group.name }}</span>
          <span class="group__rule">{{ t.rule[section.group.selection] }}</span>
        </div>
        <div
          v-if="isSingle(section.group)"
          class="seg"
          role="radiogroup"
          :aria-label="section.group.name"
          :style="{ '--n': Math.min(section.options.length, 3) }"
        >
          <button
            v-for="option in section.options"
            :key="option.id"
            type="button"
            role="radio"
            :aria-checked="selected.includes(option.id)"
            @click="selected = toggleOption(selected, section, option.id)"
          >
            {{ option.name }}<small class="tabular">＋{{ formatYen(option.price) }}</small>
          </button>
        </div>
        <div
          v-else
          class="opts"
        >
          <button
            v-for="option in section.options"
            :key="option.id"
            type="button"
            role="checkbox"
            class="opt"
            :aria-checked="selected.includes(option.id)"
            @click="selected = toggleOption(selected, section, option.id)"
          >
            <span>{{ option.name }}</span>
            <span class="tabular">＋{{ formatYen(option.price) }}</span>
          </button>
        </div>
      </div>
    </template>
    <div class="sum">
      <span>{{ t.unitPrice }}</span>
      <b
        class="tabular"
        data-unit-price
      >{{ formatYen(unitPrice) }}{{ priceSuffix ?? '' }}</b>
    </div>
  </div>
</template>

<style scoped>
.choices { display: flex; flex-direction: column; gap: 16px; }
.group { display: flex; flex-direction: column; gap: 8px; }
.group__head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.group__name { font-size: 18px; font-weight: 700; }
.group__rule { font-size: 14px; font-weight: 700; color: var(--c-text-sub); }
.divider { height: 1px; background: var(--c-border); }

.seg { display: grid; grid-template-columns: repeat(var(--n, 3), 1fr); gap: 8px; }
.seg button {
  min-height: var(--btn-h);
  padding: 4px 6px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
}
.seg button small { display: block; font-size: 14px; font-weight: 700; }

.opts { display: flex; flex-direction: column; gap: 8px; }
.opt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: var(--btn-h);
  padding: 0 16px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 18px;
  font-weight: 700;
  text-align: left;
}
.seg button[aria-checked="true"], .opt[aria-checked="true"] {
  border-color: var(--c-primary);
  background: var(--c-primary);
  color: var(--c-on-primary);
}

.sum {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding: 10px 14px;
  border-radius: var(--radius);
  background: var(--c-surface-alt);
  font-weight: 700;
}
.sum b { font-size: 22px; color: var(--c-money); }
</style>
