<?php

namespace App\Console\Commands;

use App\Models\GlobalShowTemplate;
use App\Models\Show;
use Illuminate\Console\Command;

/**
 * Deletes auto-created, globally-distributed one-off shows (shows.
 * auto_delete_when_empty) once none of their linked slides are current
 * anymore — e.g. a special promo video's expiry has passed. Leader-created
 * custom shows and every Main show / the Global Board are never eligible
 * (auto_delete_when_empty defaults to false for those), so an intentionally
 * empty custom show is never swept.
 */
class PruneEmptyShows extends Command
{
    protected $signature = 'shows:prune-empty';

    protected $description = 'Delete auto-created one-off shows that have no currently-active slides left';

    public function handle(): int
    {
        $deleted = 0;
        $templateIds = [];

        Show::where('auto_delete_when_empty', true)
            ->whereDoesntHave('slides', fn ($q) => $q->current())
            ->each(function (Show $show) use (&$deleted, &$templateIds) {
                if ($show->global_template_id) {
                    $templateIds[] = $show->global_template_id;
                }
                $show->delete();
                $deleted++;
            });

        $orphanedTemplates = GlobalShowTemplate::whereIn('id', array_unique($templateIds))
            ->whereDoesntHave('shows')
            ->delete();

        $this->info("Pruned {$deleted} empty show(s) and {$orphanedTemplates} orphaned template(s).");

        return self::SUCCESS;
    }
}
