<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/** WP 1-5：運営者が使う store:create・admin:create と docs/sql/create_store_owner.sql（02 §4.5、11 §2・§3） */
class OperatorCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_Q = '店舗名';

    private const OWNER_ID_Q = 'オーナーのログイン ID（3〜50 文字の半角英数字と _ . -）';

    private const OWNER_NAME_Q = 'オーナーの表示名';

    private const ADMIN_ID_Q = 'ログイン ID（3〜50 文字の半角英数字と _ . -）';

    private const PASSWORD_Q = 'パスワード（8〜72 文字。画面に表示されません）';

    private const CONFIRM_Q = '確認のためもう一度';

    public function test_store_createで店舗とオーナーができログインすると初期データが作られる(): void
    {
        $this->command('store:create')
            ->expectsQuestion(self::STORE_Q, 'カフェ本店')
            ->expectsQuestion(self::OWNER_ID_Q, 'cafe-owner')
            ->expectsQuestion(self::OWNER_NAME_Q, '山田')
            ->expectsQuestion(self::PASSWORD_Q, 'secret-pass-1')
            ->expectsQuestion(self::CONFIRM_Q, 'secret-pass-1')
            ->assertSuccessful();

        $owner = User::query()->where('login_id', 'cafe-owner')->sole();
        $this->assertSame(Role::Owner, $owner->role);
        $this->assertSame('カフェ本店', $owner->store?->name);
        $this->assertTrue(Hash::check('secret-pass-1', $owner->password));

        $this->fromSpa()->postJson('/api/login', ['login_id' => 'cafe-owner', 'password' => 'secret-pass-1'])
            ->assertOk()
            ->assertJsonPath('store.name', 'カフェ本店');
        $this->assertNotNull(Store::query()->findOrFail($owner->store_id)->initialized_at);
    }

    public function test_store_createは不正な入力を聞き直す(): void
    {
        User::factory()->owner()->create(['login_id' => 'taken']);

        $this->command('store:create')
            ->expectsQuestion(self::STORE_Q, '')
            ->expectsOutput('店舗名を入力してください')
            ->expectsQuestion(self::STORE_Q, '2 号店')
            ->expectsQuestion(self::OWNER_ID_Q, 'taken')
            ->expectsOutput('そのログイン IDは既に使われています')
            ->expectsQuestion(self::OWNER_ID_Q, 'bad id!')
            ->expectsOutput('ログイン IDの形式が正しくありません')
            ->expectsQuestion(self::OWNER_ID_Q, 'owner2')
            ->expectsQuestion(self::OWNER_NAME_Q, '佐藤')
            ->expectsQuestion(self::PASSWORD_Q, 'short')
            ->expectsOutput('パスワードは8文字以上にしてください')
            ->expectsQuestion(self::PASSWORD_Q, 'long-enough-1')
            ->expectsQuestion(self::CONFIRM_Q, 'mismatch')
            ->expectsOutput('パスワードが一致しません')
            ->expectsQuestion(self::PASSWORD_Q, 'long-enough-1')
            ->expectsQuestion(self::CONFIRM_Q, 'long-enough-1')
            ->assertSuccessful();

        $this->assertSame(1, Store::query()->where('name', '2 号店')->count());
    }

    public function test_admin_createは1人だけ作れる(): void
    {
        $this->command('admin:create')
            ->expectsQuestion(self::ADMIN_ID_Q, 'admin')
            ->expectsQuestion('表示名', '管理者')
            ->expectsQuestion(self::PASSWORD_Q, 'admin-pass-1')
            ->expectsQuestion(self::CONFIRM_Q, 'admin-pass-1')
            ->assertSuccessful();

        $admin = User::query()->where('login_id', 'admin')->sole();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertNull($admin->store_id);

        $this->command('admin:create')
            ->expectsOutput('admin は既に登録されています（システム全体で 1 人だけです）')
            ->assertFailed();
        $this->assertSame(1, User::query()->where('role', Role::Admin)->count());
    }

    public function test_sq_lの雛形を流すとログインできる(): void
    {
        $hash = password_hash('template-pass-1', PASSWORD_BCRYPT);
        $sql = $this->template();
        $sql = str_replace(['<店舗名>', '<ログインID>', '<表示名>', '<$2y$で始まるハッシュ>'], ['雛形店', 'tpl-owner', '雛形オーナー', $hash], $sql);
        // 手順書どおり sqlite3 に流すのと同じく、文を順に実行する（BEGIN / COMMIT はテストのトランザクションと重なるため除く）
        foreach ($this->statements($sql) as $statement) {
            if (preg_match('/^(BEGIN|COMMIT)/i', $statement) === 1) {
                continue;
            }
            DB::unprepared($statement);
        }

        $this->fromSpa()->postJson('/api/login', ['login_id' => 'tpl-owner', 'password' => 'template-pass-1'])
            ->assertOk()
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('store.name', '雛形店')
            ->assertJsonPath('store.price_mode', 'tax_included')
            ->assertJsonPath('store.day_cutoff_time', '00:00');
    }

    public function test_admin用の_sq_lも流せる(): void
    {
        $sql = $this->template();
        $this->assertSame(1, preg_match("/-- (INSERT INTO users \\(store_id, role.*?'admin'.*?\\);)/s", $sql, $m));
        $insert = preg_replace('/\n-- /', "\n", $m[1] ?? '');
        $insert = str_replace(['<ログインID>', '<$2y$で始まるハッシュ>'], ['tpl-admin', password_hash('admin-pass-1', PASSWORD_BCRYPT)], (string) $insert);
        DB::unprepared($insert);

        $this->fromSpa()->postJson('/api/login', ['login_id' => 'tpl-admin', 'password' => 'admin-pass-1'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');
    }

    private function command(string $name): PendingCommand
    {
        $pending = $this->artisan($name);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /** docs/ は git に入れないため、無い環境（クローン直後など）では飛ばす */
    private function template(): string
    {
        $path = base_path('docs/sql/create_store_owner.sql');
        if (! is_file($path)) {
            $this->markTestSkipped('docs/sql/create_store_owner.sql がありません');
        }

        return (string) file_get_contents($path);
    }

    /** @return list<string> コメント行を除き、; で区切った文 */
    private function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $sql)), fn (string $l) => ! str_starts_with(ltrim($l), '--'));

        return array_values(array_filter(array_map('trim', explode(';', implode("\n", $lines))), fn (string $s) => $s !== ''));
    }
}
