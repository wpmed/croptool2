<?php

use CropTool\File\SvgFile;
use PHPUnit\Framework\TestCase;

class SvgFileRotationTest extends TestCase
{
    /**
     * Apply "translate(tx ty) rotate(a)" the way a renderer would.
     */
    private function mapPoint(string $transform, float $x, float $y): array
    {
        preg_match('/translate\(([-\d.]+) ([-\d.]+)\) rotate\(([-\d.]+)\)/', $transform, $m);
        $rad = deg2rad((float)$m[3]);

        return [
            cos($rad) * $x - sin($rad) * $y + (float)$m[1],
            sin($rad) * $x + cos($rad) * $y + (float)$m[2],
        ];
    }

    public function testQuarterTurnMovesTheBottomLeftCornerToTheTopLeft()
    {
        $metadata = ['width' => 100, 'height' => 100, 'viewBox' => [0.0, 0.0, 100.0, 100.0]];
        // The left half of the canvas after rotating 90 degrees clockwise
        $coords = ['x' => 0, 'y' => 0, 'width' => 50, 'height' => 100];

        list($viewBox, $transform) = SvgFile::rotatedGeometry($metadata, $coords, 90);

        $this->assertEquals('0 0 50 100', $viewBox);
        $this->assertEqualsWithDelta([0.0, 0.0], $this->mapPoint($transform, 0, 100), 0.001);
        // and the corner that ended up on the right is outside the new viewBox
        $this->assertEqualsWithDelta([100.0, 0.0], $this->mapPoint($transform, 0, 0), 0.001);
    }

    public function test180DegreeRotationMirrorsBothAxes()
    {
        $metadata = ['width' => 400, 'height' => 300, 'viewBox' => [0.0, 0.0, 400.0, 300.0]];
        $coords = ['x' => 0, 'y' => 0, 'width' => 400, 'height' => 300];

        list($viewBox, $transform) = SvgFile::rotatedGeometry($metadata, $coords, 180);

        $this->assertEquals('0 0 400 300', $viewBox);
        $this->assertEqualsWithDelta([400.0, 300.0], $this->mapPoint($transform, 0, 0), 0.001);
        $this->assertEqualsWithDelta([0.0, 0.0], $this->mapPoint($transform, 400, 300), 0.001);
    }

    public function testAStraightenKeepsTheCentreOfTheDrawingInTheCentre()
    {
        $metadata = ['width' => 100, 'height' => 100, 'viewBox' => [0.0, 0.0, 100.0, 100.0]];

        // A crop of the whole rotated canvas at a small angle
        $rad = deg2rad(0.85);
        $canvas = 100 * (cos($rad) + sin($rad));
        $coords = ['x' => 0, 'y' => 0, 'width' => $canvas, 'height' => $canvas];

        list($viewBox, $transform) = SvgFile::rotatedGeometry($metadata, $coords, 0.85);

        $this->assertEqualsWithDelta([$canvas / 2, $canvas / 2], $this->mapPoint($transform, 50, 50), 0.001);
        $this->assertStringStartsWith('0 0 ' . round($canvas, 4), $viewBox);
    }

    public function testAViewBoxOffsetIsTakenIntoAccount()
    {
        // viewBox -50 -50 400 200 with a 200x100 pixel canvas: 2 units per pixel
        $metadata = ['width' => 200, 'height' => 100, 'viewBox' => [-50.0, -50.0, 400.0, 200.0]];
        $coords = ['x' => 0, 'y' => 0, 'width' => 200, 'height' => 100];

        list($viewBox, $transform) = SvgFile::rotatedGeometry($metadata, $coords, 180);

        $this->assertEquals('0 0 400 200', $viewBox);
        // The top left of the drawing (-50,-50) ends up at the bottom right of the crop
        $this->assertEqualsWithDelta([400.0, 200.0], $this->mapPoint($transform, -50, -50), 0.001);
    }
}
