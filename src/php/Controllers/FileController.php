<?php

namespace CropTool\Controllers;

use CropTool\AutoStraightener;
use CropTool\BorderLocator;
use CropTool\EditSummary;
use CropTool\File\FileInterface;
use CropTool\Image;
use CropTool\ImageEditor;
use CropTool\WikidataItem;
use CropTool\NoSuchEntity;
use CropTool\WikiPageService;
use DI\FactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FileController
{
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Utility method to return an array with the relative path + file dimensions
     *
     * @param FileInterface $file
     * @param Image $img
     * @param int $pageno
     * @param string $suffix
     * @return array|null
     */
    protected function fileResponse(FileInterface $file, Image $img = null, $pageno = 0, $suffix = '')
    {
        if (is_null($img)) {
            return null;
        }

        return [
            'name' => substr($img->path, strlen($file->getPublicDir())),
            'width' => $img->width,
            'height' => $img->height,
        ];
    }

    public function exists(Response $response, Request $request, WikiPageService $pageService)
    {
        $page = $pageService->getForTitle( $request->getQueryParams()['title'], $request->getQueryParams()['site'] );
        $response->getBody()->write((string)json_encode([
            'site' => $page->site,
            'title' => $page->title,
            'exists' => $page->exists,
        ]));
        return $response;
    }

    public function info(Response $response, Request $request, WikiPageService $pageService, ImageEditor $editor)
    {
        $page = $pageService->getForTitle($request->getQueryParams()['title'], $request->getQueryParams()['site']);
        $pageno = intval($request->getQueryParams()['page'] ?? 0);

        $page->assertExists();
        $page->assertNotWaitingForLicenseReview();
        $page->file->fetchPage($pageno);

        $thumbPath = $page->file->getAbsolutePathForPage($pageno, '_thumb');

        // If tiff file, then create jpg thumb, since most browsers don't support tiff
        $thumbPath = preg_replace('/\.tiff?$/', '.jpg', $thumbPath);

        $original = $editor->open($page->file, $pageno);     // instance of Image
        $thumb = $original->thumb($thumbPath);   // instance of Image or null

        $response->getBody()->write((string)json_encode([
            'site' => $page->site,
            'title' => $page->title,
            'description' => $page->imageinfo->descriptionurl,
            // Prefer a count verified against the actual file (some TIFFs
            // report embedded preview/thumbnail IFDs as extra "pages").
            'pagecount' => $page->file->getPageCount() ?: $page->imageinfo->pagecount,
            'mime' => $page->imageinfo->mime,
            'original' => $this->fileResponse($page->file, $original, $pageno),
            'thumb' => $this->fileResponse($page->file, $thumb, $pageno, '_thumb'),
            'samplingFactor' => $original->samplingFactor,
            'orientation' => $original->orientation,
            'categories' => $page->imageinfo->categories,
            'supportsRotation' => $page->file->supportsRotation(),
            'supportsFilters' => $page->file->supportsFilters(),
            'overrideResultExtension' => $page->file->overrideResultExtension()
        ]));

        return $response;
    }

    public function autodetect(Response $response, Request $request, WikiPageService $pageService, BorderLocator $bloc)
    {
        $page = $pageService->getForTitle($request->getQueryParams()['title'], $request->getQueryParams()['site']);
        $pageno = intval($request->getQueryParams()['page'] ?? 0);
        $srcPath = $page->file->getAbsolutePathForPage($pageno);

        $response->getBody()->write((string)json_encode([
            'area' => $bloc->open($srcPath)->getSelection(),
        ]));

        return $response;
    }

    public function autostraighten(Response $response, Request $request, WikiPageService $pageService, AutoStraightener $straightener)
    {
        $page = $pageService->getForTitle($request->getQueryParams()['title'], $request->getQueryParams()['site']);
        $pageno = intval($request->getQueryParams()['page'] ?? 0);
        $srcPath = $page->file->getAbsolutePathForPage($pageno);

        $response->getBody()->write((string)json_encode([
            'angle' => $straightener->detectAngle($srcPath),
        ]));

        return $response;
    }

    public function crop(Response $response, Request $request, WikiPageService $pageService, ImageEditor $editor, LoggerInterface $logger, FactoryInterface $factory)
    {
        $page = $pageService->getForTitle($request->getQueryParams()['title'] ?? 0, $request->getQueryParams()['site'] ?? 'commons.wikimedia.org');
        // @TODO: DRY
        $pageno = intval($request->getQueryParams()['page'] ?? 0);
        $x = intval($request->getQueryParams()['x'] ?? 0);
        $y = intval($request->getQueryParams()['y'] ?? 0);
        $width = intval($request->getQueryParams()['width'] ?? 0);
        $height = intval($request->getQueryParams()['height'] ?? 0);
        $rotation = floatval($request->getQueryParams()['rotate'] ?? 0);
        $brightness = max(-100.0, min(100.0, floatval($request->getQueryParams()['brightness'] ?? 0)));
        $contrast = max(-100.0, min(100.0, floatval($request->getQueryParams()['contrast'] ?? 0)));
        $saturation = max(-100.0, min(100.0, floatval($request->getQueryParams()['saturation'] ?? 0)));
        $cropMethod = $request->getQueryParams()['method'] ??'precise';

        $t0 = microtime(true) * 1000;

        $destPath = $page->file->getAbsolutePathForPage($pageno, '_cropped');
        $thumbPath = $page->file->getAbsolutePathForPage($pageno, '_cropped_thumb');

        // If tiff file, then create jpg thumb, since most browsers don't support tiff
        $thumbPath = preg_replace('/\.tiff?$/', '.jpg', $thumbPath);

        if (!in_array($cropMethod, ['lossless', 'precise'])) {
            throw new \RuntimeException('Unknown crop method specified');
        }

        $original = $editor->open($page->file, $pageno);
        $crop = $original->crop($destPath, $cropMethod, $x, $y, $width, $height, $rotation, $brightness, $contrast, $saturation);
        $thumb = $crop->thumb($thumbPath);

        $logger->info('[{sha1}] Cropped using {method} mode', [
            'sha1' => $page->file->getShortSha1(),
            'method' => $cropMethod,
        ]);

        $dim = array();
        if ( $pageno > 0 ) {
            $dim[] = 'page ' . $pageno;
	}
        if ($original->width != $crop->width) {
            $cropPercentX = round(($original->width - $crop->width) / $original->width * 100);
            $dim[] = ($cropPercentX ?: ' < 1') . '% horizontally';
        }
        if ($original->height != $crop->height) {
            $cropPercentY = round(($original->height - $crop->height) / $original->height * 100);
            $dim[] = ($cropPercentY ?: ' < 1') . '% vertically';
        }
        $cropPercentXY = round((1 - $crop->width * $crop->height / ($original->width * $original->height)) * 100);
        $dim[] = ($cropPercentXY ?: ' < 1') . '% areawise';
        if ($rotation) {
            $dim[] = "rotated {$rotation}°";
        }
        if ($brightness != 0) {
            $dim[] = "brightness {$brightness}";
        }
        if ($contrast != 0) {
            $dim[] = "contrast {$contrast}";
        }
        if ($saturation != 0) {
            $dim[] = "saturation {$saturation}";
        }

        $options = $page->wikitext->possibleStuffToRemove();
        $language = $this->metadataLanguage($request->getQueryParams()['language'] ?? 'en');
        $metadata = [
            'depicts' => $page->site == 'commons.wikimedia.org' ? $page->getDepicts($language) : [],
            'categories' => $this->categoriesMetadata($page->imageinfo->categories),
        ];
        $wd = null;
        if (isset($options['wikidata-item'])) {
            try {
                $item = $factory->make(WikidataItem::class, ['entity' => $options['wikidata-item']]);
                $el = $item->get();
                $wd = ['labels' => []];
                foreach ($el->labels as $k => $v) {
                    $wd['labels'][$k] = $v->value;
                }
            } catch (NoSuchEntity $e) {
            }
        }

        $response->getBody()->write((string)json_encode(([
            'site' => $page->site,
            'title' => $page->title,
            'pageno' => $pageno,
            'method' => $cropMethod,
            'dim' => implode(', ', $dim) . ' using [[Commons:CropTool|CropTool2]] with ' . $cropMethod . ' mode.',
            'page' => [
                'elems' => $options,
                'hasAssessmentTemplates' => $page->wikitext->hasAssessmentTemplates(),
                'hasDoNotCropTemplate' => $page->wikitext->hasDoNotCropTemplate(),
                'hasUploadProtection' => $page->imageinfo->hasUploadProtection(),
                'metadata' => $metadata,
            ],
            'crop' => $this->fileResponse($page->file, $crop, $pageno, '_cropped'),
            'thumb' => $this->fileResponse($page->file, $thumb, $pageno, '_cropped_thumb'),
            'time' => time(),
            'wikidata' => $wd,
            'msecs' => round(microtime(true)*1000 - $t0),
        ])));

        return $response;
    }

    private function metadataLanguage($language)
    {
        $language = strtolower($language);
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $language)) {
            $language = 'en';
        }
        $fallbacks = [$language, 'en', 'mul', 'de', 'fr', 'nl', 'es'];
        return implode('|', array_values(array_unique($fallbacks)));
    }

    public function publish(Response $response, Request $request, WikiPageService $pageService, FactoryInterface $factory, LoggerInterface $logger)
    {
        $sitesSupportingExtractedFromTemplate = [
            'commons.wikimedia.org',
        ];
        $sitesSupportingImageExtractedTemplate = [
            'commons.wikimedia.org',
        ];

        // @TODO: DRY
        $body = $request->getParsedBody();
        $site = array_get($body, 'site', 'commons.wikimedia.org');
        $page = $pageService->getForTitle(array_get($body, 'title'), $site);
        $pageno = intval(array_get($body, 'page', 0));
        $overwrite = array_get($body, 'overwrite') == 'overwrite';
        $editComment = array_get($body, 'comment');
        $stuffToRemove = array_get($body, 'elems');
        $metadata = array_get($body, 'metadata', []);
        $ignoreWarnings = boolval(array_get($body, 'ignorewarnings', false));
        $newName = array_get($body, 'filename');
        $progressFile = $this->uploadProgressFile(array_get($body, 'progress'));

        $page->assertExists();
        $cropPath = $page->file->getAbsolutePathForPage($pageno, '_cropped');

        $wikitext = $page->wikitext;
        $elems = [];
        if (array_get($stuffToRemove, 'border')) {
            $wikitext = $wikitext->withoutBorderTemplate();
            $elems['border'] = 1;
        }
        if (array_get($stuffToRemove, 'trimming')) {
            $wikitext = $wikitext->withoutTrimmingTemplate();
            $elems['trimming'] = 1;
        }
        if (array_get($stuffToRemove, 'watermark')) {
            $wikitext = $wikitext->withoutWatermarkTemplate();
            $elems['watermark'] = 1;
        }

        if ($overwrite) {
            $page->assertCanOverwrite();

            // ignoreWarnings=true is necessary for overwrite
            try {
                $uploadResponse = $page->upload($cropPath, $editComment, true, $progressFile);
            } finally {
                if ($progressFile) {
                    @unlink($progressFile);
                }
            }
            $logger->info('Uploaded new version of "' . $page->title . '".');

            $editSummary = new EditSummary();

            if (count($elems)) {
                $editSummary->add('removing ' . implode(' and ', array_keys($elems)));
            }

            if ($page->inCategory('All non-free media')) {
                $wikitext = $wikitext->addOrfurrev();
                $editSummary->add('tagging with {{Orphaned non-free revisions}}');
            }

            $page->setWikitext($wikitext)
                ->save($editSummary->build());
        } else {
            $newPage = $pageService->getForTitle( $newName, $site );
            if (!$ignoreWarnings) {
                $newPage->assertNotExists();
            }

            // Remove templates before appending {{Extracted from}}
            $wikitext = $wikitext->withoutTemplatesNotToBeCopied();
            $wikitext = $wikitext->withoutCategories($this->deselectedOriginalCategories($page, $metadata));

            if (array_get($stuffToRemove, 'wikidata')) {
                $wikitext = $wikitext->withoutCropForWikidataTemplate();
                $elems['wikidata'] = array_get($stuffToRemove, 'wikidata-item');
            }

            if (in_array($newPage->site, $sitesSupportingExtractedFromTemplate)) {
                $wikitext = $wikitext->appendExtractedFromTemplate($page->title);
            }
            $newPage->setWikitext($wikitext);

            try {
                $uploadResponse = $newPage->upload($cropPath, $editComment, $ignoreWarnings, $progressFile);
            } finally {
                if ($progressFile) {
                    @unlink($progressFile);
                }
            }
            $logger->info('Uploaded new version of "' . $page->title . '" as "' . $newPage->title . '".');

            $editSummary = new EditSummary();

            if (in_array($page->site, $sitesSupportingImageExtractedTemplate)) {
                $wt0 = $page->wikitext;
                if (array_get($stuffToRemove, 'wikidata')) {
                    $wt0 = $wt0->withoutCropForWikidataTemplate();
                }
                $editSummary->add('adding/updating {{Image extracted}}');
                $page->setWikitext($wt0->appendImageExtractedTemplate($newName))
                    ->save($editSummary->build());
            }

            if (array_get($stuffToRemove, 'wikidata')) {
                $wdEntity = array_get($stuffToRemove, 'wikidata-item');
                $item = $factory->make(WikidataItem::class, ['entity' => $wdEntity]);
                $item->addClaim('P18', '"' . $newName . '"');
            }

            if ($site == 'commons.wikimedia.org') {
                $uploadedPage = $pageService->getForTitle($newName, $site);
                try {
                    $uploadedPage->addDepictsStatements($this->selectedOriginalDepictsIds($page, $metadata));
                } catch (\Throwable $e) {
                    $logger->warning('Failed to add depicts statements to "' . $newName . '": ' . $e->getMessage());
                }
            }
        }

        $uploadResponse->elems = $elems;

        $response->getBody()->write((string)json_encode($uploadResponse));
        return $response;
    }

    /**
     * Path of the JSON status file that the frontend polls while the upload
     * is running (see /api/upload-progress). Returns null when no (valid)
     * progress token was supplied, i.e. the upload was not started from the
     * web UI.
     *
     * @param mixed $token
     * @return string|null
     */
    protected function uploadProgressFile($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{16,64}$/', $token)) {
            return null;
        }

        $dir = ROOT_PATH . '/public_html/files/progress';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . $token . '.json';
    }

    protected function selectedMetadataValues($metadata, $group, $valueKey)
    {
        return $this->metadataValues($metadata, $group, $valueKey, true);
    }

    protected function categoriesMetadata($categories)
    {
        return array_map(function($category) {
            return [
                'name' => $category,
                'selected' => true,
            ];
        }, $categories);
    }

    protected function selectedOriginalDepictsIds($page, $metadata)
    {
        $selectedIds = $this->selectedMetadataValues($metadata, 'depicts', 'id');
        $originalIds = array_map(function($depicts) {
            return $this->metadataField($depicts, 'id');
        }, $page->getDepicts());

        return array_values(array_intersect($selectedIds, $originalIds));
    }

    protected function deselectedOriginalCategories($page, $metadata)
    {
        $deselectedCategories = $this->metadataValues($metadata, 'categories', 'name', false);

        return array_values(array_intersect($deselectedCategories, $page->imageinfo->categories));
    }

    protected function metadataValues($metadata, $group, $valueKey, $selected)
    {
        $values = [];
        foreach ($this->metadataField($metadata, $group, []) as $item) {
            if (boolval($this->metadataField($item, 'selected', true)) === $selected && $this->metadataField($item, $valueKey)) {
                $values[] = $this->metadataField($item, $valueKey);
            }
        }

        return $values;
    }

    protected function metadataField($data, $key, $default = null)
    {
        if (is_array($data)) {
            return array_key_exists($key, $data) ? $data[$key] : $default;
        }
        if (is_object($data)) {
            return property_exists($data, $key) ? $data->{$key} : $default;
        }

        return $default;
    }

}
