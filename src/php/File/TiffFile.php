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

    /** @var array|null Cached IFD info: ['pages'=>int[], 'reduced'=>array{scene:int,w:int,h:int}[]]. */
    protected $ifdInfo;

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
        $info = $this->ifdInfo();
        return $info === null ? null : $info['pages'];
    }

    /**
     * The embedded reduced-resolution subfiles (scanner previews/thumbnails).
     *
     * @return array[] Each element: ['scene'=>int, 'w'=>int, 'h'=>int].
     */
    public function reducedSubfiles()
    {
        $info = $this->ifdInfo();
        return $info === null ? [] : $info['reduced'];
    }

    /**
     * Parse (and cache) the IFD chain of the downloaded original.
     *
     * @return array|null ['pages'=>int[], 'reduced'=>array[]], or null when the
     *                    original is not available yet.
     */
    protected function ifdInfo()
    {
        if ($this->ifdInfo === null) {
            $sourceFile = $this->getAbsolutePath();
            if (!file_exists($sourceFile)) {
                return null;
            }
            $this->ifdInfo = self::parseIfdChain($sourceFile);
        }
        return $this->ifdInfo;
    }

    /**
     * Parse the TIFF IFD directory chain. Supports classic TIFF and BigTIFF in
     * both byte orders.
     *
     * Returns the scene indexes of the real pages (NewSubfileType bit 0 unset)
     * and, for the reduced-resolution subfiles, their scene index and pixel
     * dimensions so callers can pick a preview size.
     *
     * @return array ['pages'=>int[], 'reduced'=>array{scene:int,w:int,h:int}[]]
     */
    protected static function parseIfdChain($path)
    {
        $result = ['pages' => [], 'reduced' => []];
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return $result;
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
            return $result;
        }

        $magic = isset($header[2]) ? unpack($u16, substr($header, 2, 2))[1] : 0;
        if ($magic === 42) {
            // Classic TIFF: 4-byte offsets.
            $ifdOffset = unpack($u32, substr($header, 4, 4))[1];
            $countWidth = 2;
            $entrySize = 12;
            $offsetWidth = 4;
            $countCode = $u16;
            $valuePos = 8;
        } elseif ($magic === 43) {
            // BigTIFF: header byte 8-15 is the first IFD offset (8-byte).
            $ifdOffset = (int)unpack($u64, substr($header, 8, 8))[1];
            $countWidth = 8;
            $entrySize = 20;
            $offsetWidth = 8;
            $countCode = $u64;
            $valuePos = 12;
        } else {
            fclose($fp);
            return $result;
        }

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
            $width = null;
            $height = null;
            for ($i = 0; $i < $count; $i++) {
                $entry = fread($fp, $entrySize);
                if (strlen($entry) < $entrySize) {
                    break 2;
                }
                $tag = unpack($u16, substr($entry, 0, 2))[1];
                $type = unpack($u16, substr($entry, 2, 2))[1];
                if ($tag === 254) {
                    // NewSubfileType is a LONG whose value fits inline.
                    if ($offsetWidth === 4) {
                        $newSubfileType = unpack($u32, substr($entry, 8, 4))[1];
                    } else {
                        $newSubfileType = (int)unpack($u64, substr($entry, 12, 8))[1];
                    }
                } elseif ($tag === 256 || $tag === 257) {
                    // ImageWidth/ImageLength: SHORT, LONG or (BigTIFF) LONG8.
                    $v = null;
                    if ($type === 3) {
                        $v = unpack($u16, substr($entry, $valuePos, 2))[1];
                    } elseif ($type === 4) {
                        $v = unpack($u32, substr($entry, $valuePos, 4))[1];
                    } elseif ($type === 16) {
                        $v = (int)unpack($u64, substr($entry, $valuePos, 8))[1];
                    }
                    if ($v !== null) {
                        if ($tag === 256) {
                            $width = $v;
                        } else {
                            $height = $v;
                        }
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
                $result['pages'][] = $scene;
            } elseif ($width && $height) {
                $result['reduced'][] = ['scene' => $scene, 'w' => $width, 'h' => $height];
            }
            $scene++;
        }

        fclose($fp);
        return $result;
    }

    /**
     * Give a cropped page TIFF an embedded thumbnail subfile when the source
     * had one.
     *
     * Scanned TIFFs commonly embed a low-resolution preview (NewSubfileType
     * bit 0). MediaWiki renders thumbnails of very large TIFFs from such a
     * subfile rather than decoding the main scan, so a crop that is still
     * huge but no longer carries the preview may not get thumbnails at all.
     * Rewrites $cropPath as a two-IFD TIFF whose second IFD is a
     * reduced-resolution version of the cropped image, sized like the largest
     * preview in the source. No-op when the source has no embedded preview.
     */
    public function embedThumbnailIntoCrop($cropPath)
    {
        $reduced = $this->reducedSubfiles();
        if (empty($reduced) || !file_exists($cropPath)) {
            return;
        }

        // The most useful preview is the largest reduced-resolution subfile.
        usort($reduced, function ($a, $b) {
            return max($b['w'], $b['h']) - max($a['w'], $a['h']);
        });
        $maxDim = max($reduced[0]['w'], $reduced[0]['h']);
        if ($maxDim < 64) {
            $maxDim = 64;
        }

        $thumbPath = $cropPath . '.thumb.tmp.tiff';
        $combinedPath = $cropPath . '.2ifd.tmp.tiff';
        try {
            // Downscale the *cropped* image (already upright) so the preview
            // always depicts the crop; avoids re-orienting/cropping the source
            // preview subfile whose own orientation is not guaranteed.
            Command::exec($this->pathToConvert . ' {src} -resize {size} -depth 8 -strip {thumb}', [
                'src' => $cropPath,
                'size' => $maxDim . 'x' . $maxDim . '>',
                'thumb' => $thumbPath,
            ]);

            // Two-IFD TIFF: IFD0 = the crop, IFD1 = the preview.
            Command::exec($this->pathToConvert . ' {a} {b} -compress zip {combined}', [
                'a' => $cropPath,
                'b' => $thumbPath,
                'combined' => $combinedPath,
            ]);

            // ImageMagick flags every frame as a page (NewSubfileType=2). Mark
            // IFD0 as the full image and IFD1 as reduced-resolution so
            // thumbnail renderers treat the second frame as a preview again.
            self::setSubfileTypes($combinedPath, [0, 1]);

            // Windows rename() can race a scanner/AV lock on the freshly
            // written multi-hundred-MB file; fall back to copy (overwrites)
            // and let the finally block drop the temporary.
            if (!@rename($combinedPath, $cropPath) && !@copy($combinedPath, $cropPath)) {
                throw new \RuntimeException('Could not replace ' . $cropPath . ' with the two-IFD crop.');
            }
        } finally {
            foreach ([$thumbPath, $combinedPath] as $tmp) {
                if (file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * Rewrite the NewSubfileType (tag 254) value of the first IFDs in place.
     *
     * @param int[] $types NewSubfileType per IFD (in file order).
     */
    protected static function setSubfileTypes($path, array $types)
    {
        $fp = @fopen($path, 'r+b');
        if ($fp === false) {
            return;
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
            return;
        }

        $magic = isset($header[2]) ? unpack($u16, substr($header, 2, 2))[1] : 0;
        if ($magic === 42) {
            $ifdOffset = unpack($u32, substr($header, 4, 4))[1];
            $countWidth = 2;
            $entrySize = 12;
            $offsetWidth = 4;
            $countCode = $u16;
        } elseif ($magic === 43) {
            $ifdOffset = (int)unpack($u64, substr($header, 8, 8))[1];
            $countWidth = 8;
            $entrySize = 20;
            $offsetWidth = 8;
            $countCode = $u64;
        } else {
            fclose($fp);
            return;
        }

        $ifdIndex = 0;
        $guard = 0;
        while ($ifdOffset > 0 && $guard++ < 100000 && $ifdIndex < count($types)) {
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

            $entryStart = $ifdOffset + $countWidth;
            for ($i = 0; $i < $count; $i++) {
                $entry = fread($fp, $entrySize);
                if (strlen($entry) < $entrySize) {
                    break 2;
                }
                $tag = unpack($u16, substr($entry, 0, 2))[1];
                if ($tag !== 254) {
                    continue;
                }
                $type = unpack($u16, substr($entry, 2, 2))[1];
                $valuePos = $entryStart + $i * $entrySize + ($offsetWidth === 4 ? 8 : 12);
                if ($type === 3) {
                    $bytes = pack($u16, $types[$ifdIndex]); // SHORT
                } elseif ($type === 4) {
                    $bytes = pack($u32, $types[$ifdIndex]); // LONG
                } else {
                    // LONG8 (BigTIFF).
                    $bytes = pack($u64, $types[$ifdIndex]);
                }
                if (fseek($fp, $valuePos) === 0) {
                    fwrite($fp, $bytes);
                }
                break;
            }

            // The entry loop above may have stopped early (after patching the
            // first NewSubfileType), so seek to the end of the entries before
            // reading the next-IFD pointer.
            if (fseek($fp, $entryStart + $count * $entrySize) !== 0) {
                break;
            }
            $nextRaw = fread($fp, $offsetWidth);
            if (strlen($nextRaw) < $offsetWidth) {
                break;
            }
            $ifdOffset = $offsetWidth === 4 ? unpack($u32, $nextRaw)[1] : (int)unpack($u64, $nextRaw)[1];
            $ifdIndex++;
        }

        fclose($fp);
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
