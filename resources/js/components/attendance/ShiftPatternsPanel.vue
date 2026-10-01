<script setup lang="ts">
// 13 §6.9 S21［区分］：勤務の区分（例 A：09:00〜12:00・13:00〜15:00）を owner が作る・直す（#91〜#93）。消さずに［使わない］で隠す
import { computed, onMounted, ref } from 'vue'
import { createShiftPattern, fetchShiftPatterns, updateShiftPattern } from '@/api/shifts'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatMinutes, normalizeShiftTime, segmentsText } from '@/lib/labor'
import type { ShiftPattern } from '@/types/api'

const emit = defineEmits<{ changed: [] }>()

const t = ja.shifts
const MAX_SEGMENTS = 3

type Active = 'true' | 'false'
const activeOptions = [
  { value: 'true', label: t.patternActive.true },
  { value: 'false', label: t.patternActive.false },
] as const satisfies readonly { value: Active; label: string }[]

const patterns = ref<ShiftPattern[]>([])
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    patterns.value = await fetchShiftPatterns()
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.patternsLoadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)

// 区分の追加・修正
const editing = ref<ShiftPattern | 'new' | null>(null)
const form = ref<{ name: string; segments: { start: string; end: string }[]; active: Active }>({ name: '', segments: [], active: 'true' })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)
const sheetTitle = computed(() => (editing.value === 'new' ? t.addPattern : t.editPatternTitle))

function startNew(): void {
  errors.value = {}
  failed.value = null
  notice.value = null
  form.value = { name: '', segments: [{ start: '', end: '' }], active: 'true' }
  editing.value = 'new'
}

function startEdit(p: ShiftPattern): void {
  errors.value = {}
  failed.value = null
  notice.value = null
  form.value = { name: p.name, segments: p.segments.map((s) => ({ ...s })), active: p.is_active ? 'true' : 'false' }
  editing.value = p
}

function addSegment(): void {
  if (form.value.segments.length < MAX_SEGMENTS) form.value.segments.push({ start: '', end: '' })
}

function removeSegment(index: number): void {
  if (form.value.segments.length > 1) form.value.segments.splice(index, 1)
}

async function save(): Promise<void> {
  if (saving.value || editing.value === null) return
  errors.value = {}
  failed.value = null
  for (const s of form.value.segments) {
    s.start = normalizeShiftTime(s.start)
    s.end = normalizeShiftTime(s.end)
  }
  const input = {
    name: form.value.name.trim(),
    segments: form.value.segments.map((s) => ({ start: s.start, end: s.end })),
    is_active: form.value.active === 'true',
  }
  saving.value = true
  try {
    if (editing.value === 'new') await createShiftPattern(input)
    else await updateShiftPattern(editing.value.id, input)
    editing.value = null
    notice.value = t.patternSaved
    await load()
    emit('changed')
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
</script>

<template>
  <section
    class="adm-panel"
    aria-labelledby="shift-patterns-heading"
  >
    <div class="adm-panel__head">
      <h2
        id="shift-patterns-heading"
        class="adm-panel__title"
      >
        {{ t.tabs.patterns }}
      </h2>
      <button
        type="button"
        class="adm-btn"
        @click="startNew"
      >
        {{ t.addPattern }}
      </button>
    </div>
    <p class="adm-help">
      {{ t.patternsHelp }}
    </p>
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
    <p v-else-if="patterns.length === 0">
      {{ t.noPatterns }}
    </p>
    <ul
      v-else
      class="pattern-list"
    >
      <li
        v-for="p in patterns"
        :key="p.id"
      >
        <button
          type="button"
          class="pattern-item"
          :class="{ 'pattern-item--off': !p.is_active }"
          :aria-label="fmt(ja.common.editNamed, { name: p.name })"
          @click="startEdit(p)"
        >
          <span class="pattern-item__name">{{ p.name }}</span>
          <span class="pattern-item__time">{{ segmentsText(p.segments) }}</span>
          <span
            v-if="p.break_minutes > 0"
            class="pattern-item__sub"
          >{{ fmt(t.patternBreak, { time: formatMinutes(p.break_minutes) }) }}</span>
          <span
            v-if="!p.is_active"
            class="adm-badge"
          >{{ t.inactive }}</span>
        </button>
      </li>
    </ul>

    <BottomSheet
      :open="editing !== null"
      :title="sheetTitle"
      @close="editing = null"
    >
      <form
        class="adm-form"
        novalidate
        :aria-label="sheetTitle"
        @submit.prevent="save"
      >
        <div class="adm-field">
          <label
            for="pattern-name"
            class="adm-field__label"
          >{{ t.patternName }}</label>
          <input
            id="pattern-name"
            v-model="form.name"
            class="adm-input pattern-form__short"
            maxlength="20"
            autocomplete="off"
            :placeholder="t.patternNameHelp"
            :aria-invalid="errors.name ? 'true' : undefined"
          >
          <p
            v-if="errors.name"
            class="adm-error"
          >
            {{ errors.name }}
          </p>
        </div>
        <fieldset class="pattern-form__segments">
          <div
            v-for="(seg, i) in form.segments"
            :key="i"
            class="pattern-form__segment"
          >
            <div class="adm-field">
              <label
                :for="`pattern-start-${i}`"
                class="adm-field__label"
              >{{ fmt(t.segment, { n: i + 1 }) }} {{ t.start }}</label>
              <input
                :id="`pattern-start-${i}`"
                v-model="seg.start"
                class="adm-input"
                inputmode="numeric"
                placeholder="09:00"
                maxlength="5"
                autocomplete="off"
                :aria-invalid="errors.segments ? 'true' : undefined"
                @blur="seg.start = normalizeShiftTime(seg.start)"
              >
            </div>
            <div class="adm-field">
              <label
                :for="`pattern-end-${i}`"
                class="adm-field__label"
              >{{ fmt(t.segment, { n: i + 1 }) }} {{ t.end }}</label>
              <input
                :id="`pattern-end-${i}`"
                v-model="seg.end"
                class="adm-input"
                inputmode="numeric"
                placeholder="12:00"
                maxlength="5"
                autocomplete="off"
                :aria-invalid="errors.segments ? 'true' : undefined"
                @blur="seg.end = normalizeShiftTime(seg.end)"
              >
            </div>
            <button
              v-if="form.segments.length > 1"
              type="button"
              class="adm-btn pattern-form__remove"
              :aria-label="fmt(t.removeSegment, { n: i + 1 })"
              @click="removeSegment(i)"
            >
              ×
            </button>
          </div>
          <p class="adm-help">
            {{ t.timeHelp }}
          </p>
          <p
            v-if="errors.segments"
            class="adm-error"
          >
            {{ errors.segments }}
          </p>
          <button
            v-if="form.segments.length < MAX_SEGMENTS"
            type="button"
            class="adm-btn"
            @click="addSegment"
          >
            {{ t.addSegment }}
          </button>
        </fieldset>
        <div class="adm-field">
          <span class="adm-field__label">{{ t.patternActiveLabel }}</span>
          <SegmentedControl
            v-model="form.active"
            :options="activeOptions"
            :label="t.patternActiveLabel"
          />
          <p
            v-if="errors.is_active"
            class="adm-error"
          >
            {{ errors.is_active }}
          </p>
        </div>
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
            {{ t.savePattern }}
          </BigButton>
          <BigButton
            variant="secondary"
            @click="editing = null"
          >
            {{ t.cancel }}
          </BigButton>
        </div>
      </form>
    </BottomSheet>
  </section>
</template>

<style scoped>
.pattern-list { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }

.pattern-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 12px;
  width: 100%;
  min-height: var(--tap-min);
  padding: 8px 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 16px;
  text-align: left;
  cursor: pointer;
}

.pattern-item--off { background: var(--c-surface-alt); color: var(--c-text-sub); }
.pattern-item__name { min-width: 3em; font-size: 18px; font-weight: 700; }
.pattern-item__time { font-variant-numeric: tabular-nums; font-weight: 700; }
.pattern-item__sub { color: var(--c-text-sub); }
.pattern-form__short { max-width: 240px; }
.pattern-form__segments { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; border: 0; }
.pattern-form__segment { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.pattern-form__segment .adm-field { flex: 1 1 120px; }
.pattern-form__remove { min-width: var(--tap-min); }
</style>
