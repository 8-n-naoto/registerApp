<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImportRequest;
use App\Services\ProductOptionImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

class ProductOptionImportController extends Controller
{
    /** #108 POST /products/options/import（06 §7.10）。入力は #20 と同じ */
    public function store(ProductImportRequest $request, ProductOptionImporter $importer): JsonResponse
    {
        $file = $request->file('file');
        $bytes = $file instanceof UploadedFile ? (string) $file->get() : '';

        return response()->json($importer->run($bytes, $request->dryRun()));
    }

    /** #109 GET /products/options/import-template（06 §7.11）。UTF-8・BOM 付き・CRLF の CSV */
    public function template(ProductOptionImporter $importer): Response
    {
        return response($importer->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="options_template.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
