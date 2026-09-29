<?php

namespace CropTool\File;

use XMLReader;
use RuntimeException;

class SvgFile extends File implements FileInterface
{

    const NS_SVG = 'http://www.w3.org/2000/svg';

    protected $supportedMimeTypes = [
        'image/svg+xml' => '.svg',
    ];

    static private function convertUnit( $length, $viewportSize ) {
        // This is based on MediaWiki's SvgReader::scaleSVGUnit
        static $unitLength = [
            'px' => 1.0,
            'pt' => 1.333333,
            'pc' => 16.0,
            'mm' => 3.7795275,
            'q' => 0.944881,
            'cm' => 37.795275,
            'in' => 96.0,
            'em' => 16.0, // Browser default font size if unspecified
            'rem' => 16.0,
            'ch' => 8.0, // Spec says 1em if impossible to determine
            'ex' => 8.0, // Spec says 0.5em if impossible to determine
            '' => 1.0, // "User units" pixels by default
        ];
        $matches = [];
        if ( preg_match(
                '/^\s*([-+]?\d*(?:\.\d+|\d+)(?:[Ee][-+]?\d+)?)\s*' .
                '(rem|em|ex|px|pt|pc|cm|mm|in|ch|q|%)\s*$/i',
                $length,
                $matches
        ) ) {
                $length = (float)$matches[1];
                $unit = $matches[2];
                if ( $unit === '%' ) {
                        return $length * 0.01 * $viewportSize;
                }

                return $length * $unitLength[$unit];
        }
        return (float)$length;
    }

    static private function getReader( $path ) {
        // This is based on MediaWiki SVGReader class.
        $reader = new XMLReader();
        // Need entity loader to open the file in the first place.
        @libxml_disable_entity_loader( false );
        $res = $reader->open( $path, null, LIBXML_NOERROR | LIBXML_NOWARNING );
        if ( !$res ) {
            throw new RuntimeException( "Invalid SVG" );
        }
        // This is very important on php < 8 but is deprecated on php 8.
        @libxml_disable_entity_loader( true );
        $reader->setParserProperty( XMLReader::SUBST_ENTITIES, true );
        return $reader;
    }

    static public function readMetadata($path) {
        $reader = self::getReader( $path );

        $readSuccess = $reader->read();
        // fast forward to root <svg>
        while ( $readSuccess && $reader->nodeType !== XMLReader::ELEMENT ) {
            $readSuccess = $reader->read();
        }

        if ( $reader->localName !== 'svg' || $reader->namespaceURI !== self::NS_SVG ) {
            throw new RuntimeException( "First element of SVG is not an SVG tag $path" );
        }

        $viewBox = $reader->getAttribute( 'viewBox' );
        $width = $reader->getAttribute( 'width' ) ?? 'auto';
        $height = $reader->getAttribute( 'height' ) ?? 'auto';

        // Case 1 no viewBox, width, height
        // SVG spec says the image should be 300x150 px. MediaWiki does 512x512 but that may change
        // in future so go with spec. https://svgwg.org/specs/integration/#svg-css-sizing
        if ( $width === 'auto' && $height === 'auto' && $viewBox === null ) {
            return [
                'width' => 300,
                'height' => 150
            ];
        }

        // Otherwise we use width & height directly. If one is missing we
        // calculate it from the other using aspect ratio from viewBox.
        // If both are missing we assume a default of 512px (Like MW does)

        if ( $viewBox !== null ) {
            // viewBox attribute is: starting-x, starting-y, width, height.
            $viewBoxArr = preg_split( '/\s*[\s,]\s*/', trim( $viewBox ) );
            $viewBoxArr = array_filter( $viewBoxArr, 'is_numeric' );
            if ( count( $viewBoxArr ) !== 4 ) {
                throw new RuntimeException( "Invalid viewBox attribute" );
            }
            $viewBoxArr = array_map( 'floatval', $viewBoxArr );

            $aspect = $viewBoxArr[2] / $viewBoxArr[3];
        } else {
            $aspect = 1.0;
        }

        // MediaWiki uses a default size of 512x512 for SVGs.
        // Note: Unlike the case where all 3 are unset, this is not 300x150 according to spec.
        // A percentage width/height resolves against the viewBox dimensions when present,
        // otherwise against the 512px default viewport (as MediaWiki does).
        $viewportWidth = isset( $viewBoxArr ) ? $viewBoxArr[2] : 512;
        $viewportHeight = isset( $viewBoxArr ) ? $viewBoxArr[3] : 512;
        if ( $width !== 'auto' ) {
            $calcWidth = self::convertUnit( $width, $viewportWidth );
        }
        if ( $height !== 'auto' ) {
            $calcHeight = self::convertUnit( $height, $viewportHeight );
        }

        if ( !isset( $calcWidth ) && !isset( $calcHeight ) ) {
            $calcWidth = 512;
            $calcHeight = 512 / $aspect;
        }

        if ( !isset( $calcWidth ) ) {
            $calcWidth = $calcHeight * $aspect;
        }

        if ( !isset( $calcHeight ) ) {
            $calcHeight = $calcWidth / $aspect;
        }

        return [
            'width' => (int)$calcWidth,
            'height' => (int)$calcHeight,
            'viewBox' => $viewBoxArr ?? [ 0.0, 0.0, (float)$calcWidth, (float)$calcHeight ],
        ];
    }

    public function crop($srcPath, $destPath, $method, $coords, $rotation, $brightness, $contrast, $saturation)
    {
        if ($brightness != 0 || $contrast != 0 || $saturation != 0) {
           throw new RuntimeException( "Filters not supported for SVGs" );
        }

        $metadata = self::readMetadata( $srcPath );

        $transform = null;

        if ( (float)$rotation != 0 ) {
            // Rotating a vector image is a transform, not a pixel operation: the
            // drawing is rotated about its centre exactly like the raster path
            // does, and the crop rectangle of that is shown.
            list( $newViewBox, $transform ) = self::rotatedGeometry( $metadata, $coords, $rotation );
        } else {
            $viewBoxHorizUnits = $metadata['viewBox'][2] / $metadata['width'];
            $newViewBoxXOffset = $metadata['viewBox'][0] + $coords['x'] * $viewBoxHorizUnits;
            $newViewBoxWidth = $coords['width'] * $viewBoxHorizUnits;

            $viewBoxVertUnits = $metadata['viewBox'][3] / $metadata['height'];
            $newViewBoxYOffset = $metadata['viewBox'][1] + $coords['y'] * $viewBoxVertUnits;
            $newViewBoxHeight = $coords['height'] * $viewBoxVertUnits;

            $newViewBox = "$newViewBoxXOffset $newViewBoxYOffset $newViewBoxWidth $newViewBoxHeight";
        }

        $reader = self::getReader( $srcPath );

        $dest = fopen( $destPath, 'w' );
        if ( !$dest ) {
            throw new RuntimeException( "Could not open destination path for writing" );
        }

        fwrite( $dest, '<?xml version="1.0" encoding="UTF-8"?>' );
        while( $reader->next() ) {
            if ( $reader->nodeType === XMLReader::ELEMENT ) {

                if ( $reader->localName !== 'svg' || $reader->namespaceURI !== self::NS_SVG ) {
                    throw new RuntimeException( "Root node of svg file is not <svg>" );
                }

                $openingElm = '<' . $reader->name;
                $closingElm = '</' . $reader->name . '>';

                $openingElm .= ' width="' . htmlspecialchars( $coords['width'], ENT_XML1 | ENT_QUOTES ) . '"';
                $openingElm .= ' height="' . htmlspecialchars( $coords['height'], ENT_XML1 | ENT_QUOTES ) . '"';
                $openingElm .= ' viewBox="' . htmlspecialchars( $newViewBox, ENT_XML1 | ENT_QUOTES ) . '"';
                while( $reader->moveToNextAttribute() ) {
                    if (
                        $reader->namespaceURI === '' &&
                        in_array( $reader->localName, [ 'width', 'height', 'viewBox', 'x', 'y' ] ) ) {
                        // x/y are ignored on a root <svg> and only mislead: the
                        // crop region is fully defined by width/height/viewBox.
                        continue;
                    }
                    $openingElm .= ' ' . $reader->name . '=';
                    $openingElm .= '"' . htmlspecialchars( $reader->value, ENT_XML1 | ENT_QUOTES ) . '"';
                }

                $openingElm .= '>';
                fwrite( $dest, $openingElm );
                // The following may duplicate xmlns declarations, but that should be fine.
                if ( $transform !== null ) {
                    fwrite( $dest, '<g transform="' . htmlspecialchars( $transform, ENT_XML1 | ENT_QUOTES ) . '">' );
                }
                fwrite( $dest, $reader->readInnerXML() );
                if ( $transform !== null ) {
                    fwrite( $dest, '</g>' );
                }
                fwrite( $dest, $closingElm );
            } else {
                // Most likely a doctype declaration.
                fwrite( $dest, $reader->readOuterXml() );
            }

        }
        fclose( $dest );

    }

    /**
     * Geometry for a rotated crop: the new viewBox and the transform that maps
     * the original drawing onto it.
     *
     * The raster path rotates the whole image about its centre (Imagick expands
     * the canvas to the rotated bounding box and centres the result in it) and
     * then crops; this reproduces that in vector terms, so a straighten of an
     * SVG matches what a straighten of the same drawing as a bitmap would give.
     * Lengths are converted with the source's viewBox-to-pixel ratio, which
     * assumes the viewBox scales uniformly (as the unrotated crop already does).
     */
    static public function rotatedGeometry( $metadata, $coords, $rotation ) {
        $unitsPerPixel = $metadata['viewBox'][2] / $metadata['width'];

        $sourceCentreX = $metadata['viewBox'][0] + $metadata['viewBox'][2] / 2;
        $sourceCentreY = $metadata['viewBox'][1] + $metadata['viewBox'][3] / 2;

        $rad = deg2rad( $rotation );
        $canvasWidth = abs( $metadata['width'] * cos( $rad ) ) + abs( $metadata['height'] * sin( $rad ) );
        $canvasHeight = abs( $metadata['height'] * cos( $rad ) ) + abs( $metadata['width'] * sin( $rad ) );
        $canvasCentreX = $canvasWidth * $unitsPerPixel / 2;
        $canvasCentreY = $canvasHeight * $unitsPerPixel / 2;

        // translate( tx ty ) rotate( angle ) maps a point of the original onto
        // the rotated canvas, shifted so the crop rectangle starts at the origin
        // of the new viewBox.
        $translateX = $canvasCentreX - $coords['x'] * $unitsPerPixel
            - ( cos( $rad ) * $sourceCentreX - sin( $rad ) * $sourceCentreY );
        $translateY = $canvasCentreY - $coords['y'] * $unitsPerPixel
            - ( sin( $rad ) * $sourceCentreX + cos( $rad ) * $sourceCentreY );

        $viewBox = '0 0 ' . self::svgNumber( $coords['width'] * $unitsPerPixel )
            . ' ' . self::svgNumber( $coords['height'] * $unitsPerPixel );
        $transform = 'translate(' . self::svgNumber( $translateX ) . ' ' . self::svgNumber( $translateY )
            . ') rotate(' . self::svgNumber( $rotation ) . ')';

        return [ $viewBox, $transform ];
    }

    static private function svgNumber( $value ) {
        $rounded = round( (float)$value, 4 );
        // Avoid "-0" in the output
        return (string)( $rounded == 0 ? 0 : $rounded );
    }

    public function supportsRotation() {
        return true;
    }

    public function supportsFilters() {
        return false;
    }
}
