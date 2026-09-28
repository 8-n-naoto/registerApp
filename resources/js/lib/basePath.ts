/** サーバーが埋めた <meta name="app-base">（本番 '/regi'、ローカル ''）。末尾の / は外す */
function readBasePath(): string {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="app-base"]')
  return (meta?.content ?? '').replace(/\/+$/, '')
}

export const basePath: string = readBasePath()
