<?php

namespace App\Console\Commands;

use App\Support\MediaCache;
use Illuminate\Console\Command;

class MediaPruneCache extends Command
{
    protected $signature = 'media:prune-cache
                            {--all : Empty the cache, even files in recent use}';

    protected $description = 'Trim the local cache of offloaded media to its size target (least recently used first)';

    public function handle(MediaCache $cache): int
    {
        $result = $cache->prune((bool) $this->option('all'));

        $this->info(sprintf(
            'Evicted %d file(s), freed %.1f MB; cache is now %.1f MB (target %.1f MB).',
            $result['evicted'],
            $result['freed'] / 1048576,
            $result['size'] / 1048576,
            config('media.cache.max_bytes') / 1048576,
        ));

        return self::SUCCESS;
    }
}
