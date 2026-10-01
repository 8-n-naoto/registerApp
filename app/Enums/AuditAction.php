<?php

namespace App\Enums;

/** 06 §2.6・12 §5.19 の操作コード。表示名は action_label として返す */
enum AuditAction: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case PasswordChanged = 'password_changed';
    case StoreInitialized = 'store_initialized';
    case StoreSettingsUpdated = 'store_settings_updated';
    case TaxTypeCreated = 'tax_type_created';
    case TaxTypeUpdated = 'tax_type_updated';
    case PaymentMethodCreated = 'payment_method_created';
    case PaymentMethodUpdated = 'payment_method_updated';
    case CategoryCreated = 'category_created';
    case CategoryUpdated = 'category_updated';
    case CategoryDeleted = 'category_deleted';
    case ProductCreated = 'product_created';
    case ProductUpdated = 'product_updated';
    case ProductDeleted = 'product_deleted';
    case ProductStockChanged = 'product_stock_changed';
    case ProductsImported = 'products_imported';
    case OptionCreated = 'option_created';
    case OptionUpdated = 'option_updated';
    case OptionDeleted = 'option_deleted';
    case OptionGroupCreated = 'option_group_created';
    case OptionGroupUpdated = 'option_group_updated';
    case OptionGroupDeleted = 'option_group_deleted';
    case SaleCancelled = 'sale_cancelled';
    case ClosingSaved = 'closing_saved';
    case StaffCreated = 'staff_created';
    case StaffUpdated = 'staff_updated';
    case StaffPasswordReset = 'staff_password_reset';
    case StoreSuspended = 'store_suspended';
    case StoreResumed = 'store_resumed';
    case BackupDownloaded = 'backup_downloaded';

    // 12 §5.19（注文）
    case OrderCreated = 'order_created';
    case OrderAccepted = 'order_accepted';
    case OrderCancelled = 'order_cancelled';
    case OrderTableCreated = 'order_table_created';
    case OrderTableUpdated = 'order_table_updated';
    case OrderTableDeleted = 'order_table_deleted';
    case OrderTableTokenRegenerated = 'order_table_token_regenerated';
    case OrderTableOpened = 'order_table_opened';
    case OrderTableClosed = 'order_table_closed';
    case OrderSettingsUpdated = 'order_settings_updated';

    // 13 §5（勤怠）
    case AttendanceClockedIn = 'attendance_clocked_in';
    case AttendanceClockedOut = 'attendance_clocked_out';
    case AttendanceBreakStarted = 'attendance_break_started';
    case AttendanceBreakEnded = 'attendance_break_ended';
    case OperatorSwitched = 'operator_switched';
    case AttendanceCreated = 'attendance_created';
    case AttendanceUpdated = 'attendance_updated';
    case AttendanceDeleted = 'attendance_deleted';
    case LaborSettingsUpdated = 'labor_settings_updated';
    case LaborMemberUpdated = 'labor_member_updated';
    case ShiftMonthUpdated = 'shift_month_updated';
    case ShiftCreated = 'shift_created';
    case ShiftUpdated = 'shift_updated';
    case ShiftDeleted = 'shift_deleted';
    case ShiftRequestsSubmitted = 'shift_requests_submitted';
    case ShiftPatternCreated = 'shift_pattern_created';
    case ShiftPatternUpdated = 'shift_pattern_updated';

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'ログイン',
            self::LoginFailed => 'ログイン失敗',
            self::PasswordChanged => 'パスワード変更',
            self::StoreInitialized => '店舗の初期設定',
            self::StoreSettingsUpdated => '店舗設定の変更',
            self::TaxTypeCreated => '税区分の追加',
            self::TaxTypeUpdated => '税区分の変更',
            self::PaymentMethodCreated => '支払方法の追加',
            self::PaymentMethodUpdated => '支払方法の変更',
            self::CategoryCreated => 'カテゴリの追加',
            self::CategoryUpdated => 'カテゴリの変更',
            self::CategoryDeleted => 'カテゴリの削除',
            self::ProductCreated => '商品の追加',
            self::ProductUpdated => '商品の変更',
            self::ProductDeleted => '商品の削除',
            self::ProductStockChanged => '在庫数の変更',
            self::ProductsImported => '商品の一括登録',
            self::OptionCreated => 'オプションの追加',
            self::OptionUpdated => 'オプションの変更',
            self::OptionDeleted => 'オプションの削除',
            self::OptionGroupCreated => 'オプションのグループの追加',
            self::OptionGroupUpdated => 'オプションのグループの変更',
            self::OptionGroupDeleted => 'オプションのグループの削除',
            self::SaleCancelled => '会計の取消',
            self::ClosingSaved => 'レジ締め',
            self::StaffCreated => 'スタッフの追加',
            self::StaffUpdated => 'スタッフの変更',
            self::StaffPasswordReset => 'スタッフのパスワード再設定',
            self::StoreSuspended => '店舗の停止',
            self::StoreResumed => '店舗の再開',
            self::BackupDownloaded => 'バックアップ取得',
            self::OrderCreated => 'お客さんの注文',
            self::OrderAccepted => '注文の受付',
            self::OrderCancelled => '注文の取消',
            self::OrderTableCreated => 'テーブルの追加',
            self::OrderTableUpdated => 'テーブルの変更',
            self::OrderTableDeleted => 'テーブルの削除',
            self::OrderTableTokenRegenerated => 'QR の作り直し',
            self::OrderTableOpened => 'テーブルの利用開始',
            self::OrderTableClosed => 'テーブルの利用終了',
            self::OrderSettingsUpdated => '注文の設定の変更',
            self::AttendanceClockedIn => '出勤',
            self::AttendanceClockedOut => '退勤',
            self::AttendanceBreakStarted => '休憩開始',
            self::AttendanceBreakEnded => '休憩終了',
            self::OperatorSwitched => '担当者の切替',
            self::AttendanceCreated => '打刻の追加',
            self::AttendanceUpdated => '打刻の修正',
            self::AttendanceDeleted => '打刻の削除',
            self::LaborSettingsUpdated => '労働条件の変更',
            self::LaborMemberUpdated => '時給・区分の変更',
            self::ShiftMonthUpdated => '勤務表の締切・公開',
            self::ShiftCreated => '勤務の予定の追加',
            self::ShiftUpdated => '勤務の予定の変更',
            self::ShiftDeleted => '勤務の予定の削除',
            self::ShiftRequestsSubmitted => '勤務の希望の提出',
            self::ShiftPatternCreated => '勤務の区分の追加',
            self::ShiftPatternUpdated => '勤務の区分の変更',
        };
    }
}
