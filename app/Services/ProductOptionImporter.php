<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Enums\OptionSelection;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Rules\NoControlCharacters;
use App\Support\Csv;
use App\Support\CsvImportReader;
use Illuminate\Support\Facades\DB;

/**
 * オプション CSV 一括登録（06 §7.10・§7.11、07 §10.7、docs/18）。
 * 登録済みの商品にオプションとグループを追加する。既存の行は変更しない（同名は飛ばして警告）。
 * 検証と変換を行い、dry_run でなくエラーが無ければ 1 トランザクションで作る
 *
 * @phpstan-type LineMessages array{line: int, messages: list<string>}
 * @phpstan-type ImportResult array{dry_run: bool, valid_count: int, skipped_count: int, product_count: int, new_group_count: int, errors: list<LineMessages>, warnings: list<LineMessages>}
 * @phpstan-type OptionRow array{group: string, selection: OptionSelection|null, name: string, price: int, is_default: bool, is_active: bool}
 * @phpstan-type GroupState array{name: string, id: int|null, selection: OptionSelection, line: int, default_line: int|null}
 * @phpstan-type Target array{product: Product, groups: array<string, GroupState>, keys: array<string, int>, count: int, rows: list<OptionRow>}
 */
final class ProductOptionImporter
{
    public const HEADER = ['商品コード', '商品名', 'グループ', '選び方', 'オプション名', '価格', '最初に選ぶ', '選択可'];

    public const MAX_ROWS = 1000;

    public const MAX_PRICE = 999_999;

    /** GroupState の line・default_line、Target の keys で「登録済み」を表す行番号 */
    private const EXISTING = 0;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string  $bytes  アップロードされたファイルの中身
     * @return ImportResult
     */
    public function run(string $bytes, bool $dryRun): array
    {
        if ($dryRun) {
            return $this->analyze($bytes, true)[0];
        }

        return DB::transaction(function () use ($bytes): array {
            [$result, $targets] = $this->analyze($bytes, false);
            if ($result['errors'] !== []) {
                throw new BusinessException(
                    ErrorCode::ImportInvalid,
                    '取り込めない行があります。内容を直してからもう一度お試しください',
                    422,
                    details: $result,
                );
            }
            $this->register($result, $targets);

            return $result;
        });
    }

    /** #109 のひな形。見出しと、オプションを付けられる商品 1 件につき 1 行（商品コード・商品名のみ） */
    public function template(): string
    {
        $csv = Csv::BOM.Csv::line(self::HEADER);
        $products = Product::query()->where('is_discount', false)->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name']);
        foreach ($products as $product) {
            $csv .= Csv::line(array_pad([$product->code, $product->name], count(self::HEADER), null));
        }

        return $csv;
    }

    /** @return array{0: ImportResult, 1: array<int, Target>} */
    private function analyze(string $bytes, bool $dryRun): array
    {
        $result = [
            'dry_run' => $dryRun,
            'valid_count' => 0,
            'skipped_count' => 0,
            'product_count' => 0,
            'new_group_count' => 0,
            'errors' => [],
            'warnings' => [],
        ];
        $fileError = function (string $message) use ($result): array {
            $result['errors'] = [['line' => 0, 'messages' => [$message]]];

            return [$result, []];
        };

        $text = CsvImportReader::decode($bytes);
        if ($text === null) {
            return $fileError('文字コードを判定できません（UTF-8 か Shift_JIS で保存してください）');
        }

        // 読み飛ばす行は行数に数えないため、途中で打ち切らず全部読む（大きさはアップロードの 1MB で抑えられる）
        $records = CsvImportReader::parse($text);
        if (CsvImportReader::header($records) !== self::HEADER) {
            return $fileError('1 行目の見出しが違います');
        }

        $rows = $this->optionRows($records);
        if ($rows === []) {
            return $fileError('オプションを入力した行がありません');
        }
        if (count($rows) > self::MAX_ROWS) {
            return $fileError(number_format(self::MAX_ROWS).' 行を超えています。ファイルを分けてください');
        }

        $products = $this->loadProducts($rows);
        /** @var array<int, Target> $targets */
        $targets = [];

        foreach ($rows as $line => $cells) {
            $code = $this->code($cells[0] ?? '');
            $product = $products[$code] ?? null;
            $messages = [];
            if ($code === '') {
                $messages[] = '商品コード：入力してください';
            } elseif ($product === null) {
                $messages[] = '商品コード：'.$code.' の商品が見つかりません';
            } elseif ($product->is_discount) {
                $messages[] = '割引の商品にはオプションを付けられません';
            }

            [$row, $cellMessages] = $this->validateCells($cells);
            $messages = [...$messages, ...$cellMessages];
            if ($row === null || $product === null || $messages !== []) {
                $result['errors'][] = ['line' => $line, 'messages' => $messages];

                continue;
            }

            $targets[$product->id] ??= $this->target($product);
            $key = $this->optionKey($row['group'], $row['name']);
            $seen = $targets[$product->id]['keys'][$key] ?? null;

            $warnings = $this->nameWarning($cells[1] ?? '', $product);
            if ($seen === self::EXISTING) {
                // 「最初に選ぶ」などの判定より先に飛ばす（同じファイルの再取込をエラーにしない）
                $result['skipped_count']++;
                $warnings[] = '同じオプションが既にあるため飛ばします（価格などは変えません）';
            } else {
                $messages = $seen !== null
                    ? ['オプション名：'.$seen.' 行目と同じです']
                    : $this->place($targets[$product->id], $row, $line);
                if ($messages !== []) {
                    $result['errors'][] = ['line' => $line, 'messages' => $messages];

                    continue;
                }
            }
            if ($warnings !== []) {
                $result['warnings'][] = ['line' => $line, 'messages' => $warnings];
            }
        }

        foreach ($targets as $target) {
            if ($target['rows'] !== []) {
                $result['product_count']++;
                $result['valid_count'] += count($target['rows']);
                $result['new_group_count'] += count(array_filter($target['groups'], fn (array $g): bool => $g['id'] === null));
            }
        }

        return [$result, $targets];
    }

    /**
     * データ行のうち、グループ以降のどこかに値がある行（セルの前後の空白は除く）。
     * 商品コード・商品名だけの行は、ひな形のままオプションを付けなかった商品として読み飛ばす
     *
     * @param  array<int, list<string>>  $records
     * @return array<int, list<string>>
     */
    private function optionRows(array $records): array
    {
        $rows = [];
        foreach (CsvImportReader::dataRows($records) as $line => $cells) {
            $cells = array_map(CsvImportReader::trim(...), $cells);
            if (array_filter(array_slice($cells, 2), fn (string $cell): bool => $cell !== '') !== []) {
                $rows[$line] = $cells;
            }
        }

        return $rows;
    }

    /**
     * 取り込む行の商品だけを、オプションとグループごと読む（N+1 を作らない）
     *
     * @param  array<int, list<string>>  $rows
     * @return array<string, Product>
     */
    private function loadProducts(array $rows): array
    {
        $codes = [];
        foreach ($rows as $cells) {
            $codes[$this->code($cells[0] ?? '')] = true;
        }

        /** @var array<string, Product> */
        return Product::query()
            ->whereIn('code', array_map('strval', array_keys($codes)))
            ->with(['options', 'optionGroups'])
            ->get()
            ->keyBy('code')
            ->all();
    }

    /** ひな形で ' が付いたコードも元に戻してから正規化する */
    private function code(string $cell): string
    {
        return Product::normalizeCode(Csv::unguard($cell));
    }

    /**
     * 1 行の中だけで分かる検証。エラーが無ければ [行, []]、あれば [null, メッセージ]
     *
     * @param  list<string>  $cells  前後の空白を除いたセル
     * @return array{0: OptionRow|null, 1: list<string>}
     */
    private function validateCells(array $cells): array
    {
        $messages = [];
        foreach (array_slice($cells, count(self::HEADER)) as $extra) {
            if ($extra !== '') {
                $messages[] = '列が多すぎます';
                break;
            }
        }
        [, , $group, $selection, $name, $price, $default, $active] = array_pad($cells, count(self::HEADER), '');

        if (mb_strlen($group) > 30) {
            $messages[] = 'グループ：30 文字以内で入力してください';
        } elseif (NoControlCharacters::contains($group)) {
            $messages[] = 'グループ：使えない文字が含まれています';
        }

        $selectionValue = $this->parseSelection($selection);
        if ($selection !== '' && $selectionValue === null) {
            $messages[] = '選び方：1つ選ぶ または いくつでも で入力してください';
        } elseif ($selection !== '' && $group === '') {
            $messages[] = '選び方：グループが空の行には指定できません';
        }

        if ($name === '') {
            $messages[] = 'オプション名：入力してください';
        } elseif (mb_strlen($name) > 30) {
            $messages[] = 'オプション名：30 文字以内で入力してください';
        } elseif (NoControlCharacters::contains($name)) {
            $messages[] = 'オプション名：使えない文字が含まれています';
        }

        $priceValue = null;
        if ($price === '') {
            $messages[] = '価格：入力してください';
        } else {
            $priceValue = $this->parsePrice($price);
            if ($priceValue === null) {
                $messages[] = '価格：-999,999〜999,999 の整数で入力してください';
            }
        }

        $defaultValue = CsvImportReader::parseBool(CsvImportReader::toHalfWidth($default), false);
        if ($defaultValue === null) {
            $messages[] = '最初に選ぶ：1・ON・はい または 0・OFF・いいえ で入力してください';
        }

        $activeValue = CsvImportReader::parseBool(CsvImportReader::toHalfWidth($active), true);
        if ($activeValue === null) {
            $messages[] = '選択可：1・ON・はい または 0・OFF・いいえ で入力してください';
        }

        if ($messages !== [] || $priceValue === null || $defaultValue === null || $activeValue === null) {
            return [null, $messages];
        }

        return [[
            'group' => $group,
            'selection' => $selectionValue,
            'name' => $name,
            'price' => $priceValue,
            'is_default' => $defaultValue,
            'is_active' => $activeValue,
        ], []];
    }

    private function parseSelection(string $value): ?OptionSelection
    {
        return match (strtolower(CsvImportReader::toHalfWidth($value))) {
            '1つ選ぶ', 'single' => OptionSelection::Single,
            'いくつでも', 'multi' => OptionSelection::Multi,
            default => null,
        };
    }

    /** ,・¥・￥・\ を除き、先頭の符号（+ - −）を許す整数。範囲外は null */
    private function parsePrice(string $value): ?int
    {
        $value = str_replace(['−', ',', '¥', '￥', '\\'], ['-', '', '', '', ''], CsvImportReader::toHalfWidth($value));
        if (preg_match('/^([+-]?)([0-9]+)$/', $value, $matches) !== 1) {
            return null;
        }
        $abs = CsvImportReader::parseInt($matches[2], self::MAX_PRICE);
        if ($abs === null) {
            return null;
        }

        return $matches[1] === '-' ? -$abs : $abs;
    }

    /** @return list<string> */
    private function nameWarning(string $cell, Product $product): array
    {
        if ($cell === '' || $cell === $product->name || Csv::unguard($cell) === $product->name) {
            return [];
        }

        return ['商品名が登録と違います（登録：'.$product->name.'）'];
    }

    /** @return Target */
    private function target(Product $product): array
    {
        $groups = [];
        $names = [];
        foreach ($product->optionGroups as $group) {
            $names[$group->id] = $group->name;
            $groups[$group->name] ??= [
                'name' => $group->name,
                'id' => $group->id,
                'selection' => $group->selection,
                'line' => self::EXISTING,
                'default_line' => null,
            ];
        }

        $keys = [];
        foreach ($product->options as $option) {
            $name = $option->group_id === null ? '' : ($names[$option->group_id] ?? '');
            $keys[$this->optionKey($name, $option->name)] = self::EXISTING;
            if ($option->is_default && isset($groups[$name])) {
                $groups[$name]['default_line'] = self::EXISTING;
            }
        }

        return ['product' => $product, 'groups' => $groups, 'keys' => $keys, 'count' => $product->options->count(), 'rows' => []];
    }

    /** 同じ商品の中でオプションを見分けるキー（グループ名とオプション名の組） */
    private function optionKey(string $group, string $name): string
    {
        return $group."\0".$name;
    }

    /**
     * 既存の内容とファイルの前の行を踏まえて検証し、問題が無ければ $target に加える（エラーの行は加えない）
     *
     * @param  Target  $target
     * @param  OptionRow  $row
     * @return list<string>
     */
    private function place(array &$target, array $row, int $line): array
    {
        $messages = [];
        $groupName = $row['group'];
        $group = $target['groups'][$groupName] ?? null;
        $selection = $group['selection'] ?? null;

        if ($groupName !== '') {
            if ($group === null) {
                $selection = $row['selection'] ?? OptionSelection::Multi;
                if (count($target['groups']) >= ProductService::MAX_OPTION_GROUPS) {
                    $messages[] = 'グループ：1 商品につき '.ProductService::MAX_OPTION_GROUPS.' 件までです';
                }
            } elseif ($row['selection'] !== null && $row['selection'] !== $group['selection']) {
                $messages[] = '選び方：'.($group['line'] === self::EXISTING
                    ? '登録済みのグループと違います（登録：'.$this->selectionLabel($group['selection']).'）'
                    : $group['line'].' 行目と違います');
            }
        }

        if ($row['is_default']) {
            if ($selection !== OptionSelection::Single) {
                $messages[] = '最初に選ぶ：「1つ選ぶ」のグループだけに指定できます';
            } elseif ($group !== null && $group['default_line'] !== null) {
                $messages[] = '最初に選ぶ：'.($group['default_line'] === self::EXISTING
                    ? 'このグループには既に指定があります'
                    : '同じグループの '.$group['default_line'].' 行目に指定があります');
            }
        }

        if ($target['count'] >= ProductService::MAX_OPTIONS) {
            $messages[] = 'オプションは 1 商品につき '.ProductService::MAX_OPTIONS.' 件までです';
        }
        if ($messages !== []) {
            return $messages;
        }

        if ($groupName !== '' && $selection !== null) {
            $group ??= ['name' => $groupName, 'id' => null, 'selection' => $selection, 'line' => $line, 'default_line' => null];
            if ($row['is_default']) {
                $group['default_line'] = $line;
            }
            $target['groups'][$groupName] = $group;
        }
        $target['keys'][$this->optionKey($groupName, $row['name'])] = $line;
        $target['count']++;
        $target['rows'][] = $row;

        return [];
    }

    private function selectionLabel(OptionSelection $selection): string
    {
        return match ($selection) {
            OptionSelection::Single => '1つ選ぶ',
            OptionSelection::Multi => 'いくつでも',
        };
    }

    /**
     * ProductService::createOption は 1 件ごとに操作ログと件数の問い合わせが出るため使わない。
     * 同じ規則は analyze で満たしている。店舗・商品は親から設定する（CSV の値から ID を取らない）
     *
     * @param  ImportResult  $result
     * @param  array<int, Target>  $targets
     */
    private function register(array $result, array $targets): void
    {
        if ($result['valid_count'] === 0) {
            return;
        }

        foreach ($targets as $target) {
            $product = $target['product'];
            $parent = ['store_id' => $product->store_id, 'product_id' => $product->id];
            $nextGroupOrder = $this->nextOrder($product->optionGroups->max('sort_order'));
            $nextOptionOrder = $this->nextOrder($product->options->max('sort_order'));

            $groupIds = [];
            foreach ($target['groups'] as $state) {
                if ($state['id'] === null) {
                    $group = new ProductOptionGroup(['name' => $state['name'], 'selection' => $state['selection']]);
                    $group->forceFill($parent);
                    $group->sort_order = $nextGroupOrder++;
                    $group->save();
                    $state['id'] = $group->id;
                }
                $groupIds[$state['name']] = $state['id'];
            }

            foreach ($target['rows'] as $row) {
                $option = new ProductOption([
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'is_active' => $row['is_active'],
                    'group_id' => $row['group'] === '' ? null : $groupIds[$row['group']],
                    'is_default' => $row['is_default'],
                ]);
                $option->forceFill($parent);
                $option->sort_order = $nextOptionOrder++;
                $option->save();
            }
        }

        $this->audit->log(AuditAction::ProductOptionsImported, null, null, [
            'count' => $result['valid_count'],
            'skipped' => $result['skipped_count'],
            'products' => $result['product_count'],
            'new_groups' => $result['new_group_count'],
        ]);
    }

    /** SortOrder::next と同じ（最大 + 1、無ければ 0）。読み込み済みの値から求める */
    private function nextOrder(mixed $max): int
    {
        return is_numeric($max) ? (int) $max + 1 : 0;
    }
}
