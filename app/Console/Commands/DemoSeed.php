<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\User;
use App\Services\Demo\DemoDataGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 検証用のデモ店舗を作り、直近の数か月分の営業データ（会計・注文・在庫・勤怠・勤務表・レジ締め）を入れる。
 * 既存の店舗には触れない。店舗名・ログイン ID が既にあれば何もしない（11 §2.5）
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed
        {--months=4 : 何か月前から営業していたことにするか（1〜12）}
        {--name=デモ店 : 作る店舗の名前}
        {--prefix=demo : ログイン ID の先頭（demo-owner・demo-staff1〜4）}
        {--password= : 全員のパスワード（8 文字以上。省略すると無作為に作って 1 度だけ表示する）}
        {--seed= : 乱数の種（同じ値なら同じ内容になる）}
        {--force : 本番環境でも確認せずに実行する}';

    protected $description = '検証用のデモ店舗と、直近の数か月分の営業データを作る';

    public function handle(DemoDataGenerator $generator): int
    {
        $months = (int) $this->option('months');
        $name = (string) $this->option('name');
        $prefix = (string) $this->option('prefix');
        $password = $this->option('password');
        $seedOption = $this->option('seed');

        if ($months < 1 || $months > 12) {
            $this->error('--months は 1〜12 で指定してください');

            return self::FAILURE;
        }
        if ($name === '' || mb_strlen($name) > 100) {
            $this->error('--name は 1〜100 文字で指定してください');

            return self::FAILURE;
        }
        if (! preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $prefix)) {
            $this->error('--prefix は 40 文字以内の半角英数字と _ . - で指定してください');

            return self::FAILURE;
        }
        if (is_string($password) && (strlen($password) < 8 || strlen($password) > 72)) {
            $this->error('--password は 8〜72 文字で指定してください');

            return self::FAILURE;
        }

        $loginIds = ["{$prefix}-owner", "{$prefix}-staff1", "{$prefix}-staff2", "{$prefix}-staff3", "{$prefix}-staff4"];
        if (Store::query()->where('name', $name)->exists()) {
            $this->error("店舗「{$name}」は既にあります。--name で別の名前を指定してください");

            return self::FAILURE;
        }
        $taken = User::query()->whereIn('login_id', $loginIds)->pluck('login_id')->all();
        if ($taken !== []) {
            $this->error('ログイン ID が既に使われています：'.implode('、', $taken).'。--prefix で別の先頭を指定してください');

            return self::FAILURE;
        }

        $this->line("店舗「{$name}」を新しく作り、{$months} か月前から今日までの営業データを入れます。既存の店舗には触れません。");
        if (app()->environment('production') && ! $this->option('force')
            && ! $this->confirm('本番環境です。デモ店舗を作りますか？', false)) {
            $this->line('中止しました');

            return self::FAILURE;
        }

        $generated = ! is_string($password);
        $password = is_string($password) ? $password : Str::password(16, symbols: false);
        $seed = is_numeric($seedOption) ? (int) $seedOption : random_int(1, PHP_INT_MAX);

        $started = microtime(true);
        $summary = $generator->generate($name, $loginIds, $password, $months, $seed, function (string $month): void {
            $this->line("  {$month} …");
        });

        $this->info(sprintf('作成しました（%.1f 秒）：店舗 #%d「%s」 %s〜%s', microtime(true) - $started, $summary['store_id'], $summary['store_name'], $summary['from'], $summary['to']));
        $this->table(['項目', '件数'], [
            ['会計', $summary['sales']],
            ['うち取消', $summary['cancelled_sales']],
            ['注文（テーブル）', $summary['orders']],
            ['勤怠', $summary['attendances']],
            ['勤務の予定', $summary['shifts']],
            ['勤務の希望', $summary['shift_requests']],
            ['レジ締め', $summary['closings']],
            ['売切れ等で見送った操作', $summary['skipped']],
        ]);
        $this->line('ログイン ID：'.implode('、', $summary['login_ids']).'（オーナー 1・スタッフ 4）');
        if ($generated) {
            $this->warn("パスワード（全員共通。この画面にだけ表示します）：{$password}");
        }
        $this->line("乱数の種：{$seed}");

        return self::SUCCESS;
    }
}
