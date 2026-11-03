<?php

namespace App\Tags;

use App\Support\Lastmod as LastmodSupport;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Cascade;
use Statamic\Structures\Page;
use Statamic\Tags\Tags;

/** `{{ lastmod }}` — ISO 8601 ostatniej zmiany bieżącego wpisu (zamiast `last_modified`, które zależy od mtime pliku). */
class Lastmod extends Tags
{
    public function index(): ?string
    {
        $content = Cascade::instance()->content();

        if ($content instanceof Page && $content->entry()) {
            $content = $content->entry();
        }

        return $content instanceof Entry ? LastmodSupport::of($content)->toIso8601String() : null;
    }
}
