<?php

namespace App\Jobs;

use App\Models\LibraryItem;
use App\Models\SlideMedia;
use App\Support\MediaStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Moves a library background's file to the offload disk (when configured and
 * eligible, see MediaStore::eligible) and repoints every row that shares it —
 * the library item and each slide's media row — before deleting the local
 * copy. Rows are repointed twice, so one created from the item while the copy
 * was uploading is caught too. Idempotent.
 */
class OffloadLibraryItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [60, 300];

    public function __construct(public LibraryItem $item) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('offload-library-' . $this->item->id))->releaseAfter(60)->expireAfter(3600)];
    }

    public function handle(MediaStore $store): void
    {
        $item = $this->item->fresh();
        $target = $store->offloadDisk();
        if (!$item || !$target || $item->isTemplate() || !$item->disk_path || !$store->isLocal($item->disk)
            || !$store->eligible($item->mime_type, $item->file_size)) {
            return;
        }
        // A video needs its poster first, so its thumbnail never needs the file back.
        if ($item->isVideo() && !$item->poster_path) {
            return;
        }
        if (!$store->copyToOffload($item->disk_path, $item->mime_type)) {
            return;
        }

        $path = $item->disk_path;
        $from = $item->disk;
        $repoint = function () use ($path, $from, $target) {
            LibraryItem::where('disk_path', $path)->where('disk', $from)->update(['disk' => $target]);
            SlideMedia::where('disk_path', $path)->where('disk', $from)->update(['disk' => $target]);
            // A resized slide keeps the library file as its 'original' variant.
            SlideMedia::whereNotNull('variants')->get()->each(function (SlideMedia $m) use ($path, $from, $target) {
                $variants = $m->variants;
                $changed = false;
                foreach ($variants as $name => $v) {
                    if (($v['disk_path'] ?? null) === $path && ($v['disk'] ?? MediaStore::LOCAL) === $from) {
                        $variants[$name]['disk'] = $target;
                        $changed = true;
                    }
                }
                $changed && $m->forceFill(['variants' => $variants])->save();
            });
        };

        $repoint();
        $repoint();
        $store->delete($from, $path);
    }
}
