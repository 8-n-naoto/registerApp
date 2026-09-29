<?php

namespace App\Http\Requests;

use App\Services\ClosingService;
use Illuminate\Foundation\Http\FormRequest;

/** 06 §6.2 PUT /closings/{date} */
class ClosingRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $max = ClosingService::MAX_AMOUNT;

        return [
            'float_amount' => ['required', 'integer', 'min:0', "max:{$max}"],
            'counted_cash' => ['required', 'integer', 'min:0', "max:{$max}"],
            'memo' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['float_amount' => 'つり銭準備金', 'counted_cash' => '実際の現金', 'memo' => 'メモ'];
    }

    /** @return array{float_amount: int, counted_cash: int, memo: string|null} */
    public function closing(): array
    {
        $memo = $this->input('memo');

        return [
            'float_amount' => $this->integer('float_amount'),
            'counted_cash' => $this->integer('counted_cash'),
            'memo' => is_string($memo) && $memo !== '' ? $memo : null,
        ];
    }
}
