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

    /** @var int[]|null Scene indexes of the real pages, or null when unknown. */
    protected $realPageScenes;

    /**
     * Number of real pages, or null when the original is not available yet
     * (callers then fall back to the page count reported by MediaWiki).
     */
    public function getPageCount()
    {
        $scenes = $this->realPageScenes();
        return $scenes === null ? null : count($scenes);
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

        $scene = $this->sceneIndexForPage($pageno);
        if ($scene === null) {
            $count = $this->getPageCount();
            throw new \RuntimeException(sprintf(
                'Page %d does not exist (this file has %s).',
                $pageno,
                $count === null ? 'no extractable pages' : ($count === 1 ? '1 page' : $count . ' pages')
            ));
        }

        // Extract page as tiff. -auto-orient physically applies any EXIF
        // orientation tag (the same rotation MediaWiki applies when rendering
        // the file), so the page pixels are stored upright and CropTool's
        // orientation-unaware TIFF path shows and crops them correctly.
        Command::exec($this->pathToConvert . ' {src} -auto-orient {dest}', [
            'src' => $sourceFile . '[' . $scene . ']',
            'dest' => $destFile,
        ]);

        $this->logMsg('Extracted page ' . $pageno);

        return $destFile;
    }

    /**
     * Scene index (ImageMagick index) of the pageno-th real page, or null.
     */
    protected function sceneIndexForPage($pageno)
    {
        $scenes = $this->realPageScenes();
        if ($scenes !== null && isset($scenes[$pageno - 1])) {
            return $scenes[$pageno - 1];
        }
        return null;
    }

    /**
     * Scene indexes of the real pages (in file order).
     *
     * A TIFF often contains extra low-resolution images (scanner previews /
     * embedded thumbnails) besides its actual pages. These are flagged with
     * the NewSubfileType tag (IFD 254) bit 0 ("reduced-resolution image of
     * another image"); they are not pages and must be skipped so that page
     * numbers line up with what a viewer actually shows.
     *
     * Returns null when the original has not been downloaded yet.
     *
     * @return int[]|null
     */
    protected function realPageScenes()
    {
        if ($this->realPageScenes === null) {
            $sourceFile = $this->getAbsolutePath();
            if (!file_exists($sourceFile)) {
                return null;
            }
            $this->realPageScenes = self::realPageSceneIndexesInFile($sourceFile);
        }
        return $this->realPageScenes;
    }

    /**
     * Parse the TIFF IFD directory chain and return the scene indexes of the
     * real pages, skipping reduced-resolution subfiles. Supports classic TIFF
     * and BigTIFF in both byte orders.
     *
     * @return int[]
     */
    protected static function realPageSceneIndexesInFile($path)
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return [];
        }

        $header = fread($fp, 16);
        $bo = substr($header, 0, 2);
        if ($bo === 'II') {
            $u16 = 'v';
            $u32 = 'V';
            $u64 = 'P';
        } elseif ($bo === 'MM') {
            $u16 = 'n';
            $u32 = 'N';
            $u64 = 'J';
        } else {
            fclose($fp);
            return [];
        }

        $magic = isset($header[2]) ? unpack($u16, substr($header, 2, 2))[1] : 0;
        if ($magic === 42) {
            // Classic TIFF: 4-byte offsets.
            $ifdOffset = unpack($u32, substr($header, 4, 4))[1];
            $countWidth = 2;
            $entrySize = 12;
            $offsetWidth = 4;
            $countCode = $u16;
        } elseif ($magic === 43) {
            // BigTIFF: header byte 8-15 is the first IFD offset (8-byte).
            $ifdOffset = (int)unpack($u64, substr($header, 8, 8))[1];
            $countWidth = 8;
            $entrySize = 20;
            $offsetWidth = 8;
            $countCode = $u64;
        } else {
            fclose($fp);
            return [];
        }

        $scenes = [];
        $scene = 0;
        $guard = 0;
        while ($ifdOffset > 0 && $guard++ < 100000) {
            if (fseek($fp, $ifdOffset) !== 0) {
                break;
            }
            $countRaw = fread($fp, $countWidth);
            if (strlen($countRaw) < $countWidth) {
                break;
            }
            $count = unpack($countCode, $countRaw)[1];
            if ($count < 1 || $count > 65536) {
                break;
            }

            $newSubfileType = 0;
            for ($i = 0; $i < $count; $i++) {
                $entry = fread($fp, $entrySize);
                if (strlen($entry) < $entrySize) {
                    break 2;
                }
                $tag = unpack($u16, substr($entry, 0, 2))[1];
                if ($tag === 254) {
                    // NewSubfileType value (inline in the entry).
                    $valueBytes = substr($entry, $offsetWidth === 4 ? 8 : 12, $offsetWidth);
                    if ($offsetWidth === 4) {
                        $newSubfileType = unpack($u32, $valueBytes)[1];
                    } else {
                        $newSubfileType = (int)unpack($u64, $valueBytes)[1];
                    }
                }
            }

            $nextRaw = fread($fp, $offsetWidth);
            if (strlen($nextRaw) < $offsetWidth) {
                break;
            }
            $ifdOffset = $offsetWidth === 4 ? unpack($u32, $nextRaw)[1] : (int)unpack($u64, $nextRaw)[1];

            // Bit 0 = reduced-resolution image of another image (thumbnail).
            if (($newSubfileType & 1) === 0) {
                $scenes[] = $scene;
            }
            $scene++;
        }

        fclose($fp);
        return $scenes;
    }

    /**
     * Write cropped TIFFs compressed. Source TIFFs are stored uncompressed,
     * so without this a crop of a full-size scan is another multi-hundred-MB
     * file that is slow and awkward to upload; ZIP/deflate is lossless.
     */
    static public function saveImage($im, $destPath, $srcPath)
    {
        if (strtolower(pathinfo($destPath, PATHINFO_EXTENSION)) === 'tiff') {
            $im->setImageCompression(\Imagick::COMPRESSION_ZIP);
        }
        return $im->writeImage($destPath);
    }
}
