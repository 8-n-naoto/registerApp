<?php

namespace App\Http\Requests;

use App\Services\SalesExport;
use Illuminate\Validation\Rule;

/** 06 §5.3 GET /reports/export?type=&from=&to= */
class ReportExportRequest extends ReportPeriodRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(SalesExport::TYPES)],
            ...parent::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['type' => '種類', ...parent::attributes()];
    }

    public function type(): string
    {
        return $this->string('type')->value();
    }
}
