<?php

namespace App\Support;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code as an SVG data: URI, rendered on the server (bacon/bacon-qr-code), so a page shows
 * it with a plain <img> and never outputs raw markup. Used by the ordering QR (T3-7).
 */
final class QrCode
{
    /** Pixel size of the SVG's viewBox; the page scales it to fit. */
    private const SIZE = 320;

    /** Quiet zone around the code, in modules; scanners need it. */
    private const MARGIN = 4;

    /**
     * Black on white whatever the colour scheme: dark mode must not invert what a camera reads.
     */
    public static function dataUri(string $content): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(self::SIZE, self::MARGIN, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
            new SvgImageBackEnd,
        )))->writeString($content);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
