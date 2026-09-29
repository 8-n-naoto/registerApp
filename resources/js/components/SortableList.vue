<script setup lang="ts" generic="T extends { id: number }">
// タッチ対応の並び替え（08 §6）。Pointer Events で実装し、外部ライブラリは使わない。
// 指の下にある項目の位置へその場で入れ替える。list は右端のつまみ、grid は項目全体をつかむ。
// つまんでいる間は touch-action: none で画面がスクロールしない（AC-S08-7）。キーボードは矢印キーで動かす
import { ref } from 'vue'

const props = withDefaults(
  defineProps<{
    items: T[]
    layout?: 'list' | 'grid'
    label: string
    handleLabel?: (item: T) => string
  }>(),
  { layout: 'list', handleLabel: undefined },
)

const emit = defineEmits<{ 'update:items': [items: T[]] }>()

const root = ref<HTMLElement | null>(null)
const draggingId = ref<number | null>(null)
let pointerId: number | null = null

const EDGE = 80
const SCROLL_STEP = 12

function move(from: number, to: number): void {
  if (from === to || to < 0 || to >= props.items.length) return
  const next = [...props.items]
  const [picked] = next.splice(from, 1)
  if (picked === undefined) return
  next.splice(to, 0, picked)
  emit('update:items', next)
}

function indexAt(x: number, y: number): number | null {
  const els = root.value?.querySelectorAll<HTMLElement>('[data-sort-index]') ?? []
  for (const el of els) {
    const r = el.getBoundingClientRect()
    if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return Number(el.dataset.sortIndex)
  }
  return null
}

function onPointerDown(e: PointerEvent, item: T): void {
  if (e.button !== 0 || pointerId !== null) return
  pointerId = e.pointerId
  draggingId.value = item.id
  ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  e.preventDefault()
}

function onPointerMove(e: PointerEvent): void {
  if (e.pointerId !== pointerId || draggingId.value === null) return
  if (e.clientY < EDGE) window.scrollBy(0, -SCROLL_STEP)
  else if (e.clientY > window.innerHeight - EDGE) window.scrollBy(0, SCROLL_STEP)

  const target = indexAt(e.clientX, e.clientY)
  const from = props.items.findIndex((i) => i.id === draggingId.value)
  if (target !== null && from >= 0) move(from, target)
}

function onPointerEnd(e: PointerEvent): void {
  if (e.pointerId !== pointerId) return
  pointerId = null
  draggingId.value = null
}

function onKeydown(e: KeyboardEvent, index: number): void {
  const delta = { ArrowUp: -1, ArrowLeft: -1, ArrowDown: 1, ArrowRight: 1 }[e.key]
  if (delta === undefined) return
  e.preventDefault()
  move(index, index + delta)
  // 動かした項目にフォーカスを残す
  requestAnimationFrame(() => {
    root.value?.querySelector<HTMLElement>(`[data-sort-index="${index + delta}"] [data-sort-grip]`)?.focus()
  })
}
</script>

<template>
  <ul
    ref="root"
    class="sortable"
    :class="`sortable--${layout}`"
    :aria-label="label"
  >
    <li
      v-for="(item, index) in items"
      :key="item.id"
      class="sortable__item"
      :class="{ 'sortable__item--dragging': draggingId === item.id }"
      :data-sort-index="index"
    >
      <div
        v-if="layout === 'grid'"
        class="sortable__grip sortable__grip--whole"
        data-sort-grip
        tabindex="0"
        role="button"
        :aria-label="handleLabel ? handleLabel(item) : undefined"
        @pointerdown="onPointerDown($event, item)"
        @pointermove="onPointerMove"
        @pointerup="onPointerEnd"
        @pointercancel="onPointerEnd"
        @keydown="onKeydown($event, index)"
      >
        <slot
          :item="item"
          :index="index"
        />
      </div>
      <template v-else>
        <div class="sortable__content">
          <slot
            :item="item"
            :index="index"
          />
        </div>
        <div
          class="sortable__grip sortable__grip--handle"
          data-sort-grip
          tabindex="0"
          role="button"
          :aria-label="handleLabel ? handleLabel(item) : undefined"
          @pointerdown="onPointerDown($event, item)"
          @pointermove="onPointerMove"
          @pointerup="onPointerEnd"
          @pointercancel="onPointerEnd"
          @keydown="onKeydown($event, index)"
        >
          <span aria-hidden="true">≡</span>
        </div>
      </template>
    </li>
  </ul>
</template>

<style scoped>
.sortable { list-style: none; margin: 0; padding: 0; }
.sortable--list { display: flex; flex-direction: column; gap: 8px; }

.sortable--grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(var(--product-min-w, 140px), 1fr));
  gap: var(--product-gap);
}

.sortable__item { display: flex; align-items: stretch; gap: 8px; border-radius: var(--radius); }
.sortable__item--dragging { outline: 3px solid var(--c-focus); box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25); opacity: 0.9; }
.sortable__content { flex: 1 1 auto; min-width: 0; }

.sortable__grip {
  touch-action: none;
  user-select: none;
  -webkit-user-select: none;
  -webkit-touch-callout: none;
  cursor: grab;
}

.sortable__grip:focus-visible { outline: 3px solid var(--c-focus); outline-offset: 2px; }
.sortable__grip--whole { flex: 1 1 auto; }

.sortable__grip--handle {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text-sub);
  font-size: 24px;
}
</style>
