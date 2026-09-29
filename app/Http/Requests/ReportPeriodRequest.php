<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** 06 §5.2・§5.3 の期間：from・to は必須、from ≤ to、両端を含めて 366 日以内 */
class ReportPeriodRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['from' => '開始日', 'to' => '終了日'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $days = (int) CarbonImmutable::parse($this->fromDate())->diffInDays(CarbonImmutable::parse($this->toDate())) + 1;
                if ($days > self::MAX_DAYS) {
                    $validator->errors()->add('to', '期間は '.self::MAX_DAYS.' 日以内で指定してください');
                }
            },
        ];
    }

    public function fromDate(): string
    {
        return $this->string('from')->value();
    }

    public function toDate(): string
    {
        return $this->string('to')->value();
    }
}
