<?php

namespace App\Http\Requests;

use App\Enums\PollingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 12 §5.13 PUT /settings/orders。時間帯は schedule なら 1〜3 件必須、それ以外は省略可（省略すると保存値のまま）。
 * 各時間帯は HH:MM（00:00〜23:59）で start ≠ end。start > end は日付をまたぐ。重なりは許す（和集合で判定）
 */
class OrderSettingsRequest extends FormRequest
{
    private const HHMM = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $schedule = $this->input('polling_mode') === PollingMode::Schedule->value;

        return [
            'customer_order_enabled' => ['required', 'boolean'],
            'customer_order_approval' => ['required', 'boolean'],
            'customer_session_minutes' => ['required', 'integer', 'min:30', 'max:720'],
            'polling_mode' => ['required', Rule::enum(PollingMode::class)],
            'polling_windows' => [$schedule ? 'required' : 'sometimes', 'array', 'min:'.($schedule ? 1 : 0), 'max:3'],
            'polling_windows.*' => ['required', 'array:start,end'],
            'polling_windows.*.start' => ['required', 'string', 'regex:'.self::HHMM],
            'polling_windows.*.end' => ['required', 'string', 'regex:'.self::HHMM, 'different:polling_windows.*.start'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'customer_order_enabled' => 'お客さんの注文',
            'customer_order_approval' => '注文の確認',
            'customer_session_minutes' => '受付時間',
            'polling_mode' => '自動更新',
            'polling_windows' => '自動更新の時間帯',
            'polling_windows.*.start' => '開始',
            'polling_windows.*.end' => '終了',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'polling_windows.*.start.regex' => '時刻は 00:00〜23:59 で入力してください',
            'polling_windows.*.end.regex' => '時刻は 00:00〜23:59 で入力してください',
            'polling_windows.*.end.different' => '開始と終了は別の時刻にしてください',
        ];
    }

    /**
     * @return array{
     *     customer_order_enabled: bool,
     *     customer_order_approval: bool,
     *     customer_session_minutes: int,
     *     polling_mode: PollingMode,
     *     polling_windows?: list<array{start: string, end: string}>,
     * }
     */
    public function settings(): array
    {
        $data = [
            'customer_order_enabled' => $this->boolean('customer_order_enabled'),
            'customer_order_approval' => $this->boolean('customer_order_approval'),
            'customer_session_minutes' => $this->integer('customer_session_minutes'),
            'polling_mode' => PollingMode::from($this->string('polling_mode')->toString()),
        ];
        if ($this->has('polling_windows')) {
            /** @var list<array{start: string, end: string}> $windows */
            $windows = array_values((array) $this->validated('polling_windows'));
            $data['polling_windows'] = array_map(fn (array $w): array => ['start' => $w['start'], 'end' => $w['end']], $windows);
        }

        return $data;
    }
}
