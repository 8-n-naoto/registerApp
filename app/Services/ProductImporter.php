<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Enums\ProductColor;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * 商品 CSV 一括登録（06 §7.7・07 §10）。
 * 検証と変換を行い、dry_run でなくエラーが無ければ 1 トランザクションでカテゴリと商品を作る。
 *
 * @phpstan-type ImportRow array{line: int, category: string|null, name: string, price: int, color: string, track_stock: bool, stock_qty: int, is_active: bool, code: string|null, memo: string|null}
 * @phpstan-type LineMessages array{line: int, messages: list<string>}
 * @phpstan-type ImportResult array{dry_run: bool, valid_count: int, new_categories: list<string>, errors: list<LineMessages>, warnings: list<LineMessages>, rows: list<ImportRow>}
 */
final class ProductImporter
{
    public const HEADER = ['カテゴリ', '商品名', '価格', '色', '在庫管理', '在庫数', '販売中', '商品コード', 'メモ'];

    /** 商品コード・メモの列を追加する前の形式。コードは自動採番、メモは空で取り込む */
    public const LEGACY_HEADER = ['カテゴリ', '商品名', '価格', '色', '在庫管理', '在庫数', '販売中'];

    public const COLUMNS = 9;

    public const MAX_ROWS = 500;

    private const BOM = "\xEF\xBB\xBF";

    private const TRUE_WORDS = ['1', 'on', 'はい'];

    private const FALSE_WORDS = ['0', 'off', 'いいえ'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string  $bytes  アップロードされたファイルの中身
     * @return ImportResult
     */
    public function run(string $bytes, bool $dryRun): array
    {
        if ($dryRun) {
            return $this->analyze($bytes, true);
        }

        return DB::transaction(function () use ($bytes): array {
            $result = $this->analyze($bytes, false);
            if ($result['errors'] !== []) {
                throw new BusinessException(
                    ErrorCode::ImportInvalid,
                    '取り込めない行があります。内容を直してからもう一度お試しください',
                    422,
                    details: $result,
                );
            }
            $this->register($result);

            return $result;
        });
    }

    /** @return ImportResult */
    private function analyze(string $bytes, bool $dryRun): array
    {
        $result = [
            'dry_run' => $dryRun,
            'valid_count' => 0,
            'new_categories' => [],
            'errors' => [],
            'warnings' => [],
            'rows' => [],
        ];
        $fileError = function (string $message) use ($result): array {
            $result['errors'] = [['line' => 0, 'messages' => [$message]]];

            return $result;
        };

        $text = $this->decode($bytes);
        if ($text === null) {
            return $fileError('文字コードを判定できません（UTF-8 か Shift_JIS で保存してください）');
        }

        $records = $this->parse($text);
        $header = array_map($this->trim(...), $records[1] ?? []);
        while ($header !== [] && end($header) === '') {
            array_pop($header);
        }
        if ($header !== self::HEADER && $header !== self::LEGACY_HEADER) {
            return $fileError('1 行目の見出しが違います');
        }

        $data = [];
        foreach ($records as $line => $cells) {
            if ($line > 1 && ! $this->isBlank($cells)) {
                $data[$line] = $cells;
            }
        }
        if ($data === []) {
            return $fileError('データ行がありません');
        }
        if (count($data) > self::MAX_ROWS) {
            return $fileError(self::MAX_ROWS.' 行を超えています');
        }

        $existingCategories = Category::query()->pluck('name')->all();
        $existingKeys = [];
        foreach (Product::query()->get(['name', 'memo']) as $product) {
            $existingKeys[$this->sameKey($product->name, $product->memo)] = true;
        }
        $existingCodes = array_flip(Product::query()->pluck('code')->all());
        $seenKeys = [];
        $seenCodes = [];

        foreach ($data as $line => $cells) {
            [$row, $messages] = $this->validateRow($cells, count($header));
            if ($row !== null && $row['code'] !== null) {
                if (isset($existingCodes[$row['code']])) {
                    $messages[] = '商品コード：既に使われています';
                } elseif (isset($seenCodes[$row['code']])) {
                    $messages[] = '商品コード：'.$seenCodes[$row['code']].' 行目と同じです';
                } else {
                    $seenCodes[$row['code']] = $line;
                }
            }
            if ($row === null || $messages !== []) {
                $result['errors'][] = ['line' => $line, 'messages' => $messages];

                continue;
            }

            $category = $row['category'];
            if ($category !== null && ! in_array($category, $existingCategories, true) && ! in_array($category, $result['new_categories'], true)) {
                $result['new_categories'][] = $category;
            }

            $warnings = [];
            // 同名でもメモが違えば別の商品として扱う（見分けが付かないものだけ警告）
            $key = $this->sameKey($row['name'], $row['memo']);
            if (isset($existingKeys[$key])) {
                $warnings[] = '商品名・メモが同じ商品が既にあります';
            }
            if (isset($seenKeys[$key])) {
                $warnings[] = $seenKeys[$key].' 行目と商品名・メモが同じです';
            } else {
                $seenKeys[$key] = $line;
            }
            if ($warnings !== []) {
                $result['warnings'][] = ['line' => $line, 'messages' => $warnings];
            }

            $result['rows'][] = ['line' => $line, ...$row];
        }
        $result['valid_count'] = count($result['rows']);

        if (Product::query()->count() + $result['valid_count'] > ProductService::MAX_PRODUCTS) {
            $result['errors'][] = ['line' => 0, 'messages' => ['商品数の上限（'.ProductService::MAX_PRODUCTS.' 件）を超えます']];
        }
        if (Category::query()->count() + count($result['new_categories']) > CategoryService::MAX_CATEGORIES) {
            $result['errors'][] = ['line' => 0, 'messages' => ['カテゴリ数の上限（'.CategoryService::MAX_CATEGORIES.' 件）を超えます']];
        }

        return $result;
    }

    /**
     * 07 §10.2・§10.3。エラーが無ければ [行, []]、あれば [null, メッセージ]
     *
     * @param  list<string>  $cells
     * @param  int  $columns  見出しの列数（旧形式は 7）
     * @return array{0: array{category: string|null, name: string, price: int, color: string, track_stock: bool, stock_qty: int, is_active: bool, code: string|null, memo: string|null}|null, 1: list<string>}
     */
    public function validateRow(array $cells, int $columns = self::COLUMNS): array
    {
        $cells = array_map($this->trim(...), $cells);
        $messages = [];

        foreach (array_slice($cells, $columns) as $extra) {
            if ($extra !== '') {
                $messages[] = '列が多すぎます';
                break;
            }
        }
        [$category, $name, $price, $color, $track, $stock, $active, $code, $memo] = array_pad(array_slice($cells, 0, $columns), self::COLUMNS, '');

        if (mb_strlen($category) > 30) {
            $messages[] = 'カテゴリ：30 文字以内で入力してください';
        }

        if ($name === '') {
            $messages[] = '商品名：入力してください';
        } elseif (mb_strlen($name) > 50) {
            $messages[] = '商品名：50 文字以内で入力してください';
        }

        $priceValue = null;
        $price = str_replace([',', '¥', '￥', '\\'], '', $this->toHalfWidth($price));
        if ($price === '') {
            $messages[] = '価格：入力してください';
        } else {
            $priceValue = $this->parseInt($price, 9_999_999);
            if ($priceValue === null) {
                $messages[] = '価格：0〜9,999,999 の整数で入力してください';
            }
        }

        $colorValue = $this->parseColor($this->toHalfWidth($color));
        if ($colorValue === null) {
            $messages[] = '色：次のいずれかで入力してください（'.implode('・', array_map(
                fn (ProductColor $c) => $c->label(),
                ProductColor::cases(),
            )).'、または gray・red などの英字）';
        }

        $trackValue = $this->parseBool($this->toHalfWidth($track), false);
        if ($trackValue === null) {
            $messages[] = '在庫管理：1・ON・はい または 0・OFF・いいえ で入力してください';
        }

        $stock = str_replace(',', '', $this->toHalfWidth($stock));
        $stockValue = $stock === '' ? 0 : $this->parseInt($stock, StockService::MAX_QTY);
        if ($stockValue === null) {
            $messages[] = '在庫数：0〜999,999 の整数で入力してください';
        }

        $activeValue = $this->parseBool($this->toHalfWidth($active), true);
        if ($activeValue === null) {
            $messages[] = '販売中：1・ON・はい または 0・OFF・いいえ で入力してください';
        }

        $code = Product::normalizeCode($code);
        if (strlen($code) > 20 || ($code !== '' && preg_match(Product::CODE_PATTERN, $code) !== 1)) {
            $messages[] = '商品コード：英数字・ハイフン・アンダースコアの 20 文字以内で入力してください';
        }

        if (mb_strlen($memo) > 200) {
            $messages[] = 'メモ：200 文字以内で入力してください';
        }

        if ($messages !== [] || $priceValue === null || $colorValue === null || $trackValue === null
            || $stockValue === null || $activeValue === null) {
            return [null, $messages];
        }

        return [[
            'category' => $category === '' ? null : $category,
            'name' => $name,
            'price' => $priceValue,
            'color' => $colorValue,
            'track_stock' => $trackValue,
            'stock_qty' => $stockValue,
            'is_active' => $activeValue,
            'code' => $code === '' ? null : $code,
            'memo' => $memo === '' ? null : $memo,
        ], []];
    }

    /** @param  ImportResult  $result */
    private function register(array $result): void
    {
        $categoryIds = Category::query()->pluck('id', 'name')->all();
        $nextCategoryOrder = SortOrder::next(Category::query());
        foreach ($result['new_categories'] as $name) {
            $category = new Category(['name' => $name]);
            $category->sort_order = $nextCategoryOrder++;
            $category->save();
            $categoryIds[$name] = $category->id;
        }

        /** @var array<string, int> $nextOrder カテゴリ ID（未分類は ''）→ 次の sort_order */
        $nextOrder = [];
        /** @var list<Product> $withCode */
        $withCode = [];
        /** @var list<Product> $autoCode */
        $autoCode = [];
        foreach ($result['rows'] as $row) {
            $categoryId = $row['category'] === null ? null : $categoryIds[$row['category']];
            $key = (string) $categoryId;
            $nextOrder[$key] ??= SortOrder::next($categoryId === null
                ? Product::query()->whereNull('category_id')
                : Product::query()->where('category_id', $categoryId));

            $product = new Product([
                'category_id' => $categoryId,
                'code' => $row['code'] ?? '',
                'name' => $row['name'],
                'memo' => $row['memo'],
                'price' => $row['price'],
                'color' => $row['color'],
                'is_active' => $row['is_active'],
                'track_stock' => $row['track_stock'],
                'stock_qty' => $row['stock_qty'],
            ]);
            $product->sort_order = $nextOrder[$key]++;
            if ($row['code'] === null) {
                $autoCode[] = $product;
            } else {
                $withCode[] = $product;
            }
        }
        // 自動採番がファイル内で指定されたコード（P0005 など）と重ならないよう、指定のある行を先に保存する
        foreach ([...$withCode, ...$autoCode] as $product) {
            $product->save();
        }

        $this->audit->log(AuditAction::ProductsImported, null, null, [
            'count' => $result['valid_count'],
            'new_categories' => $result['new_categories'],
        ]);
    }

    /** 同名判定のキー（商品名とメモの組） */
    private function sameKey(string $name, ?string $memo): string
    {
        return $name."\0".($memo ?? '');
    }

    /** 先頭の BOM を除き UTF-8 にする。UTF-8 でも CP932 でもなければ null */
    private function decode(string $bytes): ?string
    {
        if (str_starts_with($bytes, self::BOM)) {
            $bytes = substr($bytes, strlen(self::BOM));
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        if (mb_check_encoding($bytes, 'SJIS-win')) {
            return mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        }

        return null;
    }

    /**
     * レコード番号（見出し = 1）→ セルの配列。引用符内のカンマ・改行・"" を扱う。
     * CRLF / CR は LF にそろえる（引用符内の改行も LF になる）
     *
     * @return array<int, list<string>>
     */
    private function parse(string $text): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, str_replace(["\r\n", "\r"], "\n", $text));
        rewind($stream);

        $records = [];
        $line = 0;
        $filled = 0;
        while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $cells = array_map(fn (?string $c): string => (string) $c, $cells);
            $records[++$line] = $cells;
            if (! $this->isBlank($cells) && ++$filled > self::MAX_ROWS + 1) {
                break; // 見出し + 上限を超えたことが分かれば残りは読まない
            }
        }
        fclose($stream);

        return $records;
    }

    /** @param  list<string>  $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($this->trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** 前後の半角スペース・タブ・改行・全角スペースを除く */
    private function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $value);
    }

    /** 全角英数字・記号・スペースを半角にする（mb_convert_kana 'as'） */
    private function toHalfWidth(string $value): string
    {
        return mb_convert_kana($value, 'as', 'UTF-8');
    }

    private function parseInt(string $value, int $max): ?int
    {
        if (preg_match('/^[0-9]+$/', $value) !== 1 || strlen(ltrim($value, '0')) > 7) {
            return null;
        }
        $int = (int) $value;

        return $int <= $max ? $int : null;
    }

    private function parseColor(string $value): ?string
    {
        if ($value === '') {
            return ProductColor::Gray->value;
        }
        foreach (ProductColor::cases() as $color) {
            if (strtolower($value) === $color->value || $value === $color->label()) {
                return $color->value;
            }
        }

        return null;
    }

    private function parseBool(string $value, bool $default): ?bool
    {
        if ($value === '') {
            return $default;
        }
        $lower = strtolower($value);

        return match (true) {
            in_array($lower, self::TRUE_WORDS, true) => true,
            in_array($lower, self::FALSE_WORDS, true) => false,
            default => null,
        };
    }
}
