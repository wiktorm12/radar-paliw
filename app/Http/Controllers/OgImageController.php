<?php

namespace App\Http\Controllers;

use App\Support\CpnPrices;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

class OgImageController extends Controller
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    public function programCpn()
    {
        $latest = CpnPrices::latest();
        $date = $latest['date'] ?? null;
        $path = storage_path('app/og/program-cpn-'.($date ?? 'empty').'.png');

        if (! $date || ! is_file($path)) {
            $png = $this->render($latest);

            if ($date) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $png);
                foreach (glob(dirname($path).'/program-cpn-*.png') ?: [] as $old) {
                    if ($old !== $path) {
                        File::delete($old);
                    }
                }
            }
        } else {
            $png = File::get($path);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function render(?array $latest): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($image, true);

        $dark = imagecolorallocate($image, 24, 24, 27);
        $brand = imagecolorallocate($image, 0xe3, 0x64, 0x00);
        $brandLight = imagecolorallocate($image, 0xf3, 0x82, 0x1d);
        $white = imagecolorallocate($image, 255, 255, 255);
        $zinc = imagecolorallocate($image, 82, 82, 91);
        $muted = imagecolorallocate($image, 161, 161, 170);
        $cream = imagecolorallocate($image, 0xff, 0xf7, 0xea);

        $regular = resource_path('fonts/LiberationSans-Regular.ttf');
        $bold = resource_path('fonts/LiberationSans-Bold.ttf');

        imagefill($image, 0, 0, $dark);
        imagefilledrectangle($image, 0, 0, self::WIDTH, 14, $brand);

        imagefilledellipse($image, 92, 92, 44, 44, $brandLight);
        imagefilledellipse($image, 92, 92, 20, 20, $dark);
        imagettftext($image, 30, 0, 130, 104, $white, $bold, 'Radar Paliw');
        imagettftext($image, 20, 0, 1130 - $this->textWidth(20, $regular, 'radarpaliw.pl'), 102, $muted, $regular, 'radarpaliw.pl');

        imagettftext($image, 54, 0, 70, 232, $white, $bold, 'Ceny maksymalne paliw');
        imagettftext($image, 54, 0, 70, 304, $brandLight, $bold, 'Program CPN');

        if ($latest) {
            $label = 'Obowiązują '.Carbon::parse($latest['date'])->format('j.m.Y');
            imagettftext($image, 26, 0, 70, 358, $muted, $regular, $label);

            $cardWidth = 330;
            $gap = 35;
            $x = 70;

            foreach ($latest['fuels'] as $fuel) {
                imagefilledrectangle($image, $x, 400, $x + $cardWidth, 570, $cream);
                imagefilledrectangle($image, $x, 400, $x + 8, 570, $brand);
                imagettftext($image, 24, 0, $x + 32, 448, $zinc, $regular, $fuel['label']);
                imagettftext($image, 58, 0, $x + 32, 530, $dark, $bold, CpnPrices::money($fuel['price']));
                $priceWidth = $this->textWidth(58, $bold, CpnPrices::money($fuel['price']));
                imagettftext($image, 24, 0, $x + 32 + $priceWidth + 12, 530, $zinc, $regular, 'zł/l');
                $x += $cardWidth + $gap;
            }
        } else {
            imagettftext($image, 26, 0, 70, 358, $muted, $regular, 'Codzienne ceny maksymalne, wykres i prognoza');
        }

        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();

        return $png;
    }

    private function textWidth(int $size, string $font, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : (int) abs($box[2] - $box[0]);
    }
}
