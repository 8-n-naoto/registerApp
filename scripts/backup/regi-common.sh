# regi-backup.sh / regi-restore.sh の共通部分（source して使う。単独では実行しない）
#
# なぜ artisan ではなく素の bash か:
#   復元が必要になるのは、アプリが壊れて artisan が動かないときかもしれない。
#   Laravel に頼らず、PHP の PDO（無ければ sqlite3）と標準コマンドだけで動くようにする。
# 互換性: 共用サーバーの古い bash（4.2）でも動くよう、新しい構文や空配列の展開を使わない。

PATH="/usr/local/bin:/usr/bin:/bin:${PATH:-}"
export PATH
# バックアップには .env が入るので、作るものはすべて本人だけが読める権限にする
umask 077

[ -n "${HOME:-}" ] || HOME="$(getent passwd "$(id -u)" | cut -d: -f6)"
export HOME

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_ROOT="${REGI_BACKUP_DIR:-$HOME/regi-backups}"
LOCK_FILE="$BACKUP_ROOT/.lock"
LOG_FILE="$BACKUP_ROOT/backup.log"
LATEST="$BACKUP_ROOT/regi-latest.tar.gz"
LATEST_INFO="$BACKUP_ROOT/regi-latest.txt"
APP_DIR_FILE="$BACKUP_ROOT/app-dir"

KEEP_DAILY="${REGI_KEEP_DAILY:-14}"
KEEP_WEEKLY="${REGI_KEEP_WEEKLY:-8}"
KEEP_MONTHLY="${REGI_KEEP_MONTHLY:-12}"
KEEP_OTHER="${REGI_KEEP_OTHER:-10}"

BUNDLE_RE='^regi-[0-9]{8}-[0-9]{6}-[a-z-]+\.tar\.gz$'
QUIET=0

say() { [ "$QUIET" = 1 ] || printf '%s\n' "$*"; }
warn() { printf '警告: %s\n' "$*" >&2; }
die() {
    printf 'エラー: %s\n' "$*" >&2
    exit 1
}
now() { date '+%Y-%m-%d %H:%M:%S'; }
log_line() {
    [ -d "$BACKUP_ROOT" ] || return 0
    printf '%s %s\n' "$(now)" "$*" >>"$LOG_FILE"
    # 無限に増えないよう直近 500 行だけ残す
    if [ "$(wc -l <"$LOG_FILE")" -gt 600 ]; then
        tail -n 500 "$LOG_FILE" >"$LOG_FILE.tmp" && mv -f "$LOG_FILE.tmp" "$LOG_FILE"
    fi
}

resolve_app_dir() {
    if [ -n "${REGI_APP_DIR:-}" ]; then
        APP_DIR="$REGI_APP_DIR"
    elif [ -f "$SCRIPT_DIR/../../artisan" ]; then
        APP_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
    elif [ -f "$APP_DIR_FILE" ]; then
        APP_DIR="$(cat "$APP_DIR_FILE")"
    else
        APP_DIR=""
    fi
}

resolve_db_path() {
    # .env の値は DB_DATABASE だけを読み、画面にもログにも .env の他の行は出さない
    local value=""
    if [ -f "$APP_DIR/.env" ]; then
        value="$(grep -m1 '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'"'"'\r' || true)"
    fi
    case "$value" in
    "") DB_PATH="$APP_DIR/database/database.sqlite" ;;
    /*) DB_PATH="$value" ;;
    *) DB_PATH="$APP_DIR/$value" ;;
    esac
}

public_link() { printf '%s' "${REGI_PUBLIC_LINK:-$(dirname "$APP_DIR")/public_html/regi}"; }

find_php() {
    local candidate
    # 素の php が古いサーバーがあるので、8.4 を明示した実体を先に探す
    for candidate in "${REGI_PHP:-}" /usr/bin/php8.4 php8.4 php; do
        [ -n "$candidate" ] || continue
        if command -v "$candidate" >/dev/null 2>&1 &&
            "$candidate" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("pdo_sqlite") ? 0 : 1);' 2>/dev/null; then
            printf '%s' "$candidate"
            return 0
        fi
    done
    return 1
}

require_php() {
    PHP_BIN="$(find_php)" || die "PDO SQLite が使える PHP 8.2 以上が見つかりません（REGI_PHP で指定できます）"
}

# 読み出しは PHP に一本化する（sqlite3 CLI は古くて機能が足りないサーバーがある）。
# 接続を読み取り専用にしないのは、WAL の DB は読むだけでも -shm への書き込みが要るため。
db_php() {
    local code="$1"
    shift
    "$PHP_BIN" -d display_errors=stderr -r '
        $p = new PDO("sqlite:".$argv[1]);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->exec("PRAGMA busy_timeout=30000");
        '"$code" -- "$@"
}

sqlite_lib_version() {
    "$PHP_BIN" -r 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();'
}

version_ge() { # version_ge 3.40.1 3.27.0
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -t. -k1,1n -k2,2n -k3,3n | head -n1)" = "$2" ]
}

# 稼働中の DB の一貫した複製を 1 ファイルに作る。cp は WAL の中身を取りこぼすので使わない。
# VACUUM INTO は読み取りとして動き、会計の書き込みを止めない（docs/10 に計測結果）。
snapshot_db() {
    local src="$1" dst="$2"
    if version_ge "$(sqlite_lib_version)" 3.27.0; then
        SNAPSHOT_METHOD="vacuum-into"
        db_php '$s = $p->prepare("VACUUM INTO ?"); $s->execute([$argv[2]]);' "$src" "$dst" && return 0
        rm -f "$dst"
    fi
    command -v sqlite3 >/dev/null 2>&1 || die "DB の複製に失敗しました（sqlite3 コマンドもありません）"
    SNAPSHOT_METHOD="sqlite3-backup"
    # 古い sqlite3 は引数に複数の命令を渡せないので標準入力で渡す
    sqlite3 -bail "$src" <<EOF
.timeout 30000
.backup '$dst'
EOF
}

# 1 行目に ok が出れば健全。件数が取れない DB を「健全」と言わないよう stores の存在も見る
db_healthy() {
    local result
    result="$(db_php '
        $r = $p->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN);
        $p->query("select count(*) from stores")->fetchColumn();
        echo implode(" ", array_slice($r, 0, 3));' "$1")" || return 1
    [ "$result" = "ok" ] || {
        printf '%s\n' "$result" >&2
        return 1
    }
}

db_row_counts() { # rows.<テーブル>=件数
    db_php '
        $t = $p->query("select name from sqlite_master where type='\''table'\'' and name not like '\''sqlite_%'\'' order by name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($t as $n) { echo "rows.$n=", $p->query("select count(*) from \"$n\"")->fetchColumn(), "\n"; }' "$1"
}

db_migrations() { # 適用済みのマイグレーション名を 1 行ずつ
    db_php 'foreach ($p->query("select migration from migrations order by migration")->fetchAll(PDO::FETCH_COLUMN) as $m) { echo $m, "\n"; }' "$1"
}

db_store_summary() { # 店舗ごとの会計件数と最後の会計（人が新旧を見比べるため）
    db_php '
        $q = "select s.id, s.name,
            (select count(*) from sales x where x.store_id = s.id) c,
            (select max(sold_at) from sales x where x.store_id = s.id) m
            from stores s order by s.id";
        foreach ($p->query($q)->fetchAll(PDO::FETCH_NUM) as $r) {
            printf("  店舗 %d %s: 会計 %d 件、最後の会計 %s\n", $r[0], $r[1], $r[2], $r[3] ?? "なし");
        }' "$1"
}

app_key_fingerprint() {
    # 鍵そのものは出さず、同じ鍵かどうかだけ比べられるようにする
    grep -m1 '^APP_KEY=' "$1" | tr -d '\r' | sha256sum | cut -c1-12
}

manifest_value() { { grep -m1 "^$2=" "$1" || true; } | cut -d= -f2-; }

acquire_lock() {
    # 復元の中から pre-restore のバックアップを呼ぶときは親がロックを持っている
    [ -z "${REGI_LOCK_HELD:-}" ] || return 0
    mkdir -p "$BACKUP_ROOT"
    chmod 700 "$BACKUP_ROOT"
    exec 9>"$LOCK_FILE"
    flock -n 9 || die "別のバックアップまたは復元が実行中です。終わってからやり直してください"
    REGI_LOCK_HELD=1
    export REGI_LOCK_HELD
}

install_tools() {
    # アプリが壊れても復元できるよう、スクリプトの写しをアプリの外にも置く
    local dest="$BACKUP_ROOT/bin"
    mkdir -p "$dest"
    [ "$SCRIPT_DIR" = "$dest" ] || cp -f "$SCRIPT_DIR/regi-common.sh" "$SCRIPT_DIR/regi-backup.sh" "$SCRIPT_DIR/regi-restore.sh" "$dest/"
    # 別の場所へ戻す訓練で、Cron が見る普段の場所の記録を書き換えない
    if [ "${1:-}" != "keep-app-dir" ]; then
        printf '%s\n' "$APP_DIR" >"$APP_DIR_FILE"
    fi
}

# 中身を確かめてから使う。壊れたファイルで本番を上書きしないための関門で、
# 外側の SHA-256（あれば）→ gzip → 中の CHECKSUMS → DB の健全性 → 件数の照合 → アプリ一式の順に見る。
# 展開先 $2 に中身を残す（復元はそれをそのまま使う）。
verify_bundle() {
    local bundle="$1" dir="$2" sidecar="$1.sha256" expected actual line key value rows=0
    [ -f "$bundle" ] || die "ファイルがありません: $bundle"
    if [ -f "$sidecar" ]; then
        expected="$(cut -d' ' -f1 "$sidecar")"
        actual="$(sha256sum "$bundle" | cut -d' ' -f1)"
        [ "$expected" = "$actual" ] || die "SHA-256 が一致しません（壊れているか途中までのファイルです）: $bundle"
    fi
    gzip -t "$bundle" 2>/dev/null || die "圧縮ファイルとして壊れています: $bundle"
    tar xzf "$bundle" -C "$dir" || die "展開できません: $bundle"
    for key in MANIFEST.txt CHECKSUMS app.tar data/database.sqlite data/env; do
        [ -f "$dir/$key" ] || die "バックアップに $key がありません: $bundle"
    done
    (cd "$dir" && sha256sum --quiet -c CHECKSUMS) >/dev/null 2>&1 || die "中身の SHA-256 が一致しません: $bundle"
    db_healthy "$dir/data/database.sqlite" || die "DB の整合性検査に失敗しました: $bundle"
    while IFS= read -r line; do
        key="${line%%=*}"
        value="$(manifest_value "$dir/MANIFEST.txt" "$key")"
        [ "$line" = "$key=$value" ] || die "件数が記録と一致しません（$key）: $bundle"
        rows=$((rows + 1))
    done < <(db_row_counts "$dir/data/database.sqlite")
    # 0 件のまま通ると何も比べていないのに合格になる
    [ "$rows" -gt 0 ] && [ "$rows" = "$(grep -c '^rows\.' "$dir/MANIFEST.txt")" ] ||
        die "テーブルの数が記録と一致しません: $bundle"
    grep -q '^APP_KEY=.' "$dir/data/env" || die ".env に APP_KEY がありません: $bundle"
    tar tf "$dir/app.tar" >"$dir/app.list"
    for key in ./artisan ./vendor/autoload.php ./public/build/manifest.json ./public/index.php; do
        grep -qxF "$key" "$dir/app.list" || die "アプリ一式に $key がありません: $bundle"
    done
}

# 復元のあと、実際にアプリとして起動できて暗号化データが読めるかを確かめる。
# APP_KEY が違っても DB は開けて画面も出るため、QR の復号まで見ないと気づけない。
app_post_check() {
    "$PHP_BIN" -d display_errors=stderr -r '
        require $argv[1]."/vendor/autoload.php";
        $app = require $argv[1]."/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = $app->make("db");
        echo "  アプリの起動: OK（店舗 ", $db->table("stores")->count(), " 件）\n";
        $tokens = $db->table("order_tables")->whereNotNull("token_encrypted")->pluck("token_encrypted");
        $bad = 0;
        foreach ($tokens as $t) {
            try { $app->make("encrypter")->decryptString($t); } catch (Throwable $e) { $bad++; }
        }
        if ($bad > 0) {
            fwrite(STDERR, "  QR トークン: $bad / ".count($tokens)." 件を復号できません（APP_KEY が違います）\n");
            exit(1);
        }
        echo "  QR トークンの復号: ", count($tokens), " 件すべて OK\n";' -- "$1"
}
