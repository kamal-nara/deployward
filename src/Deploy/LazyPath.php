<?php

namespace Deployward\Deploy;

final class LazyPath
{
    /** @var string|\Closure */
    private $source;
    /** @var ?string */
    private $resolved = null;

    /** @param string|\Closure $source */
    public function __construct($source)
    {
        $this->source = $source;
    }

    public function get(): string
    {
        if ($this->resolved === null) {
            $path = $this->source instanceof \Closure ? call_user_func($this->source) : $this->source;
            $this->resolved = rtrim((string) $path, '/');
        }

        return $this->resolved;
    }
}
