<?php

namespace App\Tags;

use Statamic\Facades\GlobalSet;
use Statamic\Tags\Tags;

class AdSlot extends Tags
{
    public function index()
    {
        $placement = $this->params->get('placement');
        $variables = GlobalSet::findByHandle('ads')?->inCurrentSite();

        if (! $placement || ! $variables) {
            return $this->parseNoResults();
        }

        $ads = collect($variables->augmentedValue('slots')->value() ?? [])
            ->filter(fn ($ad) => $ad->raw('placement') === $placement)
            ->shuffle()
            ->take($this->params->int('limit', 1))
            ->values();

        if ($ads->isEmpty()) {
            return $this->parseNoResults();
        }

        return $ads->all();
    }
}
