<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** 06 §11.3（admin のみ。SQLite 専用） */
class BackupController extends Controller
{
    /** #45 GET /admin/backup。VACUUM INTO で整合した複製を作って返し、送信後に削除する */
    public function download(AuditLogger $audit): BinaryFileResponse
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            throw new BusinessException(ErrorCode::Validation, 'バックアップは SQLite のときだけ使えます', 422);
        }

        $dir = storage_path('app/private/backups');
        File::ensureDirectoryExists($dir);
        $name = 'regi-'.CarbonImmutable::now(BusinessDate::TIMEZONE)->format('Ymd-His').'.sqlite';
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        if (File::exists($path)) {
            // 同じ秒の 2 回目（回数制限の外から来た場合）。VACUUM INTO は既存のファイルに書けない
            throw new BusinessException(ErrorCode::TooManyAttempts, 'しばらく待ってからもう一度お試しください', 429);
        }

        // パスはサーバーで組み立てた値だけ。束縛で渡す
        $connection->statement('VACUUM INTO ?', [$path]);
        $audit->log(AuditAction::BackupDownloaded, after: ['file' => $name]);

        return response()->download($path, $name, ['Content-Type' => 'application/octet-stream'])->deleteFileAfterSend();
    }
}
