<?php

namespace App\Scopes;

use Statamic\Contracts\Query\Builder;
use Statamic\Fields\Value;
use Statamic\Query\Scopes\Scope;

class ActivePromotions extends Scope
{
    public function apply($query, $values)
    {
        $query->whereDate('valid_to', '>=', now()->toDateString());

        $stations = collect($this->resolve($values->get('stations')))
            ->map(fn ($station) => is_object($station) ? $station->id() : $station)
            ->filter()
            ->all();

        if ($stations) {
            $query->whereIn('station', $stations);
        }

        $fuelType = $this->resolve($values->get('fuel_type'));

        if (is_object($fuelType)) {
            $fuelType = $fuelType->slug();
        }

        if ($fuelType) {
            $query->whereJsonContains('fuel_types', $fuelType);
        }
    }

    private function resolve($value)
    {
        if ($value instanceof Value) {
            $value = $value->value();
        }

        if ($value instanceof Builder) {
            return $value->get();
        }

        return $value;
    }
}
