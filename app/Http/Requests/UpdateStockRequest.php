<?php

namespace App\Http\Requests;

use App\Services\StockService;
use Illuminate\Foundation\Http\FormRequest;

/** 06 §7.5 PATCH /products/{id}/stock */
class UpdateStockRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $max = StockService::MAX_QTY;
        $min = $this->input('mode') === 'add' ? -$max : 0;

        return [
            'mode' => ['required', 'string', 'in:set,add'],
            'value' => ['required', 'integer', "min:{$min}", "max:{$max}"],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['mode' => '変更の方法', 'value' => '在庫数'];
    }
}
