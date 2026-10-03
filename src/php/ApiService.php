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
    /** Timestamp of the last upload-progress write (see writeUploadProgress). */
    protected $lastUploadProgressWrite = 0;
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
     * @param bool $signed
     * @param callable|null $onProgress Called while the request is in flight with
     *   the number of request-body bytes sent so far. curl reports a POST body
     *   as upload (ultotal/ulnow), which is what lets the upload bar move during
     *   a chunk instead of only between chunks.
     * @return stdClass
     */
    public function request($args, $multipart = false, $signed = true, $onProgress = null)
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

        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt(
                $ch,
                CURLOPT_XFERINFOFUNCTION,
                function ($curl, $dltotal, $dlnow, $ultotal, $ulnow) use ($onProgress) {
                    $onProgress($ulnow, $ultotal);
                    return 0;
                }
            );
        }

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


    /**
     * Chunk size for chunked uploads. Wikimedia's PHP post_max_size /
     * upload_max_filesize is 100 MiB, so chunks can be much larger than the
     * 5 MiB used by older examples; larger chunks mean fewer round-trips and
     * are more reliable. 64 MiB leaves headroom for multipart overhead.
     */
    const UPLOAD_CHUNK_SIZE = 67108864;

    /**
     * Files up to this size are sent in a single request. A single POST is
     * more reliable than a chunked upload, and Wikimedia's post_max_size
     * (100 MiB) allows it; 75 MiB leaves headroom for the multipart overhead.
     */
    const SINGLE_UPLOAD_LIMIT = 78643200;

    /** Seconds to wait between status polls of an asynchronous upload. */
    const UPLOAD_POLL_INTERVAL = 2;

    /** Give up polling an asynchronous upload after this many seconds. */
    const UPLOAD_POLL_TIMEOUT = 1800;

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
        // not let max_execution_time silently kill the request half-way. The
        // curl transfer itself is not counted against the limit on Unix, but a
        // generous finite ceiling also protects against a runaway loop (the
        // async-status polling below runs a PHP loop, not just curl). It also
        // matters on Windows, where system-call time is counted.
        set_time_limit(1800); // 30 minutes

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
     * A successful upload must carry imageinfo.descriptionurl (needed by the
     * "copy the URL" field in the UI and as proof that the file page was
     * actually created). The chunked stash-assembly answer has no real
     * descriptionurl, and a "Success" that cannot be confirmed against the
     * wiki means the file was not published - fail instead of pretending.
     *
     * @param \stdClass $upload
     * @return \stdClass
     */
    protected function completeUploadResult($upload)
    {
        if ($upload->result !== 'Success') {
            return $upload;
        }
        if (!empty($upload->imageinfo->descriptionurl)) {
            return $upload;
        }

        // A normal upload reports the page URL directly. If it is missing,
        // ask the wiki about the file before giving up.
        $filename = $upload->filename ?? '';
        if ($filename !== '') {
            $data = $this->request([
                'action' => 'query',
                'prop' => 'imageinfo',
                'iiprop' => 'url',
                'titles' => 'File:' . $filename,
            ]);

            foreach ((array)($data->query->pages ?? []) as $page) {
                if (!empty($page->imageinfo[0]->descriptionurl)) {
                    $upload->imageinfo = $page->imageinfo[0];
                    return $upload;
                }
            }
        }

        throw new ApiError(
            'The upload was reported as successful, but no file page could be confirmed on ' .
            $this->site . ' ("' . ($filename ?: 'unknown filename') . '"). ' .
            'The file was probably not published; please check and try again.'
        );
    }

    protected function writeUploadProgress($progressFile, $uploaded, $fileSize)
    {
        if (!$progressFile) {
            return;
        }
        $now = microtime(true);
        $finished = $fileSize > 0 && $uploaded >= $fileSize;
        // The curl progress callback fires constantly, and the status file is
        // only polled a few times per second.
        if (!$finished && ($now - $this->lastUploadProgressWrite) < 0.4) {
            return;
        }
        $this->lastUploadProgressWrite = $now;
        @file_put_contents(
            $progressFile,
            (string)json_encode(['uploaded' => (int)$uploaded, 'filesize' => (int)$fileSize])
        );
    }

    /**
     * Chunked upload. MediaWiki does not accept very large files in a single
     * POST, so send the file in UPLOAD_CHUNK_SIZE-byte chunks (action=upload
     * with chunk+offset+filesize+filekey).
     *
     * Note: chunk requests only store the file in an upload stash. When the
     * last chunk is received MediaWiki assembles the stash and answers
     * "Success" with the assembled filekey, but it does NOT create the file
     * page. The final step is a separate action=upload request that has no
     * chunk and only references the filekey, which publishes the file.
     *
     * Both the chunk assembly and the publish are requested with async=1 so
     * that MediaWiki runs them in background jobs and answers "Poll" instead
     * of doing the (potentially minutes-long) work inside the API request.
     * Synchronous assembly/publish of a multi-hundred-MB file can exceed the
     * request time limit and fail at random; polling checkstatus is what makes
     * the chunked path reliable. If the server does not support async it
     * ignores the flag and answers "Success" directly, which this method also
     * handles.
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
                // Run chunk assembly in a background job rather than inside the
                // request (see the method docblock). Ignored when the server
                // does not support async.
                $chunkArgs['async'] = '1';
                // MediaWiki requires the stash filekey from the first chunk on
                // every request that has a non-zero offset.
                if ($filekey !== null) {
                    $chunkArgs['filekey'] = $filekey;
                }

                // Report progress during the chunk as well, otherwise a
                // multi-chunk upload only moves by one chunk
                // (UPLOAD_CHUNK_SIZE / filesize) at a time.
                $chunkProgress = null;
                if ($progressFile !== null) {
                    $chunkProgress = function ($sent) use ($progressFile, $offset, $fileSize) {
                        $this->writeUploadProgress($progressFile, min($offset + $sent, $fileSize), $fileSize);
                    };
                }

                $response = $this->request($chunkArgs, true, true, $chunkProgress)->upload;

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

                if ($response->result === 'Poll') {
                    // The final chunk was queued for assembly in a background
                    // job. Poll checkstatus until the job reports Success (with
                    // the assembled filekey) or a terminal failure.
                    if ($filekey === null && isset($response->filekey)) {
                        $filekey = $response->filekey;
                    }
                    $response = $this->pollAsyncUpload($args['token'], $filekey);
                    if ($response->result !== 'Success') {
                        return $response;
                    }
                    if (isset($response->filekey)) {
                        $filekey = $response->filekey;
                    }
                    break;
                }

                if ($response->result === 'Success') {
                    // Async was not applied (server does not support it): the
                    // chunks were assembled in this request. Remember the
                    // assembled filekey and publish it below.
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
            // stash. This is the request whose result the caller sees. Also
            // requested asynchronously so the (potentially slow) publish runs
            // in a job instead of the request.
            $finalArgs = $args;
            $finalArgs['filekey'] = $filekey;
            $finalArgs['async'] = '1';
            $response = $this->request($finalArgs, true)->upload;
            if ($response->result === 'Poll') {
                $response = $this->pollAsyncUpload($args['token'], $filekey);
            }
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
     * Poll action=upload&checkstatus=1 for an asynchronous upload (chunk
     * assembly or publish) until the background job reports a terminal result.
     *
     * The checkstatus answer mirrors a normal upload response: result is "Poll"
     * while the job is queued or running, and "Success" (with imageinfo, and
     * with the assembled filekey for the chunk-assembly phase) or
     * "Failure"/"Warning" once it is done.
     *
     * @param string $token CSRF token (the same one used for the upload).
     * @param string $filekey Stash key to poll.
     * @return \stdClass Terminal upload result (never "Poll").
     */
    protected function pollAsyncUpload($token, $filekey)
    {
        $deadline = time() + self::UPLOAD_POLL_TIMEOUT;

        while (true) {
            sleep(self::UPLOAD_POLL_INTERVAL);

            $response = $this->request([
                'action' => 'upload',
                'format' => 'json',
                'token' => $token,
                'filekey' => $filekey,
                'checkstatus' => '1',
            ])->upload;

            if ($response->result !== 'Poll') {
                return $response;
            }

            if (time() >= $deadline) {
                throw new ApiError(
                    'Timed out waiting for the asynchronous upload ("' . $filekey . '") to finish.'
                );
            }
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
