<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

interface ExtractorInterface
{
    public function extract(string $zipFile): Result;

    public function cleanup(string $extractedRoot): void;
}
