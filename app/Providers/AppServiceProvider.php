<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Statamic\Facades\Collection;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Collection::computed('promotions', 'discount_rank', fn ($entry) => (float) ($entry->get('discount_value') ?? -1));

        Collection::computed('promotions', 'badge', function ($entry) {
            $from = $entry->get('valid_from');
            $to = $entry->get('valid_to');

            if (! $from || ! $to) {
                return null;
            }

            $today = Carbon::parse(now('Europe/Warsaw')->toDateString());
            $from = Carbon::parse($from);
            $to = Carbon::parse($to);

            return match (true) {
                $to->lt($today) => 'expired',
                $from->gt($today) => 'upcoming',
                $to->eq($today) => 'ending_today',
                $today->diffInDays($to) <= 2 => 'ending_soon',
                $from->diffInDays($today) <= 3 => 'new',
                default => null,
            };
        });
    }
}
