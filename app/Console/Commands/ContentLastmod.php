<?php

namespace App\Console\Commands;

use App\Support\Lastmod;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

class ContentLastmod extends Command
{
    protected $signature = 'content:lastmod
        {--rebuild : Rebuild the whole manifest from git history}
        {--staged : Pre-commit mode: stamp staged content files with the current time and git add the manifest}
        {--root= : Repository root (defaults to the application base path)}';

    protected $description = 'Maintain the content/.lastmod manifest (path<TAB>ISO 8601 of the last change)';

    public function handle(): int
    {
        $root = rtrim($this->option('root') ?: base_path(), '/');

        if ($this->option('staged')) {
            try {
                return $this->staged($root);
            } catch (\Throwable $e) {
                // Never block a commit.
                $this->warn('content:lastmod skipped: '.$e->getMessage());

                return self::SUCCESS;
            }
        }

        if ($this->option('rebuild')) {
            return $this->rebuild($root);
        }

        $this->error('Pass --rebuild or --staged.');

        return self::INVALID;
    }

    private function rebuild(string $root): int
    {
        $log = $this->git($root, ['-c', 'core.quotepath=false', 'log', '--name-only', '--format=@%cI', '--', 'content']);

        if ($log === null) {
            $this->error('git failed or this is not a git repository.');

            return self::FAILURE;
        }

        if ($this->git($root, ['rev-parse', '--is-shallow-repository']) === "true\n") {
            $this->warn('Shallow clone: dates come from the truncated history and may be inaccurate.');
        }

        $map = [];
        $date = null;

        // Newest commit first, so the first sighting of a path is its last change.
        foreach (preg_split('/\R/', $log) as $line) {
            if ($line === '') {
                continue;
            }

            if ($line[0] === '@') {
                $date = Carbon::parse(substr($line, 1))->setTimezone(Lastmod::TIMEZONE)->toIso8601String();

                continue;
            }

            if ($line === Lastmod::MANIFEST || isset($map[$line]) || ! is_file("{$root}/{$line}")) {
                continue;
            }

            $map[$line] = $date;
        }

        $this->write($root, $map);
        $this->info('Manifest rebuilt: '.count($map).' file(s).');

        return self::SUCCESS;
    }

    private function staged(string $root): int
    {
        $changed = $this->stagedFiles($root, 'ACMR');
        $deleted = $this->stagedFiles($root, 'D');

        if ($changed === [] && $deleted === []) {
            return self::SUCCESS;
        }

        $map = Lastmod::readManifest("{$root}/".Lastmod::MANIFEST);
        $now = Carbon::now(Lastmod::TIMEZONE)->toIso8601String();

        foreach ($changed as $file) {
            $map[$file] = $now;
        }

        foreach ($deleted as $file) {
            unset($map[$file]);
        }

        $this->write($root, $map);

        if ($this->git($root, ['add', '--', Lastmod::MANIFEST]) === null) {
            $this->warn('content:lastmod: git add of the manifest failed.');
        }

        return self::SUCCESS;
    }

    /** Staged content files (renames count as delete + add), without the manifest itself. */
    private function stagedFiles(string $root, string $filter): array
    {
        $output = $this->git($root, ['-c', 'core.quotepath=false', 'diff', '--cached', '--name-only', '-z', '--no-renames', "--diff-filter={$filter}", '--', 'content/']);

        if ($output === null) {
            throw new \RuntimeException('git diff failed');
        }

        return array_values(array_filter(
            explode("\0", $output),
            fn ($file) => $file !== '' && $file !== Lastmod::MANIFEST,
        ));
    }

    private function write(string $root, array $map): void
    {
        $file = "{$root}/".Lastmod::MANIFEST;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        if (file_put_contents($file, Lastmod::formatManifest($map)) === false) {
            throw new \RuntimeException("cannot write {$file}");
        }
    }

    private function git(string $root, array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $root);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
