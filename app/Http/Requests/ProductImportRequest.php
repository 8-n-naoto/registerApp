<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 06 §7.7 POST /products/import（multipart） */
class ProductImportRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:csv', 'max:1024'],
            // multipart では文字列で届くため true / false も受け付ける
            'dry_run' => ['sometimes', 'in:1,0,true,false'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['file' => 'ファイル', 'dry_run' => '確認のみ'];
    }

    /** 既定は true（確認のみ） */
    public function dryRun(): bool
    {
        return $this->has('dry_run') ? $this->boolean('dry_run') : true;
    }
}
