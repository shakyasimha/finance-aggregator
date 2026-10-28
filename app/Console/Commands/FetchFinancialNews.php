<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\Article;
use App\Services\HtmlNewsExtractor;
use Illuminate\Console\Command;

class FetchFinancialNews extends Command
{
    protected $signature = 'news:fetch';
    protected $description = 'Fetch financial news and notices from active sources using the generic extractor';

    public function handle(HtmlNewsExtractor $extractor): int
    {
        $sources = Source::where('is_active', true)->get();

        if ($sources->isEmpty()) {
            $this->info('No active sources found.');
            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->info("Fetching from: {$source->name} ({$source->url})");

            try {
                // 1. Extract articles using your generic extractor (handles RSS, JSON-LD, selectors, and link heuristics)
                $articles = $extractor->extract($source);

                if (empty($articles)) {
                    $this->warn("No articles found for {$source->name}");
                    continue;
                }

                // 2. Optionally enrich missing snippets or dates by crawling top items
                $articles = $extractor->enrich($articles, 15);

                $savedCount = 0;

                // 3. Save or update into Supabase via updateOrCreate
                foreach ($articles as $data) {
                    if (empty($data['url']) || empty($data['title'])) {
                        continue;
                    }

                    Article::updateOrCreate(
                        ['url' => $data['url']],
                        [
                            'source_id' => $source->id,
                            'title' => $data['title'],
                            'snippet' => $data['snippet'] ?? null,
                            'published_at' => $data['published_at'] ?? now(),
                        ]
                    );

                    $savedCount++;
                }

                $this->info("Saved/updated {$savedCount} articles for {$source->name}.");
            } catch (\Exception $e) {
                $this->error("Failed to process {$source->name}: " . $e->getMessage());
            }
        }

        $this->info('News ingestion completed successfully.');
        return self::SUCCESS;
    }
}
