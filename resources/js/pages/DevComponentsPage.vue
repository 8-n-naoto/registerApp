<script setup lang="ts">
// 共通部品の見本（開発時のみ。本番のルートには登録しない）
import { ref } from 'vue'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import MoneyText from '@/components/MoneyText.vue'
import WaveBackground from '@/components/WaveBackground.vue'

const loading = ref(false)
function toggleLoading(): void {
  loading.value = true
  window.setTimeout(() => (loading.value = false), 1500)
}

const colors = ['gray', 'red', 'orange', 'yellow', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink'] as const
</script>

<template>
  <div>
    <AppHeader
      title="部品の見本"
      viewing-store-name="閲覧中：見本店（閲覧のみ）"
    />
    <main class="dev">
      <section>
        <h2>BigButton</h2>
        <div class="dev__row">
          <BigButton>保存</BigButton>
          <BigButton variant="secondary">
            キャンセル
          </BigButton>
          <BigButton variant="danger">
            取消
          </BigButton>
          <BigButton
            size="lg"
            :loading="loading"
            @click="toggleLoading"
          >
            お会計へ
          </BigButton>
          <BigButton disabled>
            無効
          </BigButton>
        </div>
        <BigButton
          size="xl"
          block
        >
          確定
        </BigButton>
      </section>

      <section>
        <h2>MoneyText</h2>
        <p><MoneyText :amount="1234" /> / <MoneyText :amount="-500" /></p>
        <p>
          <MoneyText
            :amount="12345"
            size="amount"
          />
        </p>
        <p>
          <MoneyText
            :amount="3080"
            size="total"
            tone="money"
          />
        </p>
        <p>
          <MoneyText
            :amount="1920"
            size="change"
            tone="change"
          />
        </p>
        <p>
          <MoneyText
            :amount="245800"
            size="daily"
          />
        </p>
      </section>

      <section>
        <h2>商品ボタンの色（05 §5.4）</h2>
        <div class="dev__palette">
          <span
            v-for="c in colors"
            :key="c"
            class="dev__chip"
            :style="{ background: `var(--pc-${c}-bg)`, color: `var(--pc-${c}-fg)` }"
          >{{ c }}</span>
        </div>
      </section>

      <section>
        <h2>WaveBackground</h2>
        <div class="dev__wave-box">
          <WaveBackground />
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
.dev {
  display: grid;
  gap: 32px;
  padding: 24px var(--gutter) calc(48px + var(--safe-bottom));
}

.dev h2 { margin-bottom: 12px; font-size: var(--fs-heading); }

.dev__row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}

.dev__palette {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(104px, 1fr));
  gap: var(--product-gap);
}

.dev__chip {
  display: grid;
  place-items: center;
  min-height: var(--product-min-h);
  border-radius: var(--radius);
  font-size: var(--fs-product);
  font-weight: 700;
}

.dev__wave-box {
  position: relative;
  height: 240px;
  overflow: hidden;
  border-radius: var(--radius-card);
  transform: translateZ(0); /* position: fixed の子をこの箱に閉じ込める */
}
</style>
