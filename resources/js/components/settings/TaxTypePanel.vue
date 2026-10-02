<script setup lang="ts">
// S09 税区分の一覧（08 §5.10・06 §8.3）：名前・税率・既定・有効、追加、並び替え
import { computed, ref } from 'vue'
import { createTaxType, fetchStoreSettings, reorderTaxTypes, updateTaxType } from '@/api/settings'
import BigButton from '@/components/BigButton.vue'
import SortableList from '@/components/SortableList.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { percentToPermille, permilleToPercent } from '@/lib/percent'
import type { TaxType } from '@/types/api'

const MAX = 10
const t = ja.storeSettings

const taxTypes = defineModel<TaxType[]>({ required: true })

const editingId = ref<number | 'new' | null>(null)
const form = ref({ name: '', rate: '', is_default: false, is_active: true })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)

const reordering = ref(false)
const draft = ref<TaxType[]>([])

const activeCount = computed(() => taxTypes.value.filter((x) => x.is_active).length)

function startNew(): void {
  editingId.value = 'new'
  form.value = { name: '', rate: '10', is_default: false, is_active: true }
  errors.value = {}
  failed.value = null
}

function startEdit(tax: TaxType): void {
  editingId.value = tax.id
  form.value = { name: tax.name, rate: permilleToPercent(tax.rate_permille), is_default: tax.is_default, is_active: tax.is_active }
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
  const rate = percentToPermille(form.value.rate)
  if (rate === null) {
    errors.value = { rate_permille: t.taxRateInvalid }
    return
  }
  const id = editingId.value
  const current = typeof id === 'number' ? taxTypes.value.find((x) => x.id === id) : undefined
  if (current?.is_active && !form.value.is_active && activeCount.value <= 1) {
    errors.value = { is_active: t.taxLastActive }
    return
  }

  saving.value = true
  try {
    const name = form.value.name.trim()
    if (id === 'new') {
      await createTaxType({ name, rate_permille: rate, is_default: form.value.is_default })
    } else if (id !== null) {
      await updateTaxType(id, { name, rate_permille: rate, is_default: form.value.is_default, is_active: form.value.is_active })
    }
    // 既定の付け替えは他の行にも及ぶので、一覧を取り直す
    taxTypes.value = (await fetchStoreSettings()).tax_types
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
  draft.value = [...taxTypes.value]
  reordering.value = true
}

async function finishReorder(): Promise<void> {
  if (saving.value) return
  saving.value = true
  failed.value = null
  try {
    await reorderTaxTypes(draft.value.map((x) => x.id))
    taxTypes.value = draft.value.map((x, i) => ({ ...x, sort_order: i }))
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
    class="adm-panel r-card"
    aria-labelledby="tax-heading"
  >
    <div class="adm-panel__head">
      <h2
        id="tax-heading"
        class="adm-panel__title r-h2"
      >
        {{ t.taxHeading }}
      </h2>
      <div class="adm-actions">
        <button
          v-if="!reordering"
          type="button"
          class="adm-btn"
          :disabled="taxTypes.length < 2"
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
    <p class="adm-help r-help">
      {{ t.taxRateNote }}
    </p>
    <p
      v-if="failed"
      class="adm-error r-err"
      role="alert"
    >
      {{ failed }}
    </p>

    <template v-if="reordering">
      <p class="adm-help r-help">
        {{ t.reorderHelp }}
      </p>
      <SortableList
        v-model:items="draft"
        :label="t.taxHeading"
        :handle-label="(x) => fmt(ja.common.moveHandle, { name: x.name })"
      >
        <template #default="{ item }">
          <div class="adm-row">
            <span class="adm-row__main">{{ item.name }}</span>
            <span class="tabular">{{ permilleToPercent(item.rate_permille) }}%</span>
          </div>
        </template>
      </SortableList>
    </template>

    <ul
      v-else
      class="adm-list"
    >
      <li
        v-for="tax in taxTypes"
        :key="tax.id"
      >
        <form
          v-if="editingId === tax.id"
          class="adm-form tax-form"
          novalidate
          @submit.prevent="save"
        >
          <div class="tax-form__grid">
            <div class="adm-field r-field">
              <label
                :for="`tax-name-${tax.id}`"
                class="adm-field__label r-label"
              >{{ t.taxName }}</label>
              <input
                :id="`tax-name-${tax.id}`"
                v-model="form.name"
                class="adm-input r-input"
                maxlength="20"
                :aria-invalid="errors.name ? 'true' : undefined"
              >
              <p
                v-if="errors.name"
                class="adm-error r-err"
              >
                {{ errors.name }}
              </p>
            </div>
            <div class="adm-field r-field">
              <label
                :for="`tax-rate-${tax.id}`"
                class="adm-field__label r-label"
              >{{ t.taxRate }}</label>
              <input
                :id="`tax-rate-${tax.id}`"
                v-model="form.rate"
                class="adm-input r-input"
                inputmode="decimal"
                maxlength="5"
                :aria-invalid="errors.rate_permille ? 'true' : undefined"
              >
              <p
                v-if="errors.rate_permille"
                class="adm-error r-err"
              >
                {{ errors.rate_permille }}
              </p>
            </div>
          </div>
          <div class="adm-actions">
            <label class="adm-check"><input
              v-model="form.is_default"
              type="checkbox"
              :disabled="!form.is_active"
            >{{ t.taxDefault }}</label>
            <label class="adm-check"><input
              v-model="form.is_active"
              type="checkbox"
            >{{ ja.common.active }}</label>
          </div>
          <p
            v-if="errors.is_default"
            class="adm-error r-err"
          >
            {{ errors.is_default }}
          </p>
          <p
            v-if="errors.is_active"
            class="adm-error r-err"
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
          :class="{ 'adm-row--off': !tax.is_active }"
        >
          <span class="adm-row__main">{{ tax.name }}</span>
          <span class="tabular">{{ permilleToPercent(tax.rate_permille) }}%</span>
          <span
            v-if="tax.is_default"
            class="adm-badge adm-badge--primary r-chip r-chip--ok"
          >{{ t.taxDefault }}</span>
          <span
            v-if="!tax.is_active"
            class="adm-badge r-chip r-chip--neutral"
          >{{ ja.common.inactive }}</span>
          <button
            type="button"
            class="adm-btn"
            :aria-label="fmt(ja.common.editNamed, { name: tax.name })"
            @click="startEdit(tax)"
          >
            {{ ja.common.edit }}
          </button>
        </div>
      </li>
    </ul>

    <form
      v-if="editingId === 'new'"
      class="adm-form tax-form"
      novalidate
      @submit.prevent="save"
    >
      <div class="tax-form__grid">
        <div class="adm-field r-field">
          <label
            for="tax-name-new"
            class="adm-field__label r-label"
          >{{ t.taxName }}</label>
          <input
            id="tax-name-new"
            v-model="form.name"
            class="adm-input r-input"
            maxlength="20"
            :aria-invalid="errors.name ? 'true' : undefined"
          >
          <p
            v-if="errors.name"
            class="adm-error r-err"
          >
            {{ errors.name }}
          </p>
        </div>
        <div class="adm-field r-field">
          <label
            for="tax-rate-new"
            class="adm-field__label r-label"
          >{{ t.taxRate }}</label>
          <input
            id="tax-rate-new"
            v-model="form.rate"
            class="adm-input r-input"
            inputmode="decimal"
            maxlength="5"
            aria-describedby="tax-rate-help"
            :aria-invalid="errors.rate_permille ? 'true' : undefined"
          >
          <p
            id="tax-rate-help"
            class="adm-help r-help"
          >
            {{ t.taxRateHelp }}
          </p>
          <p
            v-if="errors.rate_permille"
            class="adm-error r-err"
          >
            {{ errors.rate_permille }}
          </p>
        </div>
      </div>
      <label class="adm-check"><input
        v-model="form.is_default"
        type="checkbox"
      >{{ t.taxDefault }}</label>
      <p class="adm-help r-help">
        {{ t.taxDefaultHelp }}
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
        :disabled="taxTypes.length >= MAX"
        @click="startNew"
      >
        {{ t.taxAdd }}
      </BigButton>
    </div>
  </section>
</template>

<style scoped>
.tax-form { padding: 16px; border: 2px solid var(--c-focus); border-radius: var(--radius); }
.tax-form__grid { display: grid; grid-template-columns: 1fr; gap: 12px; }

@media (min-width: 768px) {
  .tax-form__grid { grid-template-columns: 2fr 1fr; }
}
</style>
