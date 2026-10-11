<?php

use CropTool\File\SvgFile;
use PHPUnit\Framework\TestCase;

class SvgFileRotationTest extends TestCase
{
    /**
     * Apply "translate(tx ty) scale(sx sy) rotate(a)" the way a renderer would,
     * i.e. right to left: rotate, then mirror, then shift.
     */
    private function mapPoint(string $transform, float $x, float $y): array
    {
        preg_match('/translate\(([-\d.]+) ([-\d.]+)\)/', $transform, $t);
        $sx = 1.0;
        $sy = 1.0;
        if (preg_match('/scale\((-?[\d.]+) (-?[\d.]+)\)/', $transform, $s)) {
            $sx = (float)$s[1];
            $sy = (float)$s[2];
        }
        $rad = 0.0;
        if (preg_match('/rotate\(([-\d.]+)\)/', $transform, $m)) {
            $rad = deg2rad((float)$m[1]);
        }

        $rotatedX = cos($rad) * $x - sin($rad) * $y;
        $rotatedY = sin($rad) * $x + cos($rad) * $y;

        return [$sx * $rotatedX + (float)$t[1], $sy * $rotatedY + (float)$t[2]];
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

    public function testAHorizontalMirrorSwapsLeftAndRight()
    {
        $metadata = ['width' => 100, 'height' => 100, 'viewBox' => [0.0, 0.0, 100.0, 100.0]];
        $coords = ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100];

        list($viewBox, $transform) = SvgFile::rotatedGeometry($metadata, $coords, 0, true, false);

        $this->assertEquals('0 0 100 100', $viewBox);
        $this->assertEquals('translate(100 0) scale(-1 1)', $transform);
        // The top left corner of the drawing lands top right, and nothing moves vertically
        $this->assertEqualsWithDelta([100.0, 0.0], $this->mapPoint($transform, 0, 0), 0.001);
        $this->assertEqualsWithDelta([0.0, 100.0], $this->mapPoint($transform, 100, 100), 0.001);
    }

    public function testAVerticalMirrorSwapsTopAndBottom()
    {
        $metadata = ['width' => 100, 'height' => 100, 'viewBox' => [0.0, 0.0, 100.0, 100.0]];
        $coords = ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100];

        list($viewBox, $transform) = SvgFile::rotatedGeometry($metadata, $coords, 0, false, true);

        $this->assertEquals('translate(0 100) scale(1 -1)', $transform);
        $this->assertEqualsWithDelta([0.0, 100.0], $this->mapPoint($transform, 0, 0), 0.001);
        $this->assertEqualsWithDelta([100.0, 0.0], $this->mapPoint($transform, 100, 100), 0.001);
    }

    public function testMirroringBothWaysIsAHalfTurn()
    {
        $metadata = ['width' => 100, 'height' => 100, 'viewBox' => [0.0, 0.0, 100.0, 100.0]];
        $coords = ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100];

        list($viewBox, $transform) = SvgFile::rotatedGeometry($metadata, $coords, 0, true, true);

        $this->assertEquals('translate(100 100) scale(-1 -1)', $transform);
        $this->assertEqualsWithDelta([100.0, 100.0], $this->mapPoint($transform, 0, 0), 0.001);
    }

    public function testAMirrorIsAppliedAfterTheRotation()
    {
        $metadata = ['width' => 100, 'height' => 100, 'viewBox' => [0.0, 0.0, 100.0, 100.0]];
        $coords = ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100];

        list($viewBox, $transform) = SvgFile::rotatedGeometry($metadata, $coords, 90, true, false);

        $this->assertEquals('translate(0 0) scale(-1 1) rotate(90)', $transform);
        // Turned a quarter clockwise, the bottom left corner would land top left;
        // mirroring afterwards puts it top right.
        $this->assertEqualsWithDelta([0.0, 0.0], $this->mapPoint($transform, 0, 0), 0.001);
        $this->assertEqualsWithDelta([100.0, 0.0], $this->mapPoint($transform, 0, 100), 0.001);
    }
}
