<?php

namespace App\Http\Requests;

use App\Enums\PriceMode;
use App\Enums\Rounding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 06 §8.2 PUT /settings/store */
class StoreSettingsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'price_mode' => ['required', 'string', Rule::enum(PriceMode::class)],
            'rounding' => ['required', 'string', Rule::enum(Rounding::class)],
            // 00:00〜11:59（05 §3.1）
            'day_cutoff_time' => ['required', 'string', 'regex:/^(0[0-9]|1[01]):[0-5][0-9]$/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => '店舗名', 'price_mode' => '価格の表示', 'rounding' => '端数処理', 'day_cutoff_time' => '締め時刻'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['day_cutoff_time.regex' => '締め時刻は 00:00〜11:59 で入力してください'];
    }

    /** @return array{name: string, price_mode: string, rounding: string, day_cutoff_time: string} */
    public function settings(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'price_mode' => $this->string('price_mode')->toString(),
            'rounding' => $this->string('rounding')->toString(),
            'day_cutoff_time' => $this->string('day_cutoff_time')->toString(),
        ];
    }
}
