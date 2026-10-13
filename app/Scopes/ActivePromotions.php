<?php

namespace App\Scopes;

use Statamic\Contracts\Query\Builder;
use Statamic\Facades\Entry;
use Statamic\Fields\Value;
use Statamic\Query\Scopes\Scope;

class ActivePromotions extends Scope
{
    public function apply($query, $values)
    {
        $today = now('Europe/Warsaw')->toDateString();

        if ($values->get('status') === 'expired') {
            $query->whereDate('valid_to', '<', $today);
        } else {
            $query->whereDate('valid_to', '>=', $today);
        }

        $stations = collect($this->resolve($values->get('stations')))
            ->map(fn ($station) => is_object($station) ? $station->id() : $station)
            ->filter()
            ->all();

        if ($stationId = $this->stationIdFromSlug($values->get('station_slug'))) {
            $stations = [$stationId];
        }

        if ($stations) {
            $query->whereIn('station', $stations);
        }

        $fuelType = $this->resolve($values->get('fuel_type'));

        if (is_object($fuelType)) {
            $fuelType = $fuelType->slug();
        }

        if (is_string($fuelType) && preg_match('/^[a-z0-9-]{1,40}$/', $fuelType)) {
            $query->whereJsonContains('paliwa', $fuelType);
        }

        if ($values->get('no_app')) {
            $query->where('requires_app', '!=', true);
        }

        if ($exclude = $values->get('exclude')) {
            $query->where('id', '!=', $exclude);
        }
    }

    private function stationIdFromSlug($slug)
    {
        if (! is_string($slug) || ! preg_match('/^[a-z0-9-]{1,40}$/', $slug)) {
            return null;
        }

        return Entry::query()
            ->where('collection', 'stations')
            ->where('slug', $slug)
            ->first()
            ?->id();
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
