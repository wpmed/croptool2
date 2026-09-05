<?php

namespace CropTool;

use CropTool\Auth\AuthServiceInterface;
use CropTool\Errors\ApiError;
use DI\FactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Simple MediaWiki API client
 */
class ApiService
{

    protected $endpoint;
    protected $container;
    protected $auth;
    protected $logger;
    protected $userAgent;
    protected $site;
    protected $factory;
    public $calls = 0;

    public function __construct(FactoryInterface $factory, LoggerInterface $logger, AuthServiceInterface $auth, Config $config, $site = 'commons.wikimedia.org')
    {
        $this->factory = $factory;
        $this->logger = $logger;
        $this->auth = $auth;
        $this->site = $site;
        $this->endpoint = 'https://' . $this->site . '/w/api.php';
        $this->userAgent = $config->get('userAgent', 'CropTool');
    }

    public function getSite()
    {
        return $this->site;
    }

    /**
     * Make a request to the MW API
     *
     * @param array $args
     * @param bool $multipart
     * @return stdClass
     */
    public function request($args, $multipart = false, $signed = true)
    {
        $args['format'] = 'json';

        $this->calls += 1;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, true);
        if ($multipart == true) {
            $oauthHeader = $this->auth->signRequestAndReturnHeader('POST', $this->endpoint);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
        } else {
            $oauthHeader = $this->auth->signRequestAndReturnHeader('POST', $this->endpoint, $args);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($args));
        }

        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        if ($signed) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($oauthHeader));
        }
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $data = curl_exec($ch);

        if (!$data) {
            header("HTTP/1.1 500 Internal Server Error");
            throw new \RuntimeException('Curl error: ' . htmlspecialchars(curl_error($ch)));
        }

        curl_close($ch);

        $data = json_decode($data);
        if (isset($data->error)) {
            // TODO better solution for this
            $info = str_replace(
                "⧼abusefilter-warning-file-overwriting⧽",
                "A file with this name already exists. You are only allowed to upload new versions of files you yourself uploaded. Please choose a different file name. See [[COM:OVERWRITE]] for details.",
                (string)$data->error->info
            );
            throw new ApiError('[api] Received error: ' . $data->error->code . ' : ' . $info);
        }

        return $data;
    }

    /**
     * @param $title
     * @return WikiText
     */
    public function getWikitext($title)
    {
        $response = $this->request([
            'action' => 'parse',
            'prop' => 'wikitext',
            'format' => 'json',
            'page' => 'File:' . $title
        ], false, false);

        return $this->factory->make(WikiText::class, [
            'text' => $response->parse->wikitext->{'*'}
            ]);
    }

    /**
     * @param $title
     * @return QueryResponse
     */
    public function getImageinfo($title, $namespace='File:')
    {
        $response = $this->request([
            'action' => 'query',
            'prop' => 'imageinfo|categories|info',
            'format' => 'json',
            'inprop' => 'protection',
            'clshow' => '!hidden',
            'cllimit' => 'max',
            'iiprop' => 'url|size|sha1|mime',
            'iilimit' => '1',
            'titles' => $namespace . $title
        ]);

        return $this->factory->make(QueryResponse::class, [
            'response' => $response->query
        ]);
    }

    public function getDepictsStatements($mediaInfoId)
    {
        if (!$mediaInfoId) {
            return [];
        }

        $response = $this->request([
            'action' => 'wbgetclaims',
            'entity' => $mediaInfoId,
            'property' => 'P180',
        ]);

        return $response->claims->P180 ?? [];
    }

    public function getEntityTerms($ids, $languages = 'en|mul|de|fr')
    {
        if (!count($ids)) {
            return [];
        }

        $response = $this->request([
            'action' => 'wbgetentities',
            'ids' => implode('|', $ids),
            'props' => 'labels|descriptions',
            'languages' => $languages,
        ]);

        $languageCodes = explode('|', $languages);
        $terms = [];
        foreach ($response->entities ?? [] as $id => $entity) {
            $labels = $this->entityTermValues($entity, 'labels', $languageCodes);
            $descriptions = $this->entityTermValues($entity, 'descriptions', $languageCodes);
            $terms[$id] = [
                'label' => $this->firstEntityTerm($labels, $languageCodes, $id),
                'description' => $this->firstEntityTerm($descriptions, $languageCodes, null, ['mul']),
                'labels' => $labels,
                'descriptions' => $descriptions,
            ];
        }

        return $terms;
    }

    private function entityTermValues($entity, $termType, array $languageCodes)
    {
        $values = [];
        foreach ($languageCodes as $language) {
            if (isset($entity->{$termType}->{$language}->value)) {
                $values[$language] = $entity->{$termType}->{$language}->value;
            }
        }
        return $values;
    }

    private function firstEntityTerm(array $terms, array $languageCodes, $default = null, array $skipLanguages = [])
    {
        foreach ($languageCodes as $language) {
            if (in_array($language, $skipLanguages)) {
                continue;
            }
            if (isset($terms[$language])) {
                return $terms[$language];
            }
        }
        return $default;
    }

    /**
     * Request an edit token
     * Returns the edit token, or FALSE on failure
     */
    public function getEditToken()
    {
        $response = $this->request([
            'action' => 'query',
            'meta' => 'tokens',
            'type' => 'csrf'
        ]);

        return $response->query->tokens->csrftoken;
    }


    /** MediaWiki's default maximum chunk size is 5 MiB. */
    const UPLOAD_CHUNK_SIZE = 5242880;

    /** Files at or below this size use the simple single-request path. */
    const SINGLE_UPLOAD_LIMIT = 8388608;

    /**
     * @param string $title
     * @param string $filename
     * @param string $summary
     * @param string|null $text
     * @param bool $ignoreWarnings
     * @param string|null $progressFile Path of a JSON status file that is
     *   updated with {"uploaded":..,"filesize":..} after every chunk, used by
     *   the frontend to render an upload progress bar.
     * @return array
     */
    public function upload($title, $filename, $summary, $text=null, $ignoreWarnings=false, $progressFile=null)
    {
        // Large files (e.g. TIFF crops) can take many minutes to upload; do
        // not let max_execution_time silently kill the request half-way.
        set_time_limit(0);

        $token = $this->getEditToken();

        $args = [
            'action' => 'upload',
            'format' => 'json',
            'filename' => $title,
            'token' => $token,
            'comment' => $summary,
        ];
        if ($ignoreWarnings) {
            $args['ignorewarnings'] = '1';
        }
        if (!is_null($text)) {
            $args['text'] = $text;
        }

        $fileSize = @filesize($filename);

        $upload = null;
        if ($fileSize === false || $fileSize <= self::SINGLE_UPLOAD_LIMIT) {
            $args['file'] = new \CURLFile($filename);
            $upload = $this->request($args, true)->upload;
        } else {
            $upload = $this->uploadInChunks($args, $filename, $fileSize, $progressFile);
        }

        return $this->completeUploadResult($upload);
    }

    /**
     * Make sure a successful upload result carries imageinfo.descriptionurl
     * (needed by the "copy the URL" field in the UI). Some chunked-upload
     * responses omit imageinfo entirely; fill it in from a follow-up request.
     *
     * @param \stdClass $upload
     * @return \stdClass
     */
    protected function completeUploadResult($upload)
    {
        if ($upload->result !== 'Success' || !empty($upload->imageinfo->descriptionurl)) {
            return $upload;
        }
        if (empty($upload->filename)) {
            return $upload;
        }

        $data = $this->request([
            'action' => 'query',
            'prop' => 'imageinfo',
            'iiprop' => 'url',
            'titles' => 'File:' . $upload->filename,
        ]);

        foreach ((array)($data->query->pages ?? []) as $page) {
            if (!empty($page->imageinfo[0])) {
                $upload->imageinfo = $page->imageinfo[0];
                break;
            }
        }

        return $upload;
    }

    protected function writeUploadProgress($progressFile, $uploaded, $fileSize)
    {
        if (!$progressFile) {
            return;
        }
        @file_put_contents(
            $progressFile,
            (string)json_encode(['uploaded' => (int)$uploaded, 'filesize' => (int)$fileSize])
        );
    }

    /**
     * Chunked upload. MediaWiki does not accept very large files in a single
     * POST, so send the file in 5 MiB chunks (action=upload with chunk+
     * offset+filesize+filekey).
     *
     * Note: chunk requests only store the file in an upload stash. When the
     * last chunk is received MediaWiki assembles the stash and answers
     * "Success" with the assembled filekey, but it does NOT create the file
     * page. The final step is a separate action=upload request that has no
     * chunk and only references the filekey, which publishes the file.
     *
     * @param array $args Base upload arguments (no file/chunk yet).
     * @param string $filename
     * @param int $fileSize
     * @param string|null $progressFile
     * @return array
     */
    protected function uploadInChunks(array $args, $filename, $fileSize, $progressFile=null)
    {
        $this->writeUploadProgress($progressFile, 0, $fileSize);

        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            throw new ApiError('Unable to read the file to upload: ' . $filename);
        }

        $tmpFile = null;
        $offset = 0;
        $filekey = null;

        try {
            while ($offset < $fileSize) {
                $chunk = fread($handle, self::UPLOAD_CHUNK_SIZE);
                $chunkLength = strlen($chunk);
                if ($chunkLength === 0) {
                    throw new ApiError('Could not read the file to upload at offset ' . $offset);
                }

                $tmpFile = tempnam(sys_get_temp_dir(), 'croptool');
                if (file_put_contents($tmpFile, $chunk) === false) {
                    throw new ApiError('Could not write an upload chunk to a temporary file.');
                }

                $chunkArgs = $args;
                $chunkArgs['filesize'] = $fileSize;
                $chunkArgs['offset'] = $offset;
                $chunkArgs['chunk'] = new \CURLFile($tmpFile);
                // MediaWiki requires the stash filekey from the first chunk on
                // every request that has a non-zero offset.
                if ($filekey !== null) {
                    $chunkArgs['filekey'] = $filekey;
                }

                $response = $this->request($chunkArgs, true)->upload;

                @unlink($tmpFile);
                $tmpFile = null;

                if ($response->result === 'Continue') {
                    if ($filekey === null && isset($response->filekey)) {
                        $filekey = $response->filekey;
                    }
                    $nextOffset = isset($response->offset)
                        ? intval($response->offset)
                        : $offset + $chunkLength;
                    if ($nextOffset <= $offset) {
                        throw new ApiError('Upload did not make progress (stuck at offset ' . $offset . ').');
                    }
                    $offset = $nextOffset;
                    $this->writeUploadProgress($progressFile, $offset, $fileSize);
                    continue;
                }

                if ($response->result === 'Success') {
                    // All chunks are assembled in the upload stash. Remember
                    // the assembled filekey and publish it below.
                    if (isset($response->filekey)) {
                        $filekey = $response->filekey;
                    }
                    break;
                }

                // 'Warning' or anything else: hand back to the caller, which
                // knows how to ask the user about warnings.
                return $response;
            }

            if ($filekey === null) {
                throw new ApiError('The upload server did not provide a filekey.');
            }

            // Publish: create the file page and revision from the assembled
            // stash. This is the request whose result the caller sees.
            $finalArgs = $args;
            $finalArgs['filekey'] = $filekey;
            $response = $this->request($finalArgs, true)->upload;
            return $response;
        } catch (\Throwable $e) {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
            throw $e;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param $title
     * @param $text
     * @param $summary
     * @return array
     */
    public function savePage($title, $text, $summary)
    {
        return $this->request([
            'action' => 'edit',
            'format' => 'json',
            'summary' => $summary,
            'token' => $this->getEditToken(),
            'title' => $title,
            'text' => $text
        ]);
    }

    /**
     * Create a new Wikibase claim
     *
     * @param string $entity (e.g. 'Q42')
     * @param string $property (e.g. 'P18')
     * @param string $value (e.g. 'Test.jpg')
     * @param string $snaktype (defaults to 'value')
     */
    public function createClaim($entity, $property, $value, $snaktype='value')
    {
        $token = $this->getEditToken();

        return $this->request([
            'action' => 'wbcreateclaim',
            'entity' => $entity,
            'property' => $property,
            'snaktype' => $snaktype,
            'value' => $value,
            'token' => $token,
        ]);
    }

    /**
     * Get the claims for a given entity, filtered by property.
     *
     * @param string $entity (e.g. 'Q42')
     * @param string $property (e.g. 'P18')
     */
    public function getClaimsByProperty($entity, $property)
    {
        $response = $this->request([
            'action' => 'wbgetclaims',
            'entity' => $entity,
        ]);

        if (!isset($response->claims->{$property})) {
            return [];
        }

        return $response->claims->{$property};
    }

    /**
     * Get data for one or more Wikidata entities.
     *
     * @param string $entity (e.g. 'Q42' or 'Q42|Q17')
     */
    public function getEntities($entities)
    {
        $response = $this->request([
            'action' => 'wbgetentities',
            'ids' => $entities,
        ]);

        if (isset($response->error)) {
            throw new NoSuchEntity();
        }

        return $response->entities;
    }
}
