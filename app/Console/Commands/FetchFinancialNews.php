<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Source;
use App\Services\HtmlNewsExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchFinancialNews extends Command
{
    protected $signature = 'news:fetch';

    protected $description = 'Fetch financial news and notices from active sources';

    public function handle(HtmlNewsExtractor $extractor): int
    {
        $sources = Source::where('is_active', true)->get();

        if ($sources->isEmpty()) {
            $this->info('No active sources found.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->info("Fetching from: {$source->name}");

            try {
                $this->ingest($source, $extractor);
            } catch (\Throwable $e) {
                Log::warning("News fetch failed for source {$source->id}", [
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to fetch from {$source->name}: {$e->getMessage()}");
            }
        }

        $this->info('News ingestion completed.');

        return self::SUCCESS;
    }

    protected function ingest(Source $source, HtmlNewsExtractor $extractor): void
    {
        $articles = $extractor->extract($source);

        if ($articles === []) {
            $this->warn('  no articles found');

            return;
        }

        $existing = Article::whereIn('url', array_column($articles, 'url'))
            ->pluck('url')
            ->all();

        $new = array_values(array_filter(
            $articles,
            fn(array $article): bool => ! in_array($article['url'], $existing, true),
        ));

        if ($new === []) {
            $this->line('  nothing new');

            return;
        }

        foreach ($extractor->enrich($new) as $article) {
            Article::create([
                'source_id' => $source->id,
                'title' => mb_substr($article['title'], 0, 255),
                'url' => $article['url'],
                'snippet' => $article['snippet'],
                'published_at' => $article['published_at'] ?? now(),
            ]);
        }

        $this->line('  stored ' . count($new) . ' new article(s)');
    }
}
