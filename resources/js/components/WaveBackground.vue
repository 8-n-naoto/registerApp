<script setup lang="ts">
// S00 / S01 / A01 の波（08 §3.1）。青い面（ui.css の .home）の中に置く。背景のグラデーションは .home 側が持つ
</script>

<template>
  <svg
    class="home__wave"
    viewBox="0 0 1200 300"
    preserveAspectRatio="none"
    aria-hidden="true"
  >
    <path
      class="w1"
      d="M0 120 C200 60 400 180 600 120 S1000 60 1200 110 V300 H0Z"
    />
    <path
      class="w2"
      d="M0 170 C250 120 450 230 700 170 S1050 120 1200 165 V300 H0Z"
    />
    <path
      class="w3"
      d="M0 220 C300 180 500 270 800 220 S1100 190 1200 215 V300 H0Z"
    />
  </svg>
</template>
