<?php

namespace App\Support;

use App\Models\Order;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\URL;

/**
 * T3-1: the pickup QR on a Ready order's page, and the link inside it.
 *
 * The link opens the staff-only confirmation page (route barista.pickup). It's signed so it
 * can't be guessed, with a relative signature (the path and query only, not the host): an
 * order page opened on tea-alley.test or on the demo tunnel gives a link that works on both.
 * No expiry: the signature only proves we made the link; what allows a pickup is a staff login
 * and the order being Ready, checked on the confirmation page.
 */
final class PickupQr
{
    /** Pixel size of the SVG's viewBox; the page scales it to fit. */
    private const SIZE = 320;

    /** Quiet zone around the code, in modules; scanners need it. */
    private const MARGIN = 4;

    /**
     * The signed path, e.g. /barista/pickup/TA-123456?signature=..., the same on every render.
     */
    public static function path(Order $order): string
    {
        return URL::signedRoute('barista.pickup', ['order' => $order->order_number], absolute: false);
    }

    /**
     * The full URL a phone camera opens: the signed path on this request's host.
     */
    public static function url(Order $order): string
    {
        return url(self::path($order));
    }

    /**
     * The QR as an SVG data: URI for an <img>, so the page never outputs raw markup. Black on
     * white whatever the colour scheme: dark mode must not invert what the scanner reads.
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
