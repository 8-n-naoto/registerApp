<script setup lang="ts">
// S08 編集パネルのオプション（06 §7.9、docs/10「オプションのグループ」）：名前・金額・有効・グループ・最初に選ぶ、追加・並び替え・削除
// グループ（3 つまで）は名前と選び方（1つ選ぶ / いくつでも）を変えるとすぐ保存する。グループなしのオプションは最後に並べる
import { computed, nextTick, ref } from 'vue'
import {
  createOption, createOptionGroup, deleteOption, deleteOptionGroup, reorderOptions, updateOption, updateOptionGroup,
  type OptionGroupInput,
} from '@/api/catalog'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import SortableList from '@/components/SortableList.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatYen } from '@/lib/money'
import { parseSignedInt } from '@/lib/numberInput'
import type { OptionSelection, ProductOption, ProductOptionGroup } from '@/types/api'

const MAX = 10
const MAX_GROUPS = 3
const PRICE_LIMIT = 999_999
const SELECTIONS: OptionSelection[] = ['single', 'multi']
const t = ja.products

const props = defineProps<{ productId: number }>()
const options = defineModel<ProductOption[]>('options', { required: true })
const groups = defineModel<ProductOptionGroup[]>('groups', { required: true })

const editingId = ref<number | 'new' | null>(null)
const form = ref({ name: '', price: '', is_active: true, group_id: null as number | null, is_default: false })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)
const formEl = ref<HTMLFormElement | null>(null)

const reordering = ref(false)
const draft = ref<ProductOption[]>([])

const deleting = ref<ProductOption | null>(null)
const deleteFailed = ref<string | null>(null)

const newGroup = ref<OptionGroupInput | null>(null)
const groupErrors = ref<Record<string, string>>({})
const deletingGroup = ref<ProductOptionGroup | null>(null)

const looseOptions = computed(() => options.value.filter((o) => o.group_id === null || !groups.value.some((g) => g.id === o.group_id)))
const formGroup = computed(() => groups.value.find((g) => g.id === form.value.group_id) ?? null)

function optionsOf(group: ProductOptionGroup): ProductOption[] {
  return options.value.filter((o) => o.group_id === group.id)
}

async function openForm(): Promise<void> {
  errors.value = {}
  failed.value = null
  await nextTick()
  formEl.value?.scrollIntoView({ block: 'nearest' })
  formEl.value?.querySelector<HTMLInputElement>('#option-name')?.focus()
}

function startNew(groupId: number | null): void {
  editingId.value = 'new'
  const group = groups.value.find((g) => g.id === groupId)
  // 「1つ選ぶ」グループの最初のオプションは「最初に選ぶ」にしておく
  const first = group?.selection === 'single' && optionsOf(group).length === 0
  form.value = { name: '', price: '0', is_active: true, group_id: groupId, is_default: first }
  void openForm()
}

function startEdit(option: ProductOption): void {
  editingId.value = option.id
  form.value = {
    name: option.name, price: String(option.price), is_active: option.is_active, group_id: option.group_id, is_default: option.is_default,
  }
  void openForm()
}

/** 保存したオプションを反映する。「最初に選ぶ」は同じグループに 1 つだけ（サーバーと同じ） */
function applySaved(saved: ProductOption): void {
  const exists = options.value.some((o) => o.id === saved.id)
  const next = exists ? options.value.map((o) => (o.id === saved.id ? saved : o)) : [...options.value, saved]
  options.value = saved.is_default
    ? next.map((o) => (o.id !== saved.id && o.group_id === saved.group_id && o.is_default ? { ...o, is_default: false } : o))
    : next
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
    const input = {
      name: form.value.name.trim(),
      price,
      is_active: form.value.is_active,
      group_id: form.value.group_id,
      is_default: formGroup.value?.selection === 'single' && form.value.is_default,
    }
    const id = editingId.value
    if (id === 'new') applySaved(await createOption(props.productId, input))
    else if (id !== null) applySaved(await updateOption(id, input))
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
  newGroup.value = null
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

function groupError(err: unknown): void {
  if (errorStatus(err) === 422) groupErrors.value = fieldErrors(err)
  else if (!isNetworkError(err)) failed.value = errorBody(err)?.message ?? ja.error.unexpected
}

function startNewGroup(): void {
  editingId.value = null
  groupErrors.value = {}
  newGroup.value = { name: '', selection: 'single' }
}

async function addGroup(): Promise<void> {
  const input = newGroup.value
  if (!input || saving.value) return
  saving.value = true
  groupErrors.value = {}
  failed.value = null
  try {
    const saved = await createOptionGroup(props.productId, { name: input.name.trim(), selection: input.selection })
    groups.value = [...groups.value, saved]
    newGroup.value = null
  } catch (err) {
    groupError(err)
  } finally {
    saving.value = false
  }
}

/** 名前・選び方を変えたらすぐ保存する。保存しなかったとき（空の名前・失敗）は false（呼び出し側で欄を元に戻す） */
async function changeGroup(group: ProductOptionGroup, patch: Partial<OptionGroupInput>): Promise<boolean> {
  const input = { name: (patch.name ?? group.name).trim(), selection: patch.selection ?? group.selection }
  if (input.name === group.name && input.selection === group.selection) return true
  if (input.name === '' || saving.value) return false
  saving.value = true
  groupErrors.value = {}
  failed.value = null
  try {
    const saved = await updateOptionGroup(group.id, input)
    groups.value = groups.value.map((g) => (g.id === saved.id ? saved : g))
    // 「いくつでも」に変えると「最初に選ぶ」は外れる（サーバーと同じ）
    if (saved.selection === 'multi') {
      options.value = options.value.map((o) => (o.group_id === saved.id && o.is_default ? { ...o, is_default: false } : o))
    }
    return true
  } catch (err) {
    groupError(err)
    return false
  } finally {
    saving.value = false
  }
}

async function onGroupName(group: ProductOptionGroup, event: Event): Promise<void> {
  const el = event.target as HTMLInputElement
  if (!(await changeGroup(group, { name: el.value }))) el.value = group.name
}

async function onGroupSelection(group: ProductOptionGroup, event: Event): Promise<void> {
  const el = event.target as HTMLSelectElement
  const selection = SELECTIONS.find((s) => s === el.value)
  if (!selection || !(await changeGroup(group, { selection }))) el.value = group.selection
}

async function confirmDeleteGroup(): Promise<void> {
  const target = deletingGroup.value
  if (!target || saving.value) return
  saving.value = true
  deleteFailed.value = null
  try {
    await deleteOptionGroup(target.id)
    // 中のオプションもサーバーで削除される
    options.value = options.value.filter((o) => o.group_id !== target.id)
    groups.value = groups.value.filter((g) => g.id !== target.id)
    if (editingId.value !== null && form.value.group_id === target.id) editingId.value = null
    deletingGroup.value = null
  } catch (err) {
    if (!isNetworkError(err)) deleteFailed.value = errorBody(err)?.message ?? ja.error.unexpected
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

    <template v-else>
      <section
        v-for="group in groups"
        :key="group.id"
        class="gbox"
        :data-group="group.id"
      >
        <div class="gbox__top">
          <input
            class="adm-input gbox__name"
            :value="group.name"
            maxlength="30"
            :aria-label="t.groupName"
            @change="onGroupName(group, $event)"
          >
          <select
            class="adm-select"
            :value="group.selection"
            :aria-label="fmt(t.groupSelection, { name: group.name })"
            @change="onGroupSelection(group, $event)"
          >
            <option
              v-for="s in SELECTIONS"
              :key="s"
              :value="s"
            >
              {{ t.selection[s] }}
            </option>
          </select>
          <button
            type="button"
            class="adm-btn adm-btn--danger"
            :aria-label="fmt(t.groupDeleteNamed, { name: group.name })"
            @click="deletingGroup = group"
          >
            {{ ja.common.delete }}
          </button>
        </div>
        <ul class="adm-list">
          <li
            v-for="option in optionsOf(group)"
            :key="option.id"
          >
            <div
              class="adm-row"
              :class="{ 'adm-row--off': !option.is_active }"
            >
              <span class="adm-row__main">{{ option.name }}<span
                v-if="option.is_default"
                class="def"
              >{{ t.optionDefault }}</span></span>
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
        <button
          type="button"
          class="adm-btn gbox__add"
          :disabled="options.length >= MAX"
          @click="startNew(group.id)"
        >
          {{ t.optionAdd }}
        </button>
      </section>
      <p
        v-if="groupErrors.name || groupErrors.selection"
        class="adm-error"
        role="alert"
      >
        {{ groupErrors.name ?? groupErrors.selection }}
      </p>

      <section
        v-if="looseOptions.length > 0"
        :class="{ gbox: groups.length > 0 }"
      >
        <p
          v-if="groups.length > 0"
          class="gbox__loose"
        >
          {{ t.groupNone }}
        </p>
        <ul class="adm-list">
          <li
            v-for="option in looseOptions"
            :key="option.id"
          >
            <div
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
      </section>
    </template>

    <form
      v-if="editingId !== null && !reordering"
      ref="formEl"
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
      <div
        v-if="groups.length > 0"
        class="adm-field"
      >
        <label
          for="option-group"
          class="adm-field__label"
        >{{ t.optionGroup }}</label>
        <select
          id="option-group"
          v-model="form.group_id"
          class="adm-select"
          :aria-invalid="errors.group_id ? 'true' : undefined"
        >
          <option :value="null">
            {{ t.groupNone }}
          </option>
          <option
            v-for="group in groups"
            :key="group.id"
            :value="group.id"
          >
            {{ group.name }}
          </option>
        </select>
        <p
          v-if="errors.group_id"
          class="adm-error"
        >
          {{ errors.group_id }}
        </p>
      </div>
      <label
        v-if="formGroup?.selection === 'single'"
        class="adm-check"
      ><input
        v-model="form.is_default"
        type="checkbox"
      >{{ t.optionDefaultCheck }}</label>
      <p
        v-if="errors.is_default"
        class="adm-error"
      >
        {{ errors.is_default }}
      </p>
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

    <form
      v-else-if="newGroup && !reordering"
      class="adm-form options__form"
      novalidate
      @submit.prevent="addGroup"
    >
      <div class="adm-field">
        <label
          for="group-name"
          class="adm-field__label"
        >{{ t.groupName }}</label>
        <input
          id="group-name"
          v-model="newGroup.name"
          class="adm-input"
          maxlength="30"
          :placeholder="t.groupNamePlaceholder"
          :aria-invalid="groupErrors.name ? 'true' : undefined"
        >
      </div>
      <div class="adm-field">
        <label
          for="group-selection"
          class="adm-field__label"
        >{{ t.groupSelectionLabel }}</label>
        <select
          id="group-selection"
          v-model="newGroup.selection"
          class="adm-select"
        >
          <option
            v-for="s in SELECTIONS"
            :key="s"
            :value="s"
          >
            {{ t.selection[s] }}
          </option>
        </select>
      </div>
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
          @click="newGroup = null"
        >
          {{ ja.common.cancel }}
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
        @click="startNew(null)"
      >
        {{ groups.length > 0 ? t.optionAddLoose : t.optionAdd }}
      </button>
      <button
        type="button"
        class="adm-btn"
        :disabled="groups.length >= MAX_GROUPS"
        @click="startNewGroup"
      >
        {{ t.groupAdd }}
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
    <ConfirmDialog
      :open="deletingGroup !== null"
      :title="fmt(t.deleteGroupTitle, { name: deletingGroup?.name ?? '' })"
      :message="deletingGroup ? fmt(t.deleteGroupMessage, { n: optionsOf(deletingGroup).length }) : ''"
      :confirm-label="ja.common.delete"
      danger
      :loading="saving"
      :error="deleteFailed"
      @confirm="confirmDeleteGroup"
      @cancel="deletingGroup = null"
    />
  </div>
</template>

<style scoped>
.options { display: flex; flex-direction: column; gap: 12px; }
.options__title { font-size: 20px; }
.options__form { padding: 16px; border: 2px solid var(--c-focus); border-radius: var(--radius); }

.gbox { display: flex; flex-direction: column; gap: 10px; padding: 14px; border: 2px solid var(--c-border); border-radius: var(--radius); }
.gbox__top { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.gbox__name { flex: 1 1 160px; width: auto; font-weight: 700; }
.gbox__add { align-self: flex-start; }
.gbox__loose { margin: 0; color: var(--c-text-sub); font-weight: 700; }
.def {
  margin-left: 6px;
  padding: 0 8px;
  border: 1px solid var(--c-primary);
  border-radius: 999px;
  color: var(--c-primary);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}
</style>
