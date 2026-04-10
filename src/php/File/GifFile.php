<?php

namespace CropTool\File;

use pastuhov\Command\Command;

class GifFile extends File implements FileInterface
{
    public function crop($srcPath, $destPath, $method, $coords, $rotation, $brightness, $contrast, $saturation)
    {
        $dim = $coords['width'] . 'x' . $coords['height'] . '+' . $coords['x'] .'+' . $coords['y'] . '!';
        $rotate = $rotation ? '-rotate ' . intval($rotation) . ' +repage' : '';

        // Uses a color matrix instead of Imagick::modulateImage for saturation
        // to match the SVG feColorMatrix saturate preview used in the
        // frontend. HSL modulation computes luminance from min/max of RGB
        // channels, which discards color noise differently.
        //
        // https://www.w3.org/TR/filter-effects-1/#feColorMatrixElement
        $colorMatrix = $saturation != 0 ? '-color-matrix {cm}' : '';
        $s = 1.0 + $saturation / 100.0;

        Command::exec('convert {src} ' . $rotate . ' -crop {dim} -channel RGB -brightness-contrast {bc} ' . $colorMatrix . ' {dest}', [
            'src' => $srcPath,
            'dest' => $destPath,
            'dim' => $dim,
            'bc' => $brightness . 'x' . $contrast,
            'cm' => '3x3:'
                . (0.213 + 0.787 * $s) . ' ' . (0.715 - 0.715 * $s) . ' ' . (0.072 - 0.072 * $s) . ' '
                . (0.213 - 0.213 * $s) . ' ' . (0.715 + 0.285 * $s) . ' ' . (0.072 - 0.072 * $s) . ' '
                . (0.213 - 0.213 * $s) . ' ' . (0.715 - 0.715 * $s) . ' ' . (0.072 + 0.928 * $s),
        ]);
    }
}
