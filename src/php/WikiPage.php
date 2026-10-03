<?php

namespace CropTool;

use DI\FactoryInterface;
use CropTool\File\FileInterface;
use CropTool\File\FileRepository;
use Psr\Log\LoggerInterface;

/**
 * @property bool exists
 * @property string site
 * @property string title
 * @property QueryResponse imageinfo
 * @property WikiText wikitext
 * @property FileInterface file
 */
class WikiPage
{
    use MagicParameterTrait;

    protected $cache = [];
    protected $api;
    protected $files;
    protected $logger;
    protected $_title;
    protected $dirty;
    protected $namespace;

    /**
     * WikiPage constructor.
     * @param ApiService $api
     * @param FileRepository $files
     * @param LoggerInterface $logger
     * @param string $title
     * @param string $namespace
     */
    public function __construct(FileRepository $files, FactoryInterface $factory, LoggerInterface $logger, $title, $site = 'commons.wikimedia.org', $namespace = 'File:')
    {
        $this->api = $factory->make(ApiService::class, [
            'site' => $site
        ]);
        $this->files = $files;
        $this->logger = $logger;
        $this->_title = $title;
        $this->namespace = $namespace;
        $this->dirty = false;
    }

    public function save($summary)
    {
        if (!$this->dirty) {
            return;
        }

        $this->api->savePage($this->namespace . $this->title, strval($this->wikitext), $summary);
        $this->logger->info('Saved page "' . $this->title . '": ' . $summary);
        $this->dirty = false;
    }

    public function upload($filename, $editComment, $ignoreWarnings = false, $progressFile = null)
    {
        $response = $this->api->upload(
            $this->title,
            $filename,
            $editComment,
            strval($this->wikitext),
            $ignoreWarnings,
            $progressFile
        );
        $this->dirty = false;
        return $response;
    }

    public function assertExists()
    {
        if (!$this->exists) {
            throw new \RuntimeException('File doesn\'t exist: ' . $this->title);
        }

        return $this;
    }

    public function assertNotExists()
    {
        if ($this->exists) {
            throw new \RuntimeException('File already exists: ' . $this->title);
        }

        return $this;
    }

    public function assertNotWaitingForLicenseReview()
    {
        if ($this->wikitext->waitingForReview()) {
            throw new \RuntimeException('file-waiting-for-license-review');
        }
    }

    public function assertCanOverwrite()
    {
        if ($this->imageinfo->hasUploadProtection()) {
            throw new \RuntimeException('This file is protected against uploading new versions. Please upload the crop as a new file.');
        }
    }

    public function getTitleParameter()
    {
        return $this->_title;
    }

    public function getImageinfoParameter()
    {
        if (!isset($this->cache['imageinfo'])) {
            $this->cache['imageinfo'] = $this->api->getImageinfo($this->title);
        }
        return $this->cache['imageinfo'];
    }

    public function setWikitext(WikiText $text)
    {
        if (array_get($this->cache, 'wikitext') != $text) {
            $this->dirty = true;
        }
        $this->cache['wikitext'] = $text;

        return $this;
    }

    public function getWikitextParameter()
    {
        if (!isset($this->cache['wikitext'])) {
            $this->cache['wikitext'] = $this->api->getWikitext($this->title);
        }
        return $this->cache['wikitext'];
    }

    public function getFileParameter()
    {
        if (!isset($this->cache['file'])) {
            $this->cache['file'] = $this->files->get($this->imageinfo);
        }
        return $this->cache['file'];
    }

    public function getDepictsParameter()
    {
        return $this->getDepicts();
    }

    public function getDepicts($languages = 'en|mul|de|fr')
    {
        if (!isset($this->cache['depictsIds'])) {
            $this->cache['depictsIds'] = $this->getDepictsIds();
        }

        $cacheKey = 'depicts:' . $languages;
        if (!isset($this->cache[$cacheKey])) {
            $ids = $this->cache['depictsIds'];
            try {
                $terms = $this->api->getEntityTerms($ids, $languages);
            } catch (\Throwable $e) {
                $terms = [];
            }
            $this->cache[$cacheKey] = array_map(function($id) use ($terms) {
                return $this->depictsMetadata($id, $terms[$id] ?? []);
            }, $ids);
        }

        return $this->cache[$cacheKey];
    }

    private function getDepictsIds()
    {
        try {
            $statements = $this->api->getDepictsStatements($this->imageinfo->getMediaInfoId());
        } catch (\Throwable $e) {
            $statements = [];
        }

        $ids = [];
        foreach ($statements as $statement) {
            $id = $statement->mainsnak->datavalue->value->id ?? null;
            if ($id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function depictsMetadata($id, array $termSet)
    {
        return [
            'id' => $id,
            'label' => $termSet['label'] ?? $id,
            'description' => $termSet['description'] ?? null,
            'labels' => $termSet['labels'] ?? [],
            'descriptions' => $termSet['descriptions'] ?? [],
            'selected' => true,
        ];
    }

    public function addDepictsStatements($depictsIds)
    {
        $mediaInfoId = $this->imageinfo->getMediaInfoId();
        if (!$mediaInfoId) {
            return;
        }

        $existingIds = [];
        try {
            foreach ($this->api->getDepictsStatements($mediaInfoId) as $statement) {
                $existingId = $statement->mainsnak->datavalue->value->id ?? null;
                if ($existingId) {
                    $existingIds[] = $existingId;
                }
            }
        } catch (\Throwable $e) {
            $existingIds = [];
        }

        foreach ($depictsIds as $id) {
            if (!preg_match('/^Q[1-9][0-9]*$/', $id)) {
                continue;
            }
            if (in_array($id, $existingIds)) {
                continue;
            }

            $this->api->createClaim($mediaInfoId, 'P180', json_encode([
                'entity-type' => 'item',
                'numeric-id' => intval(substr($id, 1)),
                'id' => $id,
            ]));
        }
    }

    public function getExistsParameter()
    {
        return $this->imageinfo->exists;
    }

    public function getSiteParameter()
    {
        return $this->api->getSite();
    }

    public function __clone()
    {
        $this->cache['wikitext'] = clone $this->cache['wikitext'];
    }

    public function inCategory($name)
    {
        return in_array($name, $this->imageinfo->categories);
    }
}
