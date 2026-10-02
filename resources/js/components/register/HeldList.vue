<script setup lang="ts">
// 保留一覧（08 §5.3 A 案）：保留した時刻・内容。［呼び出す］で今の注文と入れ替える（今の注文が空でなければ確認）
import { computed, ref } from 'vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { nameWithMemo } from '@/lib/productLabel'
import { useRegisterStore } from '@/stores/register'

defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const t = ja.register
const register = useRegisterStore()

function timeOf(iso: string): string {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '' : `${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`
}

const rows = computed(() =>
  register.held.map((order) => {
    const names = [...new Set(order.lines.map((l) => register.products.get(l.product_id)).filter((p) => p !== undefined).map((p) => nameWithMemo(p.name, p.memo)))]
    return {
      time: fmt(t.heldAt, { time: timeOf(order.held_at) }),
      summary: fmt(t.heldSummary, { names: names.slice(0, 3).join('・'), n: order.lines.reduce((s, l) => s + l.quantity, 0) }),
    }
  }),
)

const replacing = ref<number | null>(null)
const deleting = ref<number | null>(null)

function recall(index: number): void {
  if (register.lines.length > 0) {
    replacing.value = index
    return
  }
  doRecall(index)
}

function doRecall(index: number): void {
  register.recall(index)
  replacing.value = null
  emit('close')
}

function doDelete(index: number): void {
  register.deleteHeld(index)
  deleting.value = null
}
</script>

<template>
  <BottomSheet
    :open="open"
    :title="t.heldTitle"
    @close="emit('close')"
  >
    <p
      v-if="rows.length === 0"
      class="held__empty"
    >
      {{ t.heldEmpty }}
    </p>
    <ul
      v-else
      class="held"
    >
      <li
        v-for="(row, i) in rows"
        :key="i"
        class="held__row"
      >
        <div class="held__text">
          <span class="held__time">{{ row.time }}</span>
          <span>{{ row.summary }}</span>
        </div>
        <button
          type="button"
          class="held__btn held__btn--primary r-btn r-btn--primary r-btn--sm"
          @click="recall(i)"
        >
          {{ t.heldRecall }}
        </button>
        <button
          type="button"
          class="held__btn r-btn r-btn--secondary r-btn--sm"
          :aria-label="t.heldDelete"
          @click="deleting = i"
        >
          ×
        </button>
      </li>
    </ul>
  </BottomSheet>
  <ConfirmDialog
    :open="replacing !== null"
    :title="t.heldReplaceTitle"
    :confirm-label="t.heldReplaceConfirm"
    danger
    @confirm="replacing !== null && doRecall(replacing)"
    @cancel="replacing = null"
  />
  <ConfirmDialog
    :open="deleting !== null"
    :title="t.heldDeleteTitle"
    :confirm-label="ja.common.delete"
    danger
    @confirm="deleting !== null && doDelete(deleting)"
    @cancel="deleting = null"
  />
</template>

<style scoped>
.held { margin: 0; padding: 0; list-style: none; }
.held__empty { padding: 24px 0; color: var(--c-text-sub); text-align: center; }
.held__row { display: flex; align-items: center; gap: 8px; padding: 10px 0; border-bottom: 1px solid var(--c-border-soft); }
.held__text { display: flex; flex: 1 1 auto; flex-direction: column; min-width: 0; font-size: 17px; font-weight: 700; overflow-wrap: anywhere; }
.held__time { color: var(--c-text-sub); font-size: 14px; font-weight: 400; }
</style>
