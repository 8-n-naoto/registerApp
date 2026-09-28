<script setup lang="ts">
// S00 / S01 / A01 の背景（08 §3.1）。グラデーションの上に 3 層の波を重ねる。画像ファイルは持たない
</script>

<template>
  <div
    class="wave-bg"
    aria-hidden="true"
  >
    <svg
      class="wave-bg__waves"
      viewBox="0 0 1440 480"
      preserveAspectRatio="none"
    >
      <path
        fill="var(--c-wave-1)"
        d="M0,160 C240,80 480,80 720,150 C960,220 1200,230 1440,150 L1440,480 L0,480 Z"
      />
      <path
        fill="var(--c-wave-2)"
        d="M0,260 C220,200 460,190 720,250 C980,310 1220,320 1440,250 L1440,480 L0,480 Z"
      />
      <path
        fill="var(--c-wave-3)"
        d="M0,360 C260,310 500,300 740,350 C980,400 1220,410 1440,350 L1440,480 L0,480 Z"
      />
    </svg>
  </div>
</template>

<style scoped>
.wave-bg {
  position: fixed;
  inset: 0;
  z-index: -1;
  background: linear-gradient(to bottom, var(--c-bg-top), var(--c-bg-bottom));
  pointer-events: none;
}

.wave-bg__waves {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 100%;
  height: 55%;
}
</style>
