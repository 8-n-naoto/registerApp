import type { ProductColor } from '@/types/api'

/** 商品ボタンの色（05 §5.4）。並びと表示名はサーバーの App\Enums\ProductColor と同じ */
export const PRODUCT_COLORS: readonly { key: ProductColor; label: string }[] = [
  { key: 'gray', label: 'グレー' },
  { key: 'red', label: '赤' },
  { key: 'orange', label: 'オレンジ' },
  { key: 'yellow', label: '黄' },
  { key: 'green', label: '緑' },
  { key: 'teal', label: '青緑' },
  { key: 'blue', label: '青' },
  { key: 'indigo', label: '藍' },
  { key: 'purple', label: '紫' },
  { key: 'pink', label: 'ピンク' },
]

/** CSV 一括登録のひな形（06 §7.7 の見出しと例 2 行）。Excel で開けるよう UTF-8 の BOM を付ける */
export const IMPORT_TEMPLATE = '﻿カテゴリ,商品名,価格,色,在庫管理,在庫数,販売中,商品コード,メモ\r\n'
  + 'ドリンク,コーヒー,450,オレンジ,OFF,0,ON,,ホット\r\n'
  + 'フード,ケーキ,500,ピンク,ON,10,ON,CAKE-01,\r\n'
