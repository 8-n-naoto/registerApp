#!/bin/bash
# バックアップからの復元。本番のデータを置き換えるので、バックアップと分けて別のスクリプトにしている。
#
#   regi-restore.sh db <ファイル>     DB だけを戻す（誤操作・DB の破損。アプリは正常なとき）
#   regi-restore.sh full <ファイル>   アプリ一式・.env・DB をすべて戻す（アプリの破損・新しいサーバー）
#
# どちらも内容を検査してから始め、現在の新旧を見せて yes の入力を待つ。
# 差し替える前の状態は ~/regi-backups/pre-restore/ に残すので、復元のやり直しもできる。
# 手順は docs/11 §4。

set -euo pipefail
# shellcheck source=regi-common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/regi-common.sh"

WORK=""
RAW_DIR=""     # 差し替え前の DB を退避した場所
OLD_APP_DIR="" # full で移した元のフォルダ
SWAPPED=0      # 1 の間は、失敗したら退避した DB へ自動で戻す
FULL_SWAPPED=0 # 1 の間は、失敗したら元のフォルダへ自動で戻す
WENT_DOWN=0    # 1 の間は、終了時にメンテナンスモードを解除する
STAGE_DIR=""   # full で組み立て中のフォルダ。入れ替えたら空にする
LINK_PATH=""   # full で張り替えた公開リンク。失敗したら元へ戻す
OLD_LINK_TARGET=""
ASSUME_YES=0

artisan() { (cd "$APP_DIR" && "$PHP_BIN" artisan "$@"); }

# 成功したときは黙り、失敗したときだけ原因を見せる
run_artisan() {
    local output
    output="$(artisan "$@" 2>&1)" || {
        printf 'artisan %s に失敗しました:
%s
' "$*" "$output" >&2
        return 1
    }
}

# on_exit からは || の左で呼ぶので set -e が効かない。失敗は戻り値で返す
undo_swap() {
    local suffix name failed=0
    name="$(basename "$DB_PATH")"
    warn "途中で失敗したので、元の DB に戻します"
    mkdir -p "$RAW_DIR/failed" || failed=1
    for suffix in "" -wal -shm; do
        if [ -e "$DB_PATH$suffix" ]; then mv -f "$DB_PATH$suffix" "$RAW_DIR/failed/" || failed=1; fi
        if [ -e "$RAW_DIR/$name$suffix" ]; then mv -f "$RAW_DIR/$name$suffix" "$DB_PATH$suffix" || failed=1; fi
    done
    return "$failed"
}

undo_link() {
    [ -n "$LINK_PATH" ] || return 0
    if [ -n "$OLD_LINK_TARGET" ]; then
        ln -sfn "$OLD_LINK_TARGET" "$LINK_PATH"
    else
        rm -f "$LINK_PATH"
    fi
}

undo_full() {
    warn "途中で失敗したので、元のフォルダに戻します"
    if [ -e "$APP_DIR" ]; then
        mv "$APP_DIR" "$OLD_APP_DIR.failed" || return 1
        warn "失敗した方は $OLD_APP_DIR.failed に残しました"
    fi
    mv "$OLD_APP_DIR" "$APP_DIR" || return 1
    undo_link
}

on_exit() {
    local status=$?
    # 巻き戻しの途中で止まると DB とフォルダが半端なまま残る。接続が切れても最後まで走らせる
    trap '' INT TERM HUP PIPE
    if [ "$SWAPPED" = 1 ]; then
        undo_swap || warn "元の DB に戻せませんでした。退避先: $RAW_DIR"
    fi
    if [ "$FULL_SWAPPED" = 1 ]; then
        undo_full || warn "元のフォルダに戻せませんでした。退避先: $OLD_APP_DIR"
    fi
    if [ "$WENT_DOWN" = 1 ]; then
        artisan up >/dev/null 2>&1 || warn "メンテナンスモードを解除できませんでした（$PHP_BIN artisan up）"
    fi
    [ -z "$STAGE_DIR" ] || rm -rf "$STAGE_DIR"
    [ -z "$WORK" ] || rm -rf "$WORK"
    [ "$status" = 0 ] || printf '復元は完了していません（終了コード %s）\n' "$status" >&2
    exit "$status"
}
trap on_exit EXIT
trap 'exit 130' INT TERM HUP

confirm() {
    [ "$ASSUME_YES" = 0 ] || return 0
    local answer
    printf '%s 実行するなら yes と入力: ' "$1"
    read -r answer
    [ "$answer" = yes ] || die "中止しました（何も変更していません）"
}

show_bundle() {
    echo "戻す内容:"
    echo "  作成: $(manifest_value "$WORK/MANIFEST.txt" created_at)（$(manifest_value "$WORK/MANIFEST.txt" tag)）"
    echo "  コミット: $(manifest_value "$WORK/MANIFEST.txt" commit | cut -c1-7)"
    db_store_summary "$WORK/data/database.sqlite"
}

# バックアップの DB に適用済みなのに今のコードに無いマイグレーションがあれば、データの方が新しい。
# そのまま戻すとコードが知らない列やテーブルで動くことになるので止める。
check_migrations() {
    local name
    while IFS= read -r name; do
        [ -f "$APP_DIR/database/migrations/$name.php" ] ||
            die "今のコードに無いマイグレーションが適用済みです（$name）。コードも戻す full を使ってください"
    done < <(db_migrations "$WORK/data/database.sqlite")
}

set_aside_current() {
    local suffix
    RAW_DIR="$BACKUP_ROOT/pre-restore/raw-$(date +%Y%m%d-%H%M%S)"
    mkdir -p "$RAW_DIR"
    # 本体を動かせないうちに印を立てると、失敗時の巻き戻しが動いている DB を片付けてしまう
    [ ! -e "$DB_PATH" ] || mv -f "$DB_PATH" "$RAW_DIR/"
    SWAPPED=1
    # -wal と -shm を残すと古い WAL が戻した DB に再生される。
    # 整合性検査は ok のままデータだけ変わるので、3 つとも必ず一緒に動かす。
    for suffix in -wal -shm; do
        [ ! -e "$DB_PATH$suffix" ] || mv -f "$DB_PATH$suffix" "$RAW_DIR/"
    done
}

place_db() {
    local target="$1" mode
    mode="$(manifest_value "$WORK/MANIFEST.txt" db_mode)"
    cp "$WORK/data/database.sqlite" "$target.restoring"
    chmod "${mode:-640}" "$target.restoring"
    mv -f "$target.restoring" "$target"
}

prune_raw() {
    local count=0 name
    for name in $(ls -1 "$BACKUP_ROOT/pre-restore" | grep -E '^raw-[0-9]{8}-[0-9]{6}$' | sort -r); do
        count=$((count + 1))
        [ "$count" -le "$KEEP_OTHER" ] || rm -rf "${BACKUP_ROOT:?}/pre-restore/$name"
    done
}

http_report() {
    local url code
    # 訓練先の .env の APP_URL は本番を指すので、確かめても訓練の結果にならない
    [ -z "${REGI_APP_DIR:-}" ] || return 0
    url="$(grep -m1 '^APP_URL=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'"'"'\r' || true)"
    if [ -n "$url" ] && command -v curl >/dev/null 2>&1; then
        code="$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$url/" || true)"
        echo "  画面の応答: HTTP ${code:-なし}"
        case "$code" in
        2* | 3*) ;;
        *) warn "画面が正常に応答していません。storage/logs/laravel.log を確認してください" ;;
        esac
    fi
}

restore_public_link() {
    local link_rel link link_target current
    # 別の場所へ戻す訓練で、本番の URL が訓練先のアプリを指さないようにする
    if [ -n "${REGI_APP_DIR:-}" ] && [ -z "${REGI_PUBLIC_LINK:-}" ]; then
        warn "REGI_APP_DIR を指定したので公開リンクには触っていません（変えるなら REGI_PUBLIC_LINK を指定）"
        return 0
    fi
    link_rel="$(manifest_value "$WORK/MANIFEST.txt" public_link_rel)"
    case "$link_rel" in /*) link="${REGI_PUBLIC_LINK:-$link_rel}" ;; *) link="${REGI_PUBLIC_LINK:-$HOME/$link_rel}" ;; esac
    link_target="$(manifest_value "$WORK/MANIFEST.txt" public_link_target)"
    if [ -z "$link_target" ]; then
        warn "公開リンクの記録がありません。docs/11 §1.1 のとおり作ってください"
    elif [ -L "$link" ] || [ ! -e "$link" ]; then
        if [ -d "$(dirname "$link")" ]; then
            current="$(readlink "$link" 2>/dev/null || true)"
            [ "$current" != "$APP_DIR/public" ] || return 0
            LINK_PATH="$link"
            OLD_LINK_TARGET="$current"
            # 記録のパスは元のサーバーのホームを含むことがあるので、戻した先から作り直す
            ln -sfn "$APP_DIR/public" "$link"
        else
            warn "公開フォルダがありません: $(dirname "$link")（ドメインの設定後に作ってください）"
        fi
    else
        warn "$link はリンクではないので触っていません。docs/11 §1.1 のとおり確認してください"
    fi
}

cmd_db() {
    local bundle="$1" current_fp bundle_fp
    resolve_app_dir
    [ -n "$APP_DIR" ] && [ -f "$APP_DIR/artisan" ] || die "アプリのフォルダが見つかりません。full を使ってください"
    resolve_db_path
    require_php
    acquire_lock
    WORK="$(mktemp -d "$BACKUP_ROOT/.work-XXXXXX")"
    echo "バックアップを検査しています…"
    verify_bundle "$bundle" "$WORK"
    check_migrations

    current_fp="$(app_key_fingerprint "$APP_DIR/.env")"
    bundle_fp="$(manifest_value "$WORK/MANIFEST.txt" app_key_fp)"
    [ "$current_fp" = "$bundle_fp" ] ||
        die "バックアップと今の .env で APP_KEY が違います。QR の再印刷などが壊れるので full を使ってください"

    show_bundle
    if [ -f "$DB_PATH" ]; then
        echo "今の DB:"
        db_store_summary "$DB_PATH" || warn "今の DB を読めません"
    fi
    echo "バックアップより後の会計は、今の DB から無くなります（退避はします）。"
    confirm "DB を置き換えます。"

    echo "メンテナンスモードにしています…"
    artisan down --retry=60 >/dev/null
    WENT_DOWN=1
    # 実行中のリクエストが終わるのを待つ
    sleep "${REGI_DRAIN_SECONDS:-3}"

    echo "今の状態を退避しています…"
    if [ -f "$DB_PATH" ]; then
        REGI_LOCK_HELD=1 /bin/bash "$SCRIPT_DIR/regi-backup.sh" pre-restore ||
            warn "退避のバックアップを作れませんでした（DB が壊れている場合に起きます）。元のファイルは raw として残します"
    fi
    set_aside_current

    echo "DB を置き換えています…"
    place_db "$DB_PATH"
    # 戻した DB がコードより古いときは、足りない分だけ適用する
    run_artisan migrate --force
    artisan cache:clear >/dev/null 2>&1 || true

    echo "確認しています…"
    app_post_check "$APP_DIR"
    SWAPPED=0
    artisan up >/dev/null
    WENT_DOWN=0
    http_report
    prune_raw
    log_line "RESTORE db $(basename "$bundle")"
    echo "完了しました。元の DB は $RAW_DIR にあります。"
}

cmd_full() {
    local bundle="$1" rel db_rel env_db ts commit
    case "$bundle" in /*) ;; *) bundle="$PWD/$bundle" ;; esac
    require_php
    mkdir -p "$BACKUP_ROOT"
    chmod 700 "$BACKUP_ROOT"
    acquire_lock
    WORK="$(mktemp -d "$BACKUP_ROOT/.work-XXXXXX")"
    echo "バックアップを検査しています…"
    verify_bundle "$bundle" "$WORK"

    # ホームの場所が変わっても（新しいサーバー）同じ相対位置へ戻す
    rel="$(manifest_value "$WORK/MANIFEST.txt" app_dir_rel)"
    case "$rel" in /*) APP_DIR="${REGI_APP_DIR:-$rel}" ;; *) APP_DIR="${REGI_APP_DIR:-$HOME/$rel}" ;; esac
    db_rel="$(manifest_value "$WORK/MANIFEST.txt" db_rel)"
    # アプリの外の DB はフォルダの入れ替えで退避されず、古い -wal も残る
    case "$db_rel" in /*) die "DB がアプリの外にある構成（DB_DATABASE）には full は対応していません" ;; esac
    DB_PATH="$APP_DIR/$db_rel"
    # 絶対パスの DB_DATABASE は、戻した先でも元の場所を指す。別の場所へ戻すと本番の DB に移行が走る
    env_db="$(grep -m1 '^DB_DATABASE=' "$WORK/data/env" | cut -d= -f2- | tr -d '"'"'"'\r' || true)"
    case "$env_db" in /*)
        [ "$env_db" = "$DB_PATH" ] || die "DB_DATABASE が絶対パスで、戻す先の DB と違います。.env から DB_DATABASE の行を外すか、元と同じ場所へ戻してください"
        ;;
    esac
    ts="$(date +%Y%m%d-%H%M%S)"

    show_bundle
    echo "戻す先: $APP_DIR"
    if [ -e "$APP_DIR" ]; then
        OLD_APP_DIR="$APP_DIR.before-restore-$ts"
        echo "今のフォルダは消さず $OLD_APP_DIR へ移します。"
    fi
    echo "バックアップより後の会計・コードの変更は、戻した先には入りません。"
    confirm "アプリ一式を置き換えます。"

    # 公開リンクは APP_DIR を指している。その場で展開すると移行前のアプリに要求が届くので、
    # 隣で組み立ててメンテナンスの印を付けてから入れ替える。
    echo "アプリ一式を組み立てています…"
    STAGE_DIR="$APP_DIR.restoring-$ts"
    # 公開ファイルは Web サーバーが読むので、権限を作成時のまま戻す（-p）。
    # 新しく作るフォルダも同じ理由で、このときだけ既定の 077 をゆるめる
    umask 022
    mkdir -p "$STAGE_DIR"
    tar xpf "$WORK/app.tar" -C "$STAGE_DIR"
    cp "$WORK/data/env" "$STAGE_DIR/.env"
    chmod 600 "$STAGE_DIR/.env"
    mkdir -p "$(dirname "$STAGE_DIR/$db_rel")"
    place_db "$STAGE_DIR/$db_rel"
    # バックアップに入れていないフォルダを作り直す
    mkdir -p "$STAGE_DIR/storage/framework/cache/data" "$STAGE_DIR/storage/framework/sessions" \
        "$STAGE_DIR/storage/framework/views" "$STAGE_DIR/storage/logs" "$STAGE_DIR/storage/app/private" \
        "$STAGE_DIR/bootstrap/cache"
    printf '{"retry":60,"status":503}' >"$STAGE_DIR/storage/framework/down"
    umask 077

    if [ -n "$OLD_APP_DIR" ]; then
        # 壊れていて止められないこともあるので、失敗しても進める
        if artisan down --retry=60 >/dev/null 2>&1; then
            WENT_DOWN=1
            sleep "${REGI_DRAIN_SECONDS:-3}"
        fi
        mv "$APP_DIR" "$OLD_APP_DIR"
        FULL_SWAPPED=1
    fi
    mv "$STAGE_DIR" "$APP_DIR"
    STAGE_DIR=""

    restore_public_link

    echo "設定のキャッシュを作り直しています…"
    run_artisan optimize:clear
    run_artisan migrate --force
    run_artisan config:cache
    run_artisan route:cache
    run_artisan view:cache

    echo "確認しています…"
    app_post_check "$APP_DIR"
    FULL_SWAPPED=0
    # ここからは戻した先の解除。on_exit に任せず、失敗したら画面で知らせる
    WENT_DOWN=0
    run_artisan up || warn "メンテナンスモードを解除できませんでした（$PHP_BIN artisan up）"
    http_report
    commit="$(manifest_value "$WORK/MANIFEST.txt" commit)"
    log_line "RESTORE full $(basename "$bundle") commit=${commit:0:7}"
    install_tools ${REGI_APP_DIR:+keep-app-dir}
    echo "完了しました。"
    if [ -n "$OLD_APP_DIR" ]; then
        echo "元のフォルダは $OLD_APP_DIR にあります。"
    else
        echo "新しいサーバーなら、続けて regi-backup.sh setup と Cron の登録を行ってください（docs/11 §4.2）。"
    fi
}

command_name="${1:-}"
bundle_arg="${2:-}"
[ "${3:-}" != "--yes" ] || ASSUME_YES=1
case "$command_name" in
db)
    [ -n "$bundle_arg" ] || die "ファイルを指定してください"
    cmd_db "$bundle_arg"
    ;;
full)
    [ -n "$bundle_arg" ] || die "ファイルを指定してください"
    cmd_full "$bundle_arg"
    ;;
*)
    sed -n '2,8p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 2
    ;;
esac
