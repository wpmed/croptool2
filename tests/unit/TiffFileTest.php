<?php

use CropTool\File\TiffFile;
use PHPUnit\Framework\TestCase;

class TiffFileTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function tempFile(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'croptool-tiff-') . '.' . $extension;
        $this->tempFiles[] = $path;
        return $path;
    }

    private function solidImage(int $size): Imagick
    {
        $im = new Imagick();
        $im->newImage($size, $size, new ImagickPixel('#3366aa'), 'tiff');
        return $im;
    }

    /**
     * The TIFF coder reads the wand's compression, so the image-level
     * setImageCompression() is silently ignored and the crop is written
     * uncompressed - about 20% larger for a 16-bit scan.
     */
    public function testCroppedTiffIsWrittenWithZipCompression()
    {
        $dest = $this->tempFile('tiff');

        $im = $this->solidImage(400);
        TiffFile::saveImage($im, $dest, null);
        $im->destroy();

        $written = new Imagick($dest);
        try {
            $this->assertSame(Imagick::COMPRESSION_ZIP, $written->getImageCompression());
        } finally {
            $written->destroy();
        }
    }

    public function testZipCompressionActuallyShrinksTheFile()
    {
        $dest = $this->tempFile('tiff');

        $im = $this->solidImage(400);
        TiffFile::saveImage($im, $dest, null);
        $im->destroy();

        // A 400x400 RGB image is 480000 bytes flat; a solid colour zips far below that.
        $this->assertLessThan(400 * 400 * 3 / 4, filesize($dest));
    }

    public function testNonTiffOutputIsNotGivenTiffCompression()
    {
        $dest = $this->tempFile('jpg');

        $im = $this->solidImage(40);
        TiffFile::saveImage($im, $dest, null);
        $im->destroy();

        $this->assertFileExists($dest);
        $written = new Imagick($dest);
        try {
            $this->assertNotSame(Imagick::COMPRESSION_ZIP, $written->getImageCompression());
        } finally {
            $written->destroy();
        }
    }
}
