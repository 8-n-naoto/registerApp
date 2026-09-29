/** 画面の文言（08 §11）。画面に日本語を直書きしない */
export const ja = {
  app: {
    title: 'レジアプリ',
  },
  common: {
    home: 'ホーム',
    back: '戻る',
    loading: '読み込み中',
    close: '閉じる',
    save: '保存',
  },
  role: {
    admin: '管理者',
    owner: 'オーナー',
    staff: 'スタッフ',
  },
  login: {
    loginId: 'ログイン ID',
    password: 'パスワード',
    showPassword: '表示',
    hidePassword: '隠す',
    remember: 'ログインを保持する',
    submit: 'ログイン',
    throttled: 'しばらく待ってから再度お試しください（あと {sec} 秒）',
  },
  menu: {
    open: '{name}（{role}）のメニュー',
    password: 'パスワード変更',
    device: '端末名の設定',
    logout: 'ログアウト',
  },
  account: {
    title: 'アカウント',
    passwordHeading: 'パスワード変更',
    currentPassword: '現在のパスワード',
    newPassword: '新しいパスワード（8〜72 文字）',
    confirmPassword: '新しいパスワード（確認）',
    changePassword: 'パスワードを変更する',
    passwordChanged: 'パスワードを変更しました',
    deviceHeading: '端末名',
    deviceHelp: 'この端末のブラウザに保存し、会計に記録します（例：レジ 1）。30 文字まで',
    deviceSaved: '端末名を保存しました',
    deviceUnavailable: 'このブラウザでは端末名を保存できません',
    installHeading: 'ホーム画面に追加',
    installSteps: '画面下（iPad は右上）の共有ボタンを押し、「ホーム画面に追加」を選ぶと、アプリのように開けます。',
  },
  admin: {
    storesTitle: '店舗一覧',
    storesPending: '店舗の一覧は準備中です',
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
    businessDate: '本日の営業日 {date}',
  },
  error: {
    network: '通信できません。会計は保存されていません',
    login: 'ログイン ID またはパスワードが違います',
    storeSuspended: 'この店舗は利用停止中です',
    accountDisabled: 'このアカウントは停止されています',
    unexpected: 'エラーが発生しました',
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

/** 文言の {name} を値で置き換える */
export function fmt(template: string, params: Record<string, string | number>): string {
  return template.replace(/\{(\w+)\}/g, (whole, key: string) => (key in params ? String(params[key]) : whole))
}
