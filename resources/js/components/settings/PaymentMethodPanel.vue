<script setup lang="ts">
// S09 支払方法の一覧（08 §5.10・06 §8.4）：名前・現金扱い・有効、追加、並び替え
import { computed, ref } from 'vue'
import { createPaymentMethod, reorderPaymentMethods, updatePaymentMethod } from '@/api/settings'
import BigButton from '@/components/BigButton.vue'
import SortableList from '@/components/SortableList.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import type { PaymentMethod } from '@/types/api'

const MAX = 10
const t = ja.storeSettings

const methods = defineModel<PaymentMethod[]>({ required: true })

const editingId = ref<number | 'new' | null>(null)
const form = ref({ name: '', is_cash: false, is_active: true })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)

const reordering = ref(false)
const draft = ref<PaymentMethod[]>([])

const activeCount = computed(() => methods.value.filter((x) => x.is_active).length)

function startNew(): void {
  editingId.value = 'new'
  form.value = { name: '', is_cash: false, is_active: true }
  errors.value = {}
  failed.value = null
}

function startEdit(method: PaymentMethod): void {
  editingId.value = method.id
  form.value = { name: method.name, is_cash: method.is_cash, is_active: method.is_active }
  errors.value = {}
  failed.value = null
}

function cancel(): void {
  editingId.value = null
}

async function save(): Promise<void> {
  if (saving.value) return
  errors.value = {}
  failed.value = null
  const id = editingId.value
  const current = typeof id === 'number' ? methods.value.find((x) => x.id === id) : undefined
  if (current?.is_active && !form.value.is_active && activeCount.value <= 1) {
    errors.value = { is_active: t.payLastActive }
    return
  }

  saving.value = true
  try {
    const name = form.value.name.trim()
    if (id === 'new') {
      methods.value = [...methods.value, await createPaymentMethod({ name, is_cash: form.value.is_cash })]
    } else if (id !== null) {
      const saved = await updatePaymentMethod(id, { name, is_cash: form.value.is_cash, is_active: form.value.is_active })
      methods.value = methods.value.map((x) => (x.id === saved.id ? saved : x))
    }
    editingId.value = null
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else if (!isNetworkError(err)) failed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}

function startReorder(): void {
  editingId.value = null
  draft.value = [...methods.value]
  reordering.value = true
}

async function finishReorder(): Promise<void> {
  if (saving.value) return
  saving.value = true
  failed.value = null
  try {
    await reorderPaymentMethods(draft.value.map((x) => x.id))
    methods.value = draft.value.map((x, i) => ({ ...x, sort_order: i }))
    reordering.value = false
  } catch (err) {
    if (!isNetworkError(err)) failed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section
    class="adm-panel"
    aria-labelledby="pay-heading"
  >
    <div class="adm-panel__head">
      <h2
        id="pay-heading"
        class="adm-panel__title"
      >
        {{ t.payHeading }}
      </h2>
      <div class="adm-actions">
        <button
          v-if="!reordering"
          type="button"
          class="adm-btn"
          :disabled="methods.length < 2"
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
    </div>
    <p
      v-if="failed"
      class="adm-error"
      role="alert"
    >
      {{ failed }}
    </p>

    <template v-if="reordering">
      <p class="adm-help">
        {{ t.reorderHelp }}
      </p>
      <SortableList
        v-model:items="draft"
        :label="t.payHeading"
        :handle-label="(x) => fmt(ja.common.moveHandle, { name: x.name })"
      >
        <template #default="{ item }">
          <div class="adm-row">
            <span class="adm-row__main">{{ item.name }}</span>
          </div>
        </template>
      </SortableList>
    </template>

    <ul
      v-else
      class="adm-list"
    >
      <li
        v-for="method in methods"
        :key="method.id"
      >
        <form
          v-if="editingId === method.id"
          class="adm-form pay-form"
          novalidate
          @submit.prevent="save"
        >
          <div class="adm-field">
            <label
              :for="`pay-name-${method.id}`"
              class="adm-field__label"
            >{{ t.payName }}</label>
            <input
              :id="`pay-name-${method.id}`"
              v-model="form.name"
              class="adm-input"
              maxlength="20"
              :aria-invalid="errors.name ? 'true' : undefined"
            >
            <p
              v-if="errors.name"
              class="adm-error"
            >
              {{ errors.name }}
            </p>
          </div>
          <div class="adm-actions">
            <label class="adm-check"><input
              v-model="form.is_cash"
              type="checkbox"
            >{{ t.payCash }}</label>
            <label class="adm-check"><input
              v-model="form.is_active"
              type="checkbox"
            >{{ ja.common.active }}</label>
          </div>
          <p
            v-if="errors.is_active"
            class="adm-error"
            role="alert"
          >
            {{ errors.is_active }}
          </p>
          <div class="adm-actions">
            <BigButton
              type="submit"
              :loading="saving"
            >
              {{ ja.common.save }}
            </BigButton>
            <button
              type="button"
              class="adm-btn"
              @click="cancel"
            >
              {{ ja.common.cancel }}
            </button>
          </div>
        </form>
        <div
          v-else
          class="adm-row"
          :class="{ 'adm-row--off': !method.is_active }"
        >
          <span class="adm-row__main">{{ method.name }}</span>
          <span
            v-if="method.is_cash"
            class="adm-badge adm-badge--primary"
          >{{ t.payCash }}</span>
          <span
            v-if="!method.is_active"
            class="adm-badge"
          >{{ ja.common.inactive }}</span>
          <button
            type="button"
            class="adm-btn"
            :aria-label="fmt(ja.common.editNamed, { name: method.name })"
            @click="startEdit(method)"
          >
            {{ ja.common.edit }}
          </button>
        </div>
      </li>
    </ul>

    <form
      v-if="editingId === 'new'"
      class="adm-form pay-form"
      novalidate
      @submit.prevent="save"
    >
      <div class="adm-field">
        <label
          for="pay-name-new"
          class="adm-field__label"
        >{{ t.payName }}</label>
        <input
          id="pay-name-new"
          v-model="form.name"
          class="adm-input"
          maxlength="20"
          :aria-invalid="errors.name ? 'true' : undefined"
        >
        <p
          v-if="errors.name"
          class="adm-error"
        >
          {{ errors.name }}
        </p>
      </div>
      <label class="adm-check"><input
        v-model="form.is_cash"
        type="checkbox"
      >{{ t.payCash }}</label>
      <p class="adm-help">
        {{ t.payCashHelp }}
      </p>
      <div class="adm-actions">
        <BigButton
          type="submit"
          :loading="saving"
        >
          {{ ja.common.add }}
        </BigButton>
        <button
          type="button"
          class="adm-btn"
          @click="cancel"
        >
          {{ ja.common.cancel }}
        </button>
      </div>
    </form>
    <div
      v-else-if="!reordering"
      class="adm-actions"
    >
      <BigButton
        variant="secondary"
        :disabled="methods.length >= MAX"
        @click="startNew"
      >
        {{ t.payAdd }}
      </BigButton>
    </div>
  </section>
</template>

<style scoped>
.pay-form { padding: 16px; border: 2px solid var(--c-focus); border-radius: var(--radius); }
</style>
