<?php

namespace App\Support;

use App\Models\OrderTable;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * テーブルの QR（12 §5.12・Q10）。URL は APP_URL（本番はフォルダを含む）から組み立て、直書きしない。
 * APP_URL がフォルダを含まない場合だけ APP_PATH_PREFIX（config('app.base_path')）を足す
 */
final class TableQrCode
{
    public static function url(OrderTable $table): string
    {
        $root = rtrim((string) config('app.url'), '/');
        $base = (string) config('app.base_path');
        if ($base !== '' && ! str_ends_with($root, $base)) {
            $root .= $base;
        }

        return $root.'/t/'.$table->plainToken();
    }

    /** 背景は透明（画面・印刷の側で白地に置く）。誤り訂正は M、周囲の余白は 4 セル */
    public static function svg(string $url): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'drawLightModules' => false,
            'connectPaths' => true,
            'svgAddXmlHeader' => false,
        ]);

        return (string) (new QRCode($options))->render($url);
    }
}
