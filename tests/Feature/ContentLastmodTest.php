<?php

namespace Tests\Feature;

use App\Support\Lastmod;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ContentLastmodTest extends TestCase
{
    private ?string $root = null;

    protected function setUp(): void
    {
        parent::setUp();

        Lastmod::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Lastmod::forget();

        if ($this->root) {
            (new Process(['rm', '-rf', $this->root]))->run();
        }

        parent::tearDown();
    }

    public function test_rebuild_uses_last_commit_date_and_skips_deleted_files(): void
    {
        $this->repo();

        file_put_contents("{$this->root}/content/a.md", "a\n");
        file_put_contents("{$this->root}/content/b.md", "b\n");
        file_put_contents("{$this->root}/content/gone.md", "gone\n");
        $this->commit('2026-01-10T10:00:00+0000');

        file_put_contents("{$this->root}/content/b.md", "b2\n");
        file_put_contents("{$this->root}/content/c.md", "c\n");
        unlink("{$this->root}/content/gone.md");
        $this->commit('2026-03-05T12:00:00+0000');

        file_put_contents("{$this->root}/content/untracked.md", "u\n");

        $this->artisan('content:lastmod', ['--rebuild' => true, '--root' => $this->root])->assertSuccessful();

        $this->assertSame(
            "content/a.md\t2026-01-10T11:00:00+01:00\ncontent/b.md\t2026-03-05T13:00:00+01:00\ncontent/c.md\t2026-03-05T13:00:00+01:00\n",
            file_get_contents("{$this->root}/content/.lastmod"),
        );
    }

    public function test_staged_stamps_changed_files_drops_deleted_and_stages_manifest(): void
    {
        $this->repo();

        file_put_contents("{$this->root}/content/keep.md", "k\n");
        file_put_contents("{$this->root}/content/edit.md", "e\n");
        file_put_contents("{$this->root}/content/remove.md", "r\n");
        $this->commit('2026-01-10T10:00:00+0000');
        $this->artisan('content:lastmod', ['--rebuild' => true, '--root' => $this->root])->assertSuccessful();
        $this->git(['add', '-A']);
        $this->commit('2026-01-10T10:01:00+0000');

        Carbon::setTestNow(Carbon::parse('2026-11-03 09:30:00', 'Europe/Warsaw'));
        file_put_contents("{$this->root}/content/edit.md", "e2\n");
        file_put_contents("{$this->root}/content/new.md", "n\n");
        unlink("{$this->root}/content/remove.md");
        file_put_contents("{$this->root}/outside.txt", "x\n");
        $this->git(['add', '-A']);

        $this->artisan('content:lastmod', ['--staged' => true, '--root' => $this->root])->assertSuccessful();

        $manifest = Lastmod::parseManifest(file_get_contents("{$this->root}/content/.lastmod"));
        $this->assertSame(['content/edit.md', 'content/keep.md', 'content/new.md'], array_keys($manifest));
        $this->assertSame('2026-11-03T09:30:00+01:00', $manifest['content/edit.md']);
        $this->assertSame('2026-11-03T09:30:00+01:00', $manifest['content/new.md']);
        $this->assertSame('2026-01-10T11:00:00+01:00', $manifest['content/keep.md']);

        $staged = $this->git(['diff', '--cached', '--name-only']);
        $this->assertStringContainsString('content/.lastmod', $staged);
    }

    public function test_staged_without_content_changes_and_without_git_never_fails(): void
    {
        $this->repo();
        file_put_contents("{$this->root}/other.txt", "x\n");
        $this->git(['add', '-A']);

        $this->artisan('content:lastmod', ['--staged' => true, '--root' => $this->root])->assertSuccessful();
        $this->assertFileDoesNotExist("{$this->root}/content/.lastmod");

        $plain = sys_get_temp_dir().'/lastmod-nogit-'.uniqid();
        mkdir("{$plain}/content", 0777, true);
        $this->artisan('content:lastmod', ['--staged' => true, '--root' => $plain])->assertSuccessful();
        (new Process(['rm', '-rf', $plain]))->run();
    }

    public function test_manifest_reader_takes_latest_duplicate_and_ignores_broken_lines(): void
    {
        $map = Lastmod::parseManifest(implode("\n", [
            "content/a.md\t2026-01-01T10:00:00+01:00",
            "content/a.md\t2026-02-01T10:00:00+01:00",
            "content/a.md\t2025-12-01T10:00:00+01:00",
            '',
            'garbage without tab',
            "content/b.md\tnot-a-date",
            "\t2026-01-01T10:00:00+01:00",
            "content/c.md\t2026-01-05T10:00:00+01:00",
        ]));

        $this->assertSame(['content/a.md' => '2026-02-01T10:00:00+01:00', 'content/c.md' => '2026-01-05T10:00:00+01:00'], $map);
        $this->assertSame(
            "content/a.md\t2026-02-01T10:00:00+01:00\ncontent/c.md\t2026-01-05T10:00:00+01:00\n",
            Lastmod::formatManifest(array_reverse($map)),
        );
    }

    public function test_priority_updated_at_then_manifest_then_entry_date_then_file_time(): void
    {
        $article = Entry::make()->collection('articles')->slug('lastmod-test')->date('2026-05-01');
        $key = Lastmod::relativePath($article);
        $this->assertSame('content/collections/articles/2026-05-01.lastmod-test.md', $key);

        // 3) entry date (start of the day in Warsaw), not the file time
        $this->assertSame('2026-05-01T00:00:00+02:00', Lastmod::of($article)->toIso8601String());

        // 2) manifest wins over the date
        Lastmod::setManifest([$key => '2026-06-10T08:00:00+02:00']);
        $this->assertSame('2026-06-10T08:00:00+02:00', Lastmod::of($article)->toIso8601String());

        // 1) updated_at (CP edit) wins over the manifest
        $article->set('updated_at', Carbon::parse('2026-07-01 12:00:00', 'Europe/Warsaw')->timestamp);
        $this->assertSame('2026-07-01T12:00:00+02:00', Lastmod::of($article)->toIso8601String());

        // 4) undated entry without manifest falls back to lastModified()
        $page = Entry::make()->collection('pages')->slug('lastmod-undated');
        Lastmod::setManifest([]);
        $this->assertSame(
            Lastmod::warsaw($page->lastModified())->toIso8601String(),
            Lastmod::of($page)->toIso8601String(),
        );
    }

    public function test_cpn_entry_is_latest_of_started_date_and_manifest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', 'Europe/Warsaw'));

        $entry = Entry::make()->collection('cpn_prices')->slug('cpn-max-2026-11-03')->date('2026-11-03');
        $key = Lastmod::relativePath($entry);

        Lastmod::setManifest([$key => '2026-11-03T14:00:00+01:00']);
        $this->assertSame('2026-11-03T14:00:00+01:00', Lastmod::cpnEntry($entry)->toIso8601String());

        Lastmod::setManifest([$key => '2026-10-30T14:00:00+01:00']);
        $this->assertSame('2026-11-03T00:00:00+01:00', Lastmod::cpnEntry($entry)->toIso8601String());

        $future = Entry::make()->collection('cpn_prices')->slug('cpn-max-2026-11-04')->date('2026-11-04');
        Lastmod::setManifest([Lastmod::relativePath($future) => '2026-11-02T09:00:00+01:00']);
        $this->assertSame('2026-11-02T09:00:00+01:00', Lastmod::cpnEntry($future)->toIso8601String());
    }

    private function repo(): void
    {
        $git = new Process(['git', '--version']);
        $git->run();
        $git->isSuccessful() || $this->markTestSkipped('git is not available.');

        $this->root = sys_get_temp_dir().'/lastmod-'.uniqid();
        mkdir("{$this->root}/content", 0777, true);
        $this->git(['init', '-q']);
    }

    private function commit(string $date): void
    {
        $this->git(['add', '-A']);
        $this->git(['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', 'c', '--allow-empty'], [
            'GIT_AUTHOR_DATE' => $date,
            'GIT_COMMITTER_DATE' => $date,
        ]);
    }

    private function git(array $arguments, array $env = []): string
    {
        $process = new Process(['git', ...$arguments], $this->root, $env);
        $process->mustRun();

        return $process->getOutput();
    }
}
