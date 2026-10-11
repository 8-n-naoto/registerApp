// printer-check.html の処理（CSP の script-src 'self' のため外部ファイル。SEC-007）
(function () {
  'use strict'
  var $ = function (id) { return document.getElementById(id) }
  var HOST_RE = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i

  // & < > だけ。実機は &quot; や &#10; を戻さず文字のまま印字するため、改行は生のまま
  function esc(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  }

  function text(line, cp, big) {
    var w = big ? '2' : '1'
    return '<text codepage="' + cp + '" international="japan" width="' + w + '" height="' + w + '" emphasis="false">' + esc(line + '\n') + '</text>'
  }

  function testRequest(cp) {
    var now = new Date()
    return '<initialization/>' +
      '<alignment position="center"/>' +
      text('プリンターの確認', cp, true) +
      text(now.toLocaleString('ja-JP'), cp, false) +
      '<alignment position="left"/>' +
      text('漢字：会計 領収書 合計 お釣り 取消', cp, false) +
      text('かな：あいうえお アイウエオ ｱｲｳｴｵ', cp, false) +
      text('記号：¥1,234 （税込） ・ ×', cp, false) +
      text('123456789012345678901234567890123456789012345678', cp, false) +
      text('指定：' + cp, cp, false) +
      '<cutpaper feed="true" type="partial"/>'
  }

  // <Request> の中身は & < > だけをエスケープする（実機は &quot; を戻さず、属性が読まれなくなる）
  function escRequest(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  }

  function envelope(request) {
    return '<StarWebPrint xmlns="http://www.star-m.jp" xmlns:i="http://www.w3.org/2001/XMLSchema-instance"><Request>' + escRequest('<root>' + request + '</root>') + '</Request></StarWebPrint>'
  }

  function host() {
    var h = $('host').value.trim().toLowerCase()
    return HOST_RE.test(h) ? h : null
  }

  function show(ok, message) {
    var el = $('result')
    el.textContent = message
    el.className = 'result ' + (ok ? 'ok' : 'ng')
  }

  function send(request) {
    var h = host()
    if (!h) { show(false, 'IP アドレスの形が正しくありません'); return }
    var buttons = [$('print'), $('status')]
    buttons.forEach(function (b) { b.disabled = true })
    $('result').textContent = '送信中…'
    $('result').className = 'result'
    $('http').textContent = '—'
    $('error').textContent = '—'
    $('raw').textContent = '—'
    var controller = typeof AbortController === 'function' ? new AbortController() : null
    var timer = setTimeout(function () { if (controller) controller.abort() }, 10000)
    var started = performance.now()
    fetch('https://' + h + '/StarWebPRNT/SendMessage', {
      method: 'POST',
      headers: { 'Content-Type': 'text/xml; charset=UTF-8' },
      body: envelope(request),
      signal: controller ? controller.signal : undefined,
    }).then(function (res) {
      $('http').textContent = res.status + ' ' + res.statusText
      return res.text().then(function (body) {
        $('elapsed').textContent = Math.round(performance.now() - started) + ' ms'
        $('raw').textContent = body
        var ok = /success="true"/.test(body)
        show(ok, ok ? '応答を読めました（CORS 可）。紙と漢字を確認してください' : '応答は読めましたがプリンターがエラーを返しました')
      })
    }).catch(function (err) {
      $('elapsed').textContent = Math.round(performance.now() - started) + ' ms'
      $('error').textContent = (err && err.name ? err.name + '：' : '') + (err && err.message ? err.message : String(err))
      var aborted = err && err.name === 'AbortError'
      show(false, aborted
        ? '10 秒で応答がありません（電源・Wi-Fi・IP を確認）'
        : '応答を読めませんでした。紙が出た場合は CORS 不可、出ない場合は証明書・許可・IP を確認')
    }).then(function () {
      clearTimeout(timer)
      buttons.forEach(function (b) { b.disabled = false })
    })
  }

  $('origin').textContent = location.origin
  $('ua').textContent = navigator.userAgent
  $('host').addEventListener('input', function () {
    var h = host()
    $('open').setAttribute('href', h ? 'https://' + h + '/' : '#')
  })
  $('print').addEventListener('click', function () { send(testRequest($('codepage').value)) })
  // 空の要求：印刷せずに状態（status）だけ返る
  $('status').addEventListener('click', function () { send('') })
})()
