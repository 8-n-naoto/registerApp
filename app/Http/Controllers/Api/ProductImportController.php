<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImportRequest;
use App\Services\ProductImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class ProductImportController extends Controller
{
    /** #20 POST /products/import（06 §7.7・07 §10） */
    public function store(ProductImportRequest $request, ProductImporter $importer): JsonResponse
    {
        $file = $request->file('file');
        $bytes = $file instanceof UploadedFile ? (string) $file->get() : '';

        return response()->json($importer->run($bytes, $request->dryRun()));
    }
}
