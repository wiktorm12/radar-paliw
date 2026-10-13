<?php

namespace App\Scopes;

use Statamic\Query\Scopes\Scope;

class ArticlesFilter extends Scope
{
    public function apply($query, $values)
    {
        if ($category = $values->get('category')) {
            $query->whereJsonContains('kategorie', $category);
        }

        if ($station = $values->get('station')) {
            $query->whereJsonContains('stations', $station);
        }

        if ($exclude = $values->get('exclude')) {
            $query->where('id', '!=', $exclude);
        }
    }
}
