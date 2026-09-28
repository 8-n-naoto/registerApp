import pluginVue from 'eslint-plugin-vue'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'

export default defineConfigWithVueTs(
  {
    ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/cache/**', '_archive/**'],
  },
  pluginVue.configs['flat/recommended'],
  vueTsConfigs.recommended,
  {
    files: ['resources/js/**/*.{ts,vue}'],
    rules: {
      'vue/no-v-html': 'error',
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/ban-ts-comment': ['error', { 'ts-ignore': true, 'ts-expect-error': 'allow-with-description' }],
      'no-console': ['warn', { allow: ['warn', 'error'] }],
      // URL の直書き禁止（/api・/regi で始まる文字列）。api/client.ts だけで組み立てる
      'no-restricted-syntax': [
        'error',
        { selector: "Literal[value=/^\\/(api|regi|sanctum)(\\/|$)/]", message: 'URL を直書きしない。api/client.ts を使う' },
        { selector: "TemplateElement[value.raw=/^\\/(api|regi|sanctum)(\\/|$)/]", message: 'URL を直書きしない。api/client.ts を使う' },
      ],
    },
  },
  {
    files: ['resources/js/api/client.ts'],
    rules: { 'no-restricted-syntax': 'off' },
  },
)
