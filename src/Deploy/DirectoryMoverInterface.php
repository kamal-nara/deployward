<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

interface DirectoryMoverInterface
{
    public function move(string $source, string $dest): Result;
}
