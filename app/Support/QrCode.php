<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR codes as inline SVG (no GD/Imagick), sharp when printed.
 */
final class QrCode
{
    public static function svg(string $text, int $size = 240): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd)))->writeString($text);

        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg); // inline in HTML: drop the XML prolog
    }
}
