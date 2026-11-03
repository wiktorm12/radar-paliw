<?php

namespace App\Console\Commands;

use App\Support\CpnPrices;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CpnTelegram extends Command
{
    protected $signature = 'cpn:telegram {--force : Wyślij ponownie, nawet jeśli ta data była już wysłana}';

    protected $description = 'Wysyła na kanał Telegram najnowsze ceny maksymalne paliw (CPN)';

    private const MARKER = 'telegram/cpn-last-date';

    private const LINK = 'https://radarpaliw.pl/program-cpn?utm_source=telegram&utm_medium=social';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            $this->warn('Telegram nie jest skonfigurowany (TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID) — pomijam.');

            return self::SUCCESS;
        }

        $latest = CpnPrices::latest();

        if (! $latest) {
            $this->warn('Brak cen maksymalnych do wysłania.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('local');

        if (! $this->option('force') && $disk->exists(self::MARKER) && trim($disk->get(self::MARKER)) === $latest['date']) {
            $this->info("Ceny z {$latest['date']} już wysłane — pomijam.");

            return self::SUCCESS;
        }

        try {
            $response = Http::acceptJson()->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $this->message($latest),
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            $this->error('Telegram: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $this->error('Telegram odrzucił wiadomość (HTTP '.$response->status().'): '.$response->json('description'));

            return self::FAILURE;
        }

        $disk->put(self::MARKER, $latest['date']);
        $this->info("Wysłano ceny z {$latest['date']}.");

        return self::SUCCESS;
    }

    private function message(array $latest): string
    {
        $date = Carbon::parse($latest['date'], 'Europe/Warsaw');
        $today = Carbon::now('Europe/Warsaw')->startOfDay();
        $when = match (true) {
            $date->isSameDay($today) => 'dziś, '.$date->format('j.m.Y'),
            $date->isAfter($today) => 'jutro, '.$date->format('j.m.Y'),
            default => $date->format('j.m.Y'),
        };

        $lines = ["<b>Ceny maksymalne paliw ({$when})</b>", ''];

        foreach ($latest['fuels'] as $fuel) {
            $change = CpnPrices::grosze($fuel['diff']);
            $arrow = $fuel['diff'] === null || $fuel['diff'] == 0 ? '' : ($fuel['diff'] > 0 ? '▲ ' : '▼ ');
            $lines[] = $fuel['label'].': <b>'.CpnPrices::money($fuel['price']).' zł/l</b>'.($change !== null ? " ({$arrow}{$change})" : '');
        }

        $lines[] = '';
        $lines[] = 'Wykres, prognoza i historia: <a href="'.htmlspecialchars(self::LINK, ENT_QUOTES).'">radarpaliw.pl/program-cpn</a>';

        return implode("\n", $lines);
    }
}
