/** 画面の文言（08 §11）。画面に日本語を直書きしない */
export const ja = {
  app: {
    title: 'レジアプリ',
  },
  common: {
    home: 'ホーム',
    back: '戻る',
    loading: '読み込み中',
  },
  home: {
    card: {
      register: 'レジ操作',
      products: '商品管理',
      sales: '売上管理',
      settings: '設定',
    },
    btn: {
      checkout: 'お会計処理',
      productEdit: '商品登録',
      productImport: 'CSV 一括登録',
      daily: '売上確認',
      closing: 'レジ締め',
      summary: '期間集計',
      staff: 'スタッフ管理',
      store: '店舗設定',
      logs: '操作ログ',
    },
  },
  pwa: {
    newVersion: '新しいバージョンがあります',
    update: '更新',
  },
  notFound: {
    title: 'ページが見つかりません',
    toHome: 'ホームへ戻る',
  },
} as const
