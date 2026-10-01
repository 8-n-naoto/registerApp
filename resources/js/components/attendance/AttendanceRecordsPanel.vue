<script setup lang="ts">
// 13 §6.4 S20［打刻］（owner）：月の打刻の一覧、人で絞る、追加・修正・削除（#71〜#74）
import { computed, onMounted, ref, watch } from 'vue'
import { createAttendance, deleteAttendance, fetchAttendances, fetchLaborMembers, updateAttendance } from '@/api/attendance'
import AttendanceTable from '@/components/attendance/AttendanceTable.vue'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatDay, toLocalInput } from '@/lib/labor'
import type { Attendance, LaborMember } from '@/types/api'

const props = defineProps<{ month: string }>()
const emit = defineEmits<{ changed: [] }>()

const t = ja.attendance

const rows = ref<Attendance[]>([])
const members = ref<LaborMember[]>([])
const filterUser = ref<number | null>(null)
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const notice = ref<string | null>(null)

interface BreakForm { started_at: string; ended_at: string }
const editing = ref<Attendance | 'new' | null>(null)
const form = ref({ user_id: 0, clock_in_at: '', clock_out_at: '', breaks: [] as BreakForm[] })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)
const confirmingDelete = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

const sheetTitle = computed(() => (editing.value === 'new' ? t.addTitle : t.editTitle))
const editingRow = computed(() => (editing.value !== null && editing.value !== 'new' ? editing.value : null))

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    const [list, people] = await Promise.all([fetchAttendances(props.month, filterUser.value), fetchLaborMembers()])
    rows.value = list.attendances
    members.value = people
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.month, load)
watch(filterUser, load)

function resetForm(): void {
  errors.value = {}
  failed.value = null
  notice.value = null
}

function startNew(): void {
  resetForm()
  const first = filterUser.value ?? members.value.find((m) => m.is_active)?.id ?? 0
  form.value = { user_id: first, clock_in_at: '', clock_out_at: '', breaks: [] }
  editing.value = 'new'
}

function startEdit(row: Attendance): void {
  resetForm()
  form.value = {
    user_id: row.user_id,
    clock_in_at: toLocalInput(row.clock_in_at),
    clock_out_at: toLocalInput(row.clock_out_at),
    breaks: row.breaks.map((b) => ({ started_at: toLocalInput(b.started_at), ended_at: toLocalInput(b.ended_at) })),
  }
  editing.value = row
}

function close(): void {
  editing.value = null
}

function addBreak(): void {
  form.value.breaks.push({ started_at: '', ended_at: '' })
}

function removeBreak(i: number): void {
  form.value.breaks.splice(i, 1)
}

function breakError(i: number): string | undefined {
  return errors.value[`breaks.${i}.started_at`] ?? errors.value[`breaks.${i}.ended_at`]
}

async function save(): Promise<void> {
  if (saving.value || editing.value === null) return
  errors.value = {}
  failed.value = null
  saving.value = true
  const input = {
    clock_in_at: form.value.clock_in_at,
    clock_out_at: form.value.clock_out_at === '' ? null : form.value.clock_out_at,
    breaks: form.value.breaks.map((b) => ({ started_at: b.started_at, ended_at: b.ended_at === '' ? null : b.ended_at })),
  }
  try {
    if (editing.value === 'new') await createAttendance({ ...input, user_id: form.value.user_id })
    else await updateAttendance(editing.value.id, input)
    editing.value = null
    notice.value = t.saved
    emit('changed')
    await load()
  } catch (err) {
    if (errorStatus(err) === 422) {
      errors.value = fieldErrors(err)
      if (Object.keys(errors.value).length === 0) failed.value = errorBody(err)?.message ?? ja.error.unexpected
    } else if (errorStatus(err) === 404) {
      editing.value = null
      await load()
    } else if (!isNetworkError(err)) {
      failed.value = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  const row = editingRow.value
  if (!row || deleting.value) return
  deleting.value = true
  deleteError.value = null
  try {
    await deleteAttendance(row.id)
    confirmingDelete.value = false
    editing.value = null
    notice.value = t.deleted
    emit('changed')
    await load()
  } catch (err) {
    if (!isNetworkError(err)) deleteError.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <section
    class="adm-panel"
    aria-labelledby="att-records-heading"
  >
    <div class="adm-panel__head">
      <h2
        id="att-records-heading"
        class="adm-panel__title"
      >
        {{ t.tabs.records }}
      </h2>
      <button
        type="button"
        class="adm-btn"
        :disabled="loading || members.length === 0"
        @click="startNew"
      >
        {{ t.add }}
      </button>
    </div>
    <div class="adm-field att-filter">
      <label
        for="att-filter-user"
        class="adm-field__label"
      >{{ t.filterUser }}</label>
      <select
        id="att-filter-user"
        v-model="filterUser"
        class="adm-select"
      >
        <option :value="null">
          {{ t.allUsers }}
        </option>
        <option
          v-for="m in members"
          :key="m.id"
          :value="m.id"
        >
          {{ m.name }}{{ m.is_active ? '' : `（${t.inactive}）` }}
        </option>
      </select>
    </div>
    <p
      v-if="notice"
      class="adm-ok"
      role="status"
    >
      {{ notice }}
    </p>
    <p
      v-if="loading"
      role="status"
    >
      {{ ja.common.loading }}
    </p>
    <template v-else-if="loadFailed">
      <p
        class="adm-error"
        role="alert"
      >
        {{ loadFailed }}
      </p>
      <div class="adm-actions">
        <BigButton @click="load">
          {{ t.retry }}
        </BigButton>
      </div>
    </template>
    <p v-else-if="rows.length === 0">
      {{ t.empty }}
    </p>
    <AttendanceTable
      v-else
      :attendances="rows"
      show-name
      editable
      @edit="startEdit"
    />

    <BottomSheet
      :open="editing !== null"
      :title="sheetTitle"
      @close="close"
    >
      <form
        class="adm-form"
        novalidate
        :aria-label="sheetTitle"
        @submit.prevent="save"
      >
        <div
          v-if="editing === 'new'"
          class="adm-field"
        >
          <label
            for="att-user"
            class="adm-field__label"
          >{{ t.person }}</label>
          <select
            id="att-user"
            v-model="form.user_id"
            class="adm-select"
            :aria-invalid="errors.user_id ? 'true' : undefined"
          >
            <option
              v-for="m in members"
              :key="m.id"
              :value="m.id"
            >
              {{ m.name }}
            </option>
          </select>
          <p
            v-if="errors.user_id"
            class="adm-error"
          >
            {{ errors.user_id }}
          </p>
        </div>
        <p
          v-else-if="editingRow"
          class="att-form__who"
        >
          {{ editingRow.user_name }}・{{ formatDay(editingRow.business_date) }}
        </p>
        <div class="adm-field">
          <label
            for="att-in"
            class="adm-field__label"
          >{{ t.clockInAt }}</label>
          <input
            id="att-in"
            v-model="form.clock_in_at"
            class="adm-input"
            type="datetime-local"
            :aria-invalid="errors.clock_in_at ? 'true' : undefined"
          >
          <p
            v-if="errors.clock_in_at"
            class="adm-error"
          >
            {{ errors.clock_in_at }}
          </p>
        </div>
        <div class="adm-field">
          <label
            for="att-out"
            class="adm-field__label"
          >{{ t.clockOutAt }}</label>
          <input
            id="att-out"
            v-model="form.clock_out_at"
            class="adm-input"
            type="datetime-local"
            :aria-invalid="errors.clock_out_at ? 'true' : undefined"
          >
          <p
            v-if="errors.clock_out_at"
            class="adm-error"
          >
            {{ errors.clock_out_at }}
          </p>
        </div>
        <fieldset class="att-breaks">
          <legend class="adm-field__label">
            {{ t.breaks }}
          </legend>
          <div
            v-for="(b, i) in form.breaks"
            :key="i"
            class="att-break"
          >
            <div class="adm-field">
              <label
                :for="`att-break-s-${i}`"
                class="adm-field__label"
              >{{ t.breakStart }}</label>
              <input
                :id="`att-break-s-${i}`"
                v-model="b.started_at"
                class="adm-input"
                type="datetime-local"
              >
            </div>
            <div class="adm-field">
              <label
                :for="`att-break-e-${i}`"
                class="adm-field__label"
              >{{ t.breakEnd }}</label>
              <input
                :id="`att-break-e-${i}`"
                v-model="b.ended_at"
                class="adm-input"
                type="datetime-local"
              >
            </div>
            <button
              type="button"
              class="adm-btn adm-btn--danger"
              @click="removeBreak(i)"
            >
              {{ t.removeBreak }}
            </button>
            <p
              v-if="breakError(i)"
              class="adm-error att-break__error"
            >
              {{ breakError(i) }}
            </p>
          </div>
          <p
            v-if="errors.breaks"
            class="adm-error"
          >
            {{ errors.breaks }}
          </p>
          <button
            type="button"
            class="adm-btn"
            @click="addBreak"
          >
            {{ t.addBreak }}
          </button>
        </fieldset>
        <p
          v-if="failed"
          class="adm-error"
          role="alert"
        >
          {{ failed }}
        </p>
        <div class="adm-actions">
          <BigButton
            type="submit"
            :loading="saving"
          >
            {{ t.save }}
          </BigButton>
          <BigButton
            variant="secondary"
            @click="close"
          >
            {{ t.cancel }}
          </BigButton>
          <BigButton
            v-if="editingRow"
            variant="danger"
            @click="confirmingDelete = true"
          >
            {{ t.delete }}
          </BigButton>
        </div>
      </form>
    </BottomSheet>

    <ConfirmDialog
      :open="confirmingDelete"
      :title="t.deleteTitle"
      :message="editingRow ? fmt(t.deleteMessage, { name: editingRow.user_name, date: formatDay(editingRow.business_date) }) : ''"
      :confirm-label="t.delete"
      danger
      :loading="deleting"
      :error="deleteError"
      @confirm="remove"
      @cancel="confirmingDelete = false"
    />
  </section>
</template>

<style scoped>
.att-filter { max-width: 320px; }
.att-form__who { font-size: 18px; font-weight: 700; }
.att-breaks { display: flex; flex-direction: column; gap: 12px; margin: 0; padding: 0; border: 0; }

.att-break {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 8px 12px;
  padding: 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
}

.att-break .adm-field { flex: 1 1 200px; }
.att-break__error { flex-basis: 100%; }
</style>
