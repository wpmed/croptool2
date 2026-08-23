<?php

use CropTool\File\SvgFile;
use PHPUnit\Framework\TestCase;

class SvgFileTest extends TestCase
{
    private function writeTempSvg(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'croptool-svg-');
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>' . $body);
        return $path;
    }

    public function testPercentageDimensionsResolveAgainstViewBox()
    {
        $svg = $this->writeTempSvg(
            '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 500 500">'
            . '<rect x="10" y="10" width="200" height="100" fill="red"/></svg>'
        );

        $meta = SvgFile::readMetadata($svg);

        $this->assertEquals(500, $meta['width']);
        $this->assertEquals(500, $meta['height']);
        $this->assertEquals([0.0, 0.0, 500.0, 500.0], $meta['viewBox']);

        unlink($svg);
    }

    public function testPercentageWidthWithAutoHeightUsesViewBoxAspect()
    {
        $svg = $this->writeTempSvg(
            '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="auto" viewBox="0 0 400 200">'
            . '<rect x="0" y="0" width="100" height="100" fill="blue"/></svg>'
        );

        $meta = SvgFile::readMetadata($svg);

        $this->assertEquals(400, $meta['width']);
        $this->assertEquals(200, $meta['height']);

        unlink($svg);
    }

    public function testPixelsAreUnchanged()
    {
        $svg = $this->writeTempSvg(
            '<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1376" viewBox="-305 -516 610 820">'
            . '<rect x="0" y="0" width="100" height="100" fill="green"/></svg>'
        );

        $meta = SvgFile::readMetadata($svg);

        $this->assertEquals(1024, $meta['width']);
        $this->assertEquals(1376, $meta['height']);
        $this->assertEquals([-305.0, -516.0, 610.0, 820.0], $meta['viewBox']);

        unlink($svg);
    }

    public function testNoDimensionsFallsBackToSpecDefaults()
    {
        $svg = $this->writeTempSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="100" height="100" fill="black"/></svg>'
        );

        $meta = SvgFile::readMetadata($svg);

        $this->assertEquals(300, $meta['width']);
        $this->assertEquals(150, $meta['height']);

        unlink($svg);
    }
}
