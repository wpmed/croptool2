<?php

namespace CropTool\File;

use pastuhov\Command\Command;

class GifFile extends File implements FileInterface
{
    public function crop($srcPath, $destPath, $method, $coords, $rotation, $brightness, $contrast)
    {
        $dim = $coords['width'] . 'x' . $coords['height'] . '+' . $coords['x'] .'+' . $coords['y'] . '!';

        $rotate = $rotation ? '-rotate ' . intval($rotation) . ' +repage' : '';

        Command::exec('convert {src} ' . $rotate . ' -crop {dim} -channel RGB -brightness-contrast {bc} {dest}', [
            'src' => $srcPath,
            'dest' => $destPath,
            'dim' => $dim,
            'bc' => $brightness . 'x' . $contrast,
        ]);
    }
}
