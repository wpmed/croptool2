<?php

namespace CropTool\File;

use pastuhov\Command\Command;

class TiffFile extends File implements FileInterface
{
    protected $multipage = true;

    protected $supportedMimeTypes = [
        'image/tiff' => '.tiff',
    ];

    protected function pageSuffix($pageno=0)
    {
        return '.page' . $pageno . '.tiff';
    }

    public function fetchPage($pageno = 0)
    {
        if ($pageno == 0) {
            throw new \RuntimeException('A "page" parameter must be specified.');
        }

        $this->fetch();

        $sourceFile = $this->getAbsolutePath();
        $destFile = $this->getAbsolutePathForPage($pageno);

        if ($this->exists($pageno)) {
            return $destFile;
        }

        // Extract page as tiff. -auto-orient physically applies any EXIF
        // orientation tag (the same rotation MediaWiki applies when rendering
        // the file), so the page pixels are stored upright and CropTool's
        // orientation-unaware TIFF path shows and crops them correctly.
        Command::exec($this->pathToConvert . ' {src} -auto-orient {dest}', [
            'src' => $sourceFile . '[' . ($pageno - 1) . ']',
            'dest' => $destFile,
        ]);

        $this->logMsg('Extracted page ' . $pageno);

        return $destFile;
    }
}
