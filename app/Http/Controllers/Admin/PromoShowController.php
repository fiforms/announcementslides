<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalShowTemplate;
use App\Models\Slide;
use App\Models\SlideMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin oversight of globally-distributed one-off "promo" shows (see
 * GlobalShowTemplate): what each holds, how many sites carry a copy, a
 * public hot-link, and rename/remove/delete across every site's copy at once.
 * New promos (and new slides in one) are still created from the admin
 * upload form's "Distribute as a separate one-off show" option.
 */
class PromoShowController extends Controller
{
    public function index(): Response
    {
        $promos = GlobalShowTemplate::with('creator')
            ->withCount('shows')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (GlobalShowTemplate $t) {
                $currentIds = $t->slidesQuery()->current()->pluck('slides.id')->all();

                return [
                    'id'          => $t->id,
                    'name'        => $t->name,
                    'sites_count' => $t->shows_count,
                    'creator'     => $t->creator?->only('id', 'name'),
                    'created_at'  => $t->created_at->toIso8601String(),
                    'public_url'  => route('slides.index', ['promo' => $t->token]),
                    'slides'      => $t->slidesQuery()->with('primaryMedia')->get()->map(fn (Slide $s) => [
                        'id'            => $s->id,
                        'title'         => $s->title,
                        'mime_type'     => $s->mime_type,
                        'file_url'      => $s->file_url,
                        'thumbnail_url' => $s->thumbnail_url,
                        'status'        => $s->status,
                        'publish_at'    => $s->publish_at?->toIso8601String(),
                        'expires_at'    => $s->expires_at?->toIso8601String(),
                        'is_current'    => in_array($s->id, $currentIds),
                        'public_url'    => route('slides.show', ['slide' => $s->token, 'promo' => $t->token]),
                    ]),
                ];
            });

        return Inertia::render('Admin/PromoShows', compact('promos'));
    }

    /**
     * Rename the promo. Sites whose copy still carries the old name follow
     * along; a copy a leader has renamed locally keeps their name.
     */
    public function update(Request $request, GlobalShowTemplate $promo)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);

        DB::transaction(function () use ($promo, $data) {
            $promo->shows()->where('name', $promo->name)->update(['name' => $data['name']]);
            $promo->update(['name' => $data['name']]);
        });

        return back()->with('success', 'Promo show renamed.');
    }

    /**
     * Take one slide out of the promo at every site. The slide itself is
     * untouched (manage it from Slides); once the promo has nothing live
     * left, shows:prune-empty sweeps it as usual.
     */
    public function detachSlide(GlobalShowTemplate $promo, Slide $slide)
    {
        DB::table('show_slides')
            ->where('slide_id', $slide->id)
            ->whereIn('show_id', $promo->shows()->select('id'))
            ->delete();

        return back()->with('success', 'Slide removed from the promo show at every site.');
    }

    /**
     * Delete the promo and every site's copy of it (including any frame a
     * leader added to theirs). Its slides stay, managed from Slides.
     */
    public function destroy(GlobalShowTemplate $promo)
    {
        $disk = Storage::disk('public');
        SlideMedia::whereIn('show_id', $promo->shows()->withTrashed()->select('id'))
            ->each(function (SlideMedia $m) use ($disk) {
                $disk->delete($m->allFilePaths());
                $m->delete();
            });

        // Copies cascade with the template (shows.global_template_id).
        $promo->delete();

        return redirect()->route('admin.promo-shows.index')->with('success', 'Promo show deleted from every site.');
    }
}
