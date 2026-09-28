<?php

namespace App\Enums;

/** 06 §2.6 の操作コード。表示名は action_label として返す */
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
    case SaleCancelled = 'sale_cancelled';
    case ClosingSaved = 'closing_saved';
    case StaffCreated = 'staff_created';
    case StaffUpdated = 'staff_updated';
    case StaffPasswordReset = 'staff_password_reset';
    case StoreSuspended = 'store_suspended';
    case StoreResumed = 'store_resumed';
    case BackupDownloaded = 'backup_downloaded';

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
            self::SaleCancelled => '会計の取消',
            self::ClosingSaved => 'レジ締め',
            self::StaffCreated => 'スタッフの追加',
            self::StaffUpdated => 'スタッフの変更',
            self::StaffPasswordReset => 'スタッフのパスワード再設定',
            self::StoreSuspended => '店舗の停止',
            self::StoreResumed => '店舗の再開',
            self::BackupDownloaded => 'バックアップ取得',
        };
    }
}
