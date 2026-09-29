<?php

namespace CropTool\File;

use pastuhov\Command\Command;

class PdfFile extends File implements FileInterface
{
    protected $multipage = true;

    protected $supportedMimeTypes = [
        'application/pdf' => '.pdf',
    ];

    public function fetchPage($pageno = 0)
    {
        if ($pageno == 0) {
            throw new \RuntimeException('A "page" parameter must be specified.');
        }

        $this->fetch();

        if ($this->exists($pageno)) {
            return;
        }

        $pdfFile = $this->getAbsolutePath();
        $jpgFile = $this->getAbsolutePathForPage($pageno);

        // The binary path is part of the template, so it has to be quoted here:
        // exec() goes through a shell and "C:\Program Files\..." breaks on the space.
        Command::exec(escapeshellarg($this->pathToGs) . ' -sDEVICE=jpeg -dNOPAUSE -dBATCH -dSAFER -dFirstPage={page} -dLastPage={page} -r300 -dUseCropBox -sOutputFile={dest} {src}', [
            'page' => $pageno,
            'src' => $pdfFile,
            'dest' => $jpgFile,
        ]);

        $this->logMsg('Extracted page ' . $pageno);

        return $jpgFile;
    }

    public function overrideResultExtension() {
        return 'jpg';
    }
}
