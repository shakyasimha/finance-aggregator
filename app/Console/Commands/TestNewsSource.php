<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\HtmlNewsExtractor;
use Illuminate\Console\Command;

class TestNewsSource extends Command
{
    protected $signature = 'news:test {url : The page or feed URL to test} {--limit=5 : How many articles to show}';

    protected $description = 'Preview what the extractor finds for a URL without saving anything';

    public function handle(HtmlNewsExtractor $extractor): int
    {
        $url = (string) $this->argument('url');

        $this->info("Testing: {$url}");

        $articles = $extractor->extract(new Source(['url' => $url]));

        if ($articles === []) {
            $this->warn('No articles found (fetch failed, page is JS-rendered, or nothing matched).');

            return self::FAILURE;
        }

        $this->info('Found ' . count($articles) . ' article(s). Showing the first ' . $this->option('limit') . ':');

        $this->table(
            ['Title', 'URL', 'Date', 'Snippet'],
            collect($articles)
                ->take((int) $this->option('limit'))
                ->map(fn(array $a): array => [
                    mb_strimwidth($a['title'], 0, 60, '…'),
                    mb_strimwidth($a['url'], 0, 60, '…'),
                    $a['published_at'] ?? '-',
                    mb_strimwidth((string) ($a['snippet'] ?? '-'), 0, 40, '…'),
                ])
                ->all(),
        );

        return self::SUCCESS;
    }
}
