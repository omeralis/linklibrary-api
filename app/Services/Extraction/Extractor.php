<?php

namespace App\Services\Extraction;

use App\Services\Http\FetchException;
use App\Services\Url\NormalizedUrl;

interface Extractor
{
    public function name(): string;

    public function supports(NormalizedUrl $url): bool;

    /**
     * @throws ExtractionException
     * @throws FetchException
     */
    public function extract(NormalizedUrl $url): ExtractedMetadata;
}
