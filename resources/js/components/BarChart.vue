<script setup lang="ts">
// 棒グラフ（S06）。Chart.js はこの部品が表示されたときに動的 import で読み込む（08 §5.7、AC-S06-4）
import type { Chart } from 'chart.js'
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { ja } from '@/i18n/ja'
import { formatYen } from '@/lib/money'

const props = defineProps<{ labels: string[]; values: number[]; label: string }>()

const canvas = ref<HTMLCanvasElement | null>(null)
const chart = shallowRef<Chart<'bar'> | null>(null)
const state = ref<'loading' | 'ready' | 'failed'>('loading')

function cssVar(name: string, fallback: string): string {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value === '' ? fallback : value
}

async function draw(): Promise<void> {
  try {
    const { BarController, BarElement, CategoryScale, Chart: ChartJs, LinearScale, Tooltip } = await import('chart.js')
    ChartJs.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)
    const context = canvas.value?.getContext('2d')
    if (!context) throw new Error('canvas unavailable')
    chart.value = new ChartJs(context, {
      type: 'bar',
      data: {
        labels: props.labels,
        datasets: [{ data: props.values, backgroundColor: cssVar('--c-primary', '#2F63DB'), borderRadius: 4 }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: { tooltip: { callbacks: { label: (item) => formatYen(Number(item.raw)) } } },
        scales: {
          x: { ticks: { font: { size: 14 }, autoSkip: true, maxRotation: 0 } },
          y: { beginAtZero: true, ticks: { font: { size: 14 }, callback: (v) => formatYen(Number(v)) } },
        },
      },
    })
    state.value = 'ready'
  } catch {
    state.value = 'failed'
  }
}

watch(
  () => [props.labels, props.values] as const,
  ([labels, values]) => {
    const c = chart.value
    if (!c) return
    c.data.labels = labels
    const dataset = c.data.datasets[0]
    if (dataset) dataset.data = values
    c.update()
  },
)

onMounted(draw)
onBeforeUnmount(() => chart.value?.destroy())
</script>

<template>
  <div class="bar-chart">
    <canvas
      ref="canvas"
      role="img"
      :aria-label="label"
    />
    <p
      v-if="state === 'loading'"
      class="bar-chart__note"
      role="status"
    >
      {{ ja.summary.chartLoading }}
    </p>
    <p
      v-else-if="state === 'failed'"
      class="bar-chart__note"
    >
      {{ ja.summary.chartFailed }}
    </p>
  </div>
</template>

<style scoped>
.bar-chart { position: relative; height: 260px; }
.bar-chart__note { position: absolute; inset: 0; display: grid; place-items: center; color: var(--c-text-sub); }
</style>
