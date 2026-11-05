#!/bin/bash
# お会計補助アプリのバックアップ。これ 1 つのファイルで完全に復元できる束（DB・.env・アプリ一式）を作る。
#
#   regi-backup.sh setup              最初に 1 回。保存先を作り、Cron に登録する行を表示する
#   regi-backup.sh probe              サーバーの環境を確認する（何も変更しない）
#   regi-backup.sh daily              Cron 用。成功時は何も出さず、失敗時だけ出力する（通知メールになる）
#   regi-backup.sh manual             今すぐ取る
#   regi-backup.sh pre-deploy         更新の直前に取る
#   regi-backup.sh status             最新のバックアップと保存状況を表示する（古い・無いときは終了コード 3）
#   regi-backup.sh verify [ファイル]  バックアップが復元に使えるか確かめる（既定は最新）
#
# 手順は docs/11 §4。設計の理由は docs/agents/decisions.md の D-018。

set -euo pipefail
# shellcheck source=regi-common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/regi-common.sh"

WORK=""
RUN_TAG=""
RUN_DONE=0

on_exit() {
    local status=$?
    [ -z "$WORK" ] || rm -rf "$WORK"
    if [ -n "$RUN_TAG" ] && [ "$RUN_DONE" != 1 ]; then
        log_line "NG $RUN_TAG exit=$status"
        printf 'バックアップは完了していません（%s、終了コード %s）。前回までのバックアップは残っています\n' "$RUN_TAG" "$status" >&2
    fi
    exit "$status"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

new_work_dir() {
    mkdir -p "$BACKUP_ROOT"
    chmod 700 "$BACKUP_ROOT"
    WORK="$(mktemp -d "$BACKUP_ROOT/.work-XXXXXX")"
}

remove_stale_work() {
    # kill などで残った作業フォルダには .env の写しが入っている。ロックを持っている間に片づける
    find "$BACKUP_ROOT" -maxdepth 1 -type d -name '.work-*' ! -path "$WORK" -exec rm -rf {} + 2>/dev/null || true
}

check_free_space() {
    local db_kb free_kb need_kb
    db_kb="$(du -k "$DB_PATH" | cut -f1)"
    free_kb="$(df -Pk "$BACKUP_ROOT" | awk 'NR==2 {print $4}')"
    # 複製・未圧縮の tar・圧縮後が同時に存在する瞬間がある
    need_kb=$((db_kb * 3 + 300000))
    [ "$free_kb" -ge "$need_kb" ] || die "保存先の空きが足りません（必要 ${need_kb}KB、空き ${free_kb}KB）"
}

pack_app() {
    # ログ・キャッシュ・セッション等は復元に不要で、日によって変わるので除く。
    # 空のフォルダと .gitignore は残すよう、中身のファイルだけを指定する。
    # bootstrap/cache の設定キャッシュは絶対パスと APP_KEY を含むので、復元先で作り直す。
    local status=0
    tar cf "$1" -C "$APP_DIR" \
        --exclude=./.env \
        --exclude=./node_modules \
        --exclude=./docs \
        --exclude=./regi-build.tar.gz \
        --exclude=./public/hot \
        --exclude='./database/*.sqlite' \
        --exclude='./database/*.sqlite-wal' \
        --exclude='./database/*.sqlite-shm' \
        --exclude='./bootstrap/cache/*.php' \
        --exclude='./storage/logs/*.log' \
        --exclude='./storage/framework/down' \
        --exclude='./storage/framework/maintenance.php' \
        --exclude='./storage/framework/cache/data' \
        --exclude='./storage/framework/sessions/[!.]*' \
        --exclude='./storage/framework/views/[!.]*' \
        --exclude='./storage/app/private/backups' \
        . || status=$?
    # 終了コード 1 は「読んでいる間にファイルが変わった」だけ。Cron 通知で気づけるよう警告にとどめる
    [ "$status" = 0 ] || [ "$status" = 1 ] || die "アプリ一式の固めに失敗しました"
    [ "$status" = 0 ] || warn "アプリ一式の読み取り中に変更されたファイルがあります"
}

write_manifest() {
    local out="$1" tag="$2" stamp="$3" snapshot="$4" link rel
    link="$(public_link)"
    rel="${APP_DIR#"$HOME"/}"
    {
        echo "format=1"
        echo "tag=$tag"
        echo "created_at=$stamp"
        echo "snapshot_method=$SNAPSHOT_METHOD"
        echo "commit=$(git -C "$APP_DIR" rev-parse HEAD 2>/dev/null || echo unknown)"
        echo "dirty=$(git -C "$APP_DIR" status --porcelain 2>/dev/null | wc -l)"
        echo "last_migration=$(db_migrations "$snapshot" | tail -n1)"
        echo "php=$("$PHP_BIN" -r 'echo PHP_VERSION;')"
        echo "sqlite=$(sqlite_lib_version)"
        echo "app_dir_rel=$rel"
        echo "db_rel=${DB_PATH#"$APP_DIR"/}"
        echo "db_mode=$(stat -c %a "$DB_PATH")"
        echo "public_link_rel=${link#"$HOME"/}"
        echo "public_link_target=$(readlink "$link" 2>/dev/null || true)"
        echo "app_key_fp=$(app_key_fingerprint "$APP_DIR/.env")"
        db_row_counts "$snapshot"
    } >"$out"
}

# docs は束に入らないので、PC とサーバーの両方を失っても束だけで手順が分かるようにする
write_restore_note() {
    cat >"$1" <<'NOTE'
お会計補助アプリの復元（詳しい手順は docs/11 §4）

中身
  data/database.sqlite  整合した DB
  data/env              .env（APP_KEY を含む。取り扱い注意）
  app.tar               アプリ一式（コード・.git・vendor・ビルド済みの画面）
  tools/                復元の道具
  MANIFEST.txt          作成日時・コミット・行数の記録

新しいサーバーへ戻す（束をホームに置いて実行）
  tar xzf <束> tools
  bash tools/regi-restore.sh full <束>

戻した後
  bash ~/regi-backups/bin/regi-backup.sh setup
  表示された行をサーバーパネルの Cron に登録する（束には入っていない）
NOTE
}

prune_dir() {
    local dir="$1" keep="$2" count=0 name
    [ -d "$dir" ] || return 0
    # 名前に時刻が入っているので、名前の降順が新しい順。決まった形の名前だけを消す
    for name in $(ls -1 "$dir" | grep -E "$BUNDLE_RE" | sort -r); do
        count=$((count + 1))
        [ "$count" -le "$keep" ] || rm -f "$dir/$name" "$dir/$name.sha256"
    done
}

link_bundle() { # ハードリンクなので容量は増えない
    mkdir -p "$3"
    ln -f "$1/$2" "$3/$2"
    ln -f "$1/$2.sha256" "$3/$2.sha256"
}

rotate() {
    local dir="$1" name="$2" tag="$3"
    if [ "$tag" = daily ]; then
        mkdir -p "$BACKUP_ROOT/weekly" "$BACKUP_ROOT/monthly"
        # 毎日同じ時刻に動くので、6 日より新しいものが無ければ週の分として残す
        if [ -z "$(find "$BACKUP_ROOT/weekly" -maxdepth 1 -name 'regi-*.tar.gz' -mtime -6 | head -n1)" ]; then
            link_bundle "$dir" "$name" "$BACKUP_ROOT/weekly"
        fi
        if [ -z "$(find "$BACKUP_ROOT/monthly" -maxdepth 1 -name "regi-$(date +%Y%m)*.tar.gz" | head -n1)" ]; then
            link_bundle "$dir" "$name" "$BACKUP_ROOT/monthly"
        fi
        prune_dir "$BACKUP_ROOT/daily" "$KEEP_DAILY"
        prune_dir "$BACKUP_ROOT/weekly" "$KEEP_WEEKLY"
        prune_dir "$BACKUP_ROOT/monthly" "$KEEP_MONTHLY"
    else
        prune_dir "$dir" "$KEEP_OTHER"
    fi
}

publish_latest() {
    local dir="$1" name="$2" hash size stamp
    hash="$(cut -d' ' -f1 "$dir/$name.sha256")"
    size="$(stat -c %s "$dir/$name")"
    stamp="$3"
    ln -f "$dir/$name" "$LATEST.new"
    mv -f "$LATEST.new" "$LATEST"
    ln -f "$dir/$name.sha256" "$LATEST.sha256.new"
    mv -f "$LATEST.sha256.new" "$LATEST.sha256"
    # 取得マクロが読むので、1 行 1 項目の決まった形にする
    {
        echo "name=$name"
        echo "size=$size"
        echo "sha256=$hash"
        echo "created_at=$stamp"
    } >"$LATEST_INFO.new"
    mv -f "$LATEST_INFO.new" "$LATEST_INFO"
}

cmd_run() {
    local tag="$1" started stamp ts dir name size nested="${REGI_LOCK_HELD:-}"
    case "$tag" in daily) QUIET=1 ;; esac
    RUN_TAG="$tag"
    started="$(date +%s)"
    resolve_app_dir
    [ -n "$APP_DIR" ] && [ -f "$APP_DIR/artisan" ] || die "アプリのフォルダが見つかりません（REGI_APP_DIR で指定できます）"
    [ -f "$APP_DIR/.env" ] || die ".env がありません: $APP_DIR/.env"
    resolve_db_path
    [ -f "$DB_PATH" ] || die "DB がありません: $DB_PATH"
    require_php
    acquire_lock
    new_work_dir
    # 復元の中から呼ばれたときは、親の作業フォルダが動いているので触らない
    [ -n "$nested" ] || remove_stale_work
    check_free_space
    install_tools ${nested:+keep-app-dir}
    # 共用サーバーで他の処理の邪魔をしない
    renice -n 10 $$ >/dev/null 2>&1 || true

    ts="$(date +%Y%m%d-%H%M%S)"
    stamp="$(now)"
    mkdir -p "$WORK/data" "$WORK/tools"

    say "DB を複製しています…"
    snapshot_db "$DB_PATH" "$WORK/data/database.sqlite"
    say "DB を検査しています…"
    db_healthy "$WORK/data/database.sqlite" || die "複製した DB の整合性検査に失敗しました。本番の DB が壊れている可能性があります"

    cp -p "$APP_DIR/.env" "$WORK/data/env"
    # パネルの Cron が見える環境なら控えておく（無くても復元には困らない）
    crontab -l >"$WORK/data/crontab.txt" 2>/dev/null || true
    # 新しいサーバーでは bin の写しも無いので、束の中にも復元の道具を入れる
    cp -p "$SCRIPT_DIR/regi-common.sh" "$SCRIPT_DIR/regi-backup.sh" "$SCRIPT_DIR/regi-restore.sh" "$WORK/tools/"

    say "アプリ一式を固めています…"
    pack_app "$WORK/app.tar"
    write_manifest "$WORK/MANIFEST.txt" "$tag" "$stamp" "$WORK/data/database.sqlite"
    write_restore_note "$WORK/RESTORE.txt"
    (cd "$WORK" && sha256sum MANIFEST.txt RESTORE.txt app.tar data/database.sqlite data/env tools/*.sh >CHECKSUMS)

    say "圧縮しています…"
    name="regi-$ts-$tag.tar.gz"
    tar czf "$WORK/$name" -C "$WORK" MANIFEST.txt RESTORE.txt CHECKSUMS app.tar data tools
    (cd "$WORK" && sha256sum "$name" >"$name.sha256")

    say "できたファイルを確かめています…"
    mkdir "$WORK/verify"
    verify_bundle "$WORK/$name" "$WORK/verify"

    # 検査に通ったものだけを保存先へ移す。途中で止まっても半端なファイルは見えない
    dir="$BACKUP_ROOT/$tag"
    mkdir -p "$dir"
    mv -f "$WORK/$name" "$dir/$name"
    mv -f "$WORK/$name.sha256" "$dir/$name.sha256"
    rotate "$dir" "$name" "$tag"
    # 復元の直前の退避は「最新」にしない。取得すべきなのは普段のバックアップ
    [ "$tag" = pre-restore ] || publish_latest "$dir" "$name" "$stamp"

    RUN_DONE=1
    size="$(stat -c %s "$dir/$name")"
    log_line "OK $tag $name ${size}B $(($(date +%s) - started))s"
    say "完了: $dir/$name（$((size / 1024 / 1024)) MB、$(($(date +%s) - started)) 秒）"
}

cmd_verify() {
    local bundle="${1:-$LATEST}"
    require_php
    # 実行中に daily が始まると、古い作業フォルダとして消されてしまう
    acquire_lock
    new_work_dir
    verify_bundle "$bundle" "$WORK"
    echo "問題ありません: $bundle"
    echo "  作成: $(manifest_value "$WORK/MANIFEST.txt" created_at)（$(manifest_value "$WORK/MANIFEST.txt" tag)）"
    echo "  コミット: $(manifest_value "$WORK/MANIFEST.txt" commit | cut -c1-7)、最後のマイグレーション: $(manifest_value "$WORK/MANIFEST.txt" last_migration)"
    db_store_summary "$WORK/data/database.sqlite"
}

cmd_status() {
    local dir count size stale
    if [ ! -f "$LATEST_INFO" ]; then
        echo "バックアップはまだありません（$BACKUP_ROOT）"
        return 3
    fi
    echo "最新: $(manifest_value "$LATEST_INFO" name)（$(manifest_value "$LATEST_INFO" created_at)、$(($(manifest_value "$LATEST_INFO" size) / 1024 / 1024)) MB）"
    echo "SHA-256: $(manifest_value "$LATEST_INFO" sha256)"
    stale="$(find "$LATEST_INFO" -mmin +1560 | head -n1)"
    if [ -n "$stale" ]; then
        echo "警告: 最新のバックアップが 26 時間以上前です。Cron が動いていない可能性があります"
    fi
    for dir in daily weekly monthly manual pre-deploy pre-restore; do
        [ -d "$BACKUP_ROOT/$dir" ] || continue
        count="$(ls -1 "$BACKUP_ROOT/$dir" | grep -cE "$BUNDLE_RE" || true)"
        printf '  %-12s %s 世代\n' "$dir" "$count"
    done
    size="$(du -sh "$BACKUP_ROOT" | cut -f1)"
    echo "使用容量: $size（空き $(df -Ph "$BACKUP_ROOT" | awk 'NR==2 {print $4}')）"
    echo "直近の実行:"
    tail -n 5 "$LOG_FILE" | sed 's/^/  /'
    # 取得マクロがこれを見て警告する
    [ -z "$stale" ] || return 3
}

probe_line() { printf '  %-22s %s\n' "$1" "$2"; }

cmd_probe() {
    local version tool ng=0 link
    resolve_app_dir
    echo "環境の確認（何も変更しません）"
    probe_line "bash" "$BASH_VERSION"
    if [ -n "$APP_DIR" ] && [ -f "$APP_DIR/artisan" ]; then
        probe_line "アプリ" "$APP_DIR"
        resolve_db_path
        if [ -f "$DB_PATH" ]; then probe_line "DB" "$DB_PATH（$(du -h "$DB_PATH" | cut -f1)）"; else probe_line "DB" "見つかりません"; ng=1; fi
        case "$DB_PATH" in
        "$APP_DIR"/*) ;;
        *)
            probe_line "DB の場所" "アプリの外（full の復元に対応していません）"
            ng=1
            ;;
        esac
        if [ -f "$APP_DIR/.env" ]; then probe_line ".env" "あり（権限 $(stat -c %a "$APP_DIR/.env")）"; else probe_line ".env" "見つかりません"; ng=1; fi
        link="$(public_link)"
        probe_line "公開リンク" "$link → $(readlink "$link" 2>/dev/null || echo '見つかりません')"
    else
        probe_line "アプリ" "見つかりません（REGI_APP_DIR で指定できます）"
        ng=1
    fi
    if PHP_BIN="$(find_php)"; then
        version="$(sqlite_lib_version)"
        probe_line "PHP" "$PHP_BIN（$("$PHP_BIN" -r 'echo PHP_VERSION;')）"
        probe_line "PHP の SQLite" "$version"
        if version_ge "$version" 3.27.0; then
            probe_line "複製の方式" "VACUUM INTO（書き込みを止めない）"
        elif command -v sqlite3 >/dev/null 2>&1; then
            probe_line "複製の方式" "sqlite3 .backup（PHP の SQLite が古いため）"
        else
            probe_line "複製の方式" "使えません"
            ng=1
        fi
    else
        probe_line "PHP" "PDO SQLite が使える 8.2 以上が見つかりません"
        ng=1
    fi
    if command -v sqlite3 >/dev/null 2>&1; then probe_line "sqlite3" "$(sqlite3 -version | cut -d' ' -f1)"; else probe_line "sqlite3" "なし"; fi
    for tool in flock sha256sum tar gzip git; do
        if command -v "$tool" >/dev/null 2>&1; then probe_line "$tool" "あり"; else
            probe_line "$tool" "なし"
            [ "$tool" = git ] || ng=1
        fi
    done
    if command -v openssl >/dev/null 2>&1; then
        case "$(openssl enc -help 2>&1)" in
        *pbkdf2*) probe_line "openssl" "$(openssl version | cut -d' ' -f1,2)（pbkdf2 可）" ;;
        *) probe_line "openssl" "$(openssl version | cut -d' ' -f1,2)（pbkdf2 不可）" ;;
        esac
    else
        probe_line "openssl" "なし"
    fi
    if command -v gpg >/dev/null 2>&1; then probe_line "gpg" "$(gpg --version | head -n1)"; else probe_line "gpg" "なし"; fi
    probe_line "保存先" "$BACKUP_ROOT（空き $(df -Ph "$(dirname "$BACKUP_ROOT")" | awk 'NR==2 {print $4}')）"
    if [ "$ng" = 0 ]; then echo "判定: 問題ありません"; else
        echo "判定: 足りないものがあります"
        return 1
    fi
}

cmd_setup() {
    resolve_app_dir
    [ -n "$APP_DIR" ] && [ -f "$APP_DIR/artisan" ] || die "アプリのフォルダが見つかりません（REGI_APP_DIR で指定できます）"
    mkdir -p "$BACKUP_ROOT"
    chmod 700 "$BACKUP_ROOT"
    install_tools
    cmd_probe
    echo
    echo "サーバーパネルの Cron に登録する行:"
    echo "  分 30 / 時 4 / 日 * / 月 * / 曜日 *"
    # bin の写しではなく本体を指す。git pull で更新された内容がそのまま使われ、実行のたびに bin も最新になる
    echo "  コマンド: /bin/bash $SCRIPT_DIR/regi-backup.sh daily"
}

usage() { sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

command_name="${1:-}"
case "$command_name" in
daily | manual | pre-deploy | pre-restore) cmd_run "$command_name" ;;
verify) cmd_verify "${2:-}" ;;
status) cmd_status ;;
probe) cmd_probe ;;
setup) cmd_setup ;;
*)
    usage
    exit 2
    ;;
esac
