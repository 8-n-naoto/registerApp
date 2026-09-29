<script setup lang="ts">
// S08 編集パネルのオプション（06 §7.9）：名前・金額・有効、追加・並び替え・削除
import { ref } from 'vue'
import { createOption, deleteOption, reorderOptions, updateOption } from '@/api/catalog'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import SortableList from '@/components/SortableList.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatYen } from '@/lib/money'
import { parseSignedInt } from '@/lib/numberInput'
import type { ProductOption } from '@/types/api'

const MAX = 10
const PRICE_LIMIT = 999_999
const t = ja.products

const props = defineProps<{ productId: number }>()
const options = defineModel<ProductOption[]>({ required: true })

const editingId = ref<number | 'new' | null>(null)
const form = ref({ name: '', price: '', is_active: true })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)

const reordering = ref(false)
const draft = ref<ProductOption[]>([])

const deleting = ref<ProductOption | null>(null)
const deleteFailed = ref<string | null>(null)

function startNew(): void {
  editingId.value = 'new'
  form.value = { name: '', price: '0', is_active: true }
  errors.value = {}
  failed.value = null
}

function startEdit(option: ProductOption): void {
  editingId.value = option.id
  form.value = { name: option.name, price: String(option.price), is_active: option.is_active }
  errors.value = {}
  failed.value = null
}

async function save(): Promise<void> {
  if (saving.value) return
  errors.value = {}
  failed.value = null
  const price = parseSignedInt(form.value.price)
  if (price === null || Math.abs(price) > PRICE_LIMIT) {
    errors.value = { price: t.optionPriceInvalid }
    return
  }
  saving.value = true
  try {
    const input = { name: form.value.name.trim(), price, is_active: form.value.is_active }
    const id = editingId.value
    if (id === 'new') {
      options.value = [...options.value, await createOption(props.productId, input)]
    } else if (id !== null) {
      const saved = await updateOption(id, input)
      options.value = options.value.map((o) => (o.id === saved.id ? saved : o))
    }
    editingId.value = null
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else if (!isNetworkError(err)) failed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}

async function confirmDelete(): Promise<void> {
  const target = deleting.value
  if (!target || saving.value) return
  saving.value = true
  deleteFailed.value = null
  try {
    await deleteOption(target.id)
    options.value = options.value.filter((o) => o.id !== target.id)
    deleting.value = null
    editingId.value = null
  } catch (err) {
    if (!isNetworkError(err)) deleteFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}

function startReorder(): void {
  editingId.value = null
  draft.value = [...options.value]
  reordering.value = true
}

async function finishReorder(): Promise<void> {
  if (saving.value) return
  saving.value = true
  failed.value = null
  try {
    await reorderOptions(props.productId, draft.value.map((o) => o.id))
    options.value = draft.value.map((o, i) => ({ ...o, sort_order: i }))
    reordering.value = false
  } catch (err) {
    if (!isNetworkError(err)) failed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="options">
    <div class="adm-panel__head">
      <h3 class="options__title">
        {{ t.options }}
      </h3>
      <button
        v-if="!reordering"
        type="button"
        class="adm-btn"
        :disabled="options.length < 2"
        @click="startReorder"
      >
        {{ ja.common.reorder }}
      </button>
      <BigButton
        v-else
        :loading="saving"
        @click="finishReorder"
      >
        {{ ja.common.done }}
      </BigButton>
    </div>
    <p class="adm-help">
      {{ t.optionsHelp }}
    </p>
    <p
      v-if="failed"
      class="adm-error"
      role="alert"
    >
      {{ failed }}
    </p>

    <SortableList
      v-if="reordering"
      v-model:items="draft"
      :label="t.options"
      :handle-label="(o) => fmt(ja.common.moveHandle, { name: o.name })"
    >
      <template #default="{ item }">
        <div class="adm-row">
          <span class="adm-row__main">{{ item.name }}</span>
          <span class="tabular">{{ formatYen(item.price) }}</span>
        </div>
      </template>
    </SortableList>

    <ul
      v-else
      class="adm-list"
    >
      <li
        v-for="option in options"
        :key="option.id"
      >
        <div
          v-if="editingId !== option.id"
          class="adm-row"
          :class="{ 'adm-row--off': !option.is_active }"
        >
          <span class="adm-row__main">{{ option.name }}</span>
          <span class="tabular">{{ formatYen(option.price) }}</span>
          <span
            v-if="!option.is_active"
            class="adm-badge"
          >{{ ja.common.inactive }}</span>
          <button
            type="button"
            class="adm-btn"
            :aria-label="fmt(ja.common.editNamed, { name: option.name })"
            @click="startEdit(option)"
          >
            {{ ja.common.edit }}
          </button>
        </div>
      </li>
    </ul>

    <form
      v-if="editingId !== null && !reordering"
      class="adm-form options__form"
      novalidate
      @submit.prevent="save"
    >
      <div class="adm-field">
        <label
          for="option-name"
          class="adm-field__label"
        >{{ t.optionName }}</label>
        <input
          id="option-name"
          v-model="form.name"
          class="adm-input"
          maxlength="30"
          :aria-invalid="errors.name ? 'true' : undefined"
        >
        <p
          v-if="errors.name"
          class="adm-error"
        >
          {{ errors.name }}
        </p>
      </div>
      <div class="adm-field">
        <label
          for="option-price"
          class="adm-field__label"
        >{{ t.optionPrice }}</label>
        <input
          id="option-price"
          v-model="form.price"
          class="adm-input tabular"
          inputmode="numeric"
          maxlength="8"
          :aria-invalid="errors.price ? 'true' : undefined"
        >
        <p
          v-if="errors.price"
          class="adm-error"
        >
          {{ errors.price }}
        </p>
      </div>
      <label class="adm-check"><input
        v-model="form.is_active"
        type="checkbox"
      >{{ ja.common.active }}</label>
      <div class="adm-actions">
        <BigButton
          type="submit"
          :loading="saving"
        >
          {{ editingId === 'new' ? ja.common.add : ja.common.save }}
        </BigButton>
        <button
          type="button"
          class="adm-btn"
          @click="editingId = null"
        >
          {{ ja.common.cancel }}
        </button>
        <button
          v-if="editingId !== 'new'"
          type="button"
          class="adm-btn adm-btn--danger"
          @click="deleting = options.find((o) => o.id === editingId) ?? null"
        >
          {{ ja.common.delete }}
        </button>
      </div>
    </form>
    <div
      v-else-if="!reordering"
      class="adm-actions"
    >
      <button
        type="button"
        class="adm-btn"
        :disabled="options.length >= MAX"
        @click="startNew"
      >
        {{ t.optionAdd }}
      </button>
    </div>

    <ConfirmDialog
      :open="deleting !== null"
      :title="fmt(t.deleteOptionTitle, { name: deleting?.name ?? '' })"
      :confirm-label="ja.common.delete"
      danger
      :loading="saving"
      :error="deleteFailed"
      @confirm="confirmDelete"
      @cancel="deleting = null"
    />
  </div>
</template>

<style scoped>
.options { display: flex; flex-direction: column; gap: 12px; }
.options__title { font-size: 20px; }
.options__form { padding: 16px; border: 2px solid var(--c-focus); border-radius: var(--radius); }
</style>
