<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PDO;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * WP 5-4：06 §11.3 GET /admin/backup（AC-A01-4）。
 * VACUUM INTO はトランザクションの中で使えないため RefreshDatabase を使わず、一時ファイルの SQLite に移行して試す（04 §4.11）
 */
class AdminBackupApiTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir().'/regi-backup-test-'.uniqid().'.sqlite';
        touch($this->dbPath);
        config(['database.connections.sqlite.database' => $this->dbPath]);
        DB::purge('sqlite');
        $command = $this->artisan('migrate', ['--force' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        foreach ([$this->dbPath, $this->dbPath.'-wal', $this->dbPath.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_整合した複製を返し_操作ログを残し_送信後に消す(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 16:05:09', 'Asia/Tokyo'));
        $store = Store::factory()->create(['name' => 'バックアップ店']);
        $admin = User::factory()->admin()->create();

        $res = $this->actingAs($admin)->get('/api/admin/backup')->assertOk();
        $res->assertHeader('Content-Type', 'application/octet-stream');
        $res->assertDownload('regi-20260929-160509.sqlite');

        $base = $res->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $base);
        $file = $base->getFile()->getPathname();
        $this->assertSame(storage_path('app/private/backups').DIRECTORY_SEPARATOR.'regi-20260929-160509.sqlite', $file);

        // AC-A01-4：SQLite として開け、integrity_check が ok、データが入っている
        $pdo = new PDO('sqlite:'.$file);
        $check = $pdo->query('PRAGMA integrity_check');
        $this->assertNotFalse($check);
        $this->assertSame('ok', $check->fetchColumn());
        $name = $pdo->query("SELECT name FROM stores WHERE id = {$store->id}");
        $this->assertNotFalse($name);
        $this->assertSame('バックアップ店', $name->fetchColumn());
        $check = $name = null;
        $pdo = null;

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'backup_downloaded')->sole();
        $this->assertNull($log->store_id);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(['file' => 'regi-20260929-160509.sqlite'], $log->after);

        // 送信すると消える
        ob_start();
        $base->sendContent();
        $body = (string) ob_get_clean();
        $this->assertStringStartsWith("SQLite format 3\0", $body);
        $this->assertFileDoesNotExist($file);
    }

    public function test_回数制限は1分に1回(): void
    {
        $admin = User::factory()->admin()->create();
        $first = $this->actingAs($admin)->get('/api/admin/backup')->assertOk()->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $first);
        File::delete($first->getFile()->getPathname());

        $this->get('/api/admin/backup')->assertStatus(429);
    }

    public function test_sq_lite以外は422(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        config(['database.default' => 'pgsql']);   // 接続は遅延するため、ドライバ名を見るだけで接続はしない

        $this->getJson('/api/admin/backup')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION');
        config(['database.default' => 'sqlite']);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }
}
