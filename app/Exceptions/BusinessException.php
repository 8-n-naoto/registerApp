<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * 業務ルール違反・状態の競合。bootstrap/app.php で 06 §1.3 の JSON に変換される。
 * 例：throw new BusinessException(ErrorCode::OutOfStock, '在庫が足りません', 409, details: ['shortages' => $rows]);
 */
final class BusinessException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  入力欄ごとのメッセージ（無ければ空）
     * @param  array<string, mixed>  $details  画面が使う補足（server_total、shortages、product_ids など）
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $errors = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
