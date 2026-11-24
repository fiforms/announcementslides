<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalShowTemplate;
use App\Models\Language;
use App\Models\Show;
use App\Models\Slide;
use App\Models\SlideMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin oversight of globally-distributed one-off shows (shown to admins as "Playlists"; "promo" internally and in public ?promo= links) (see
 * GlobalShowTemplate): what each holds, how many sites carry a copy, a
 * public hot-link, and rename/play mode/remove/delete across every site's copy at once.
 * New promos (and new slides in one) are still created from the admin
 * upload form's "Add to a separate playlist" option.
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
                    'play_mode'   => $t->play_mode,
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

        return Inertia::render('Admin/Playlists/Index', compact('promos'));
    }

    /**
     * The regular admin slide uploader, fixed to adding to this one promo
     * (new slides land in it at every site). Saving returns to Playlists.
     */
    public function upload(GlobalShowTemplate $promo): Response
    {
        return Inertia::render('Admin/Playlists/Upload', [
            'promo' => $promo->only('id', 'name'),
            'languages' => Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']),
        ]);
    }

    /**
     * Rename the promo. Sites whose copy still carries the old name follow
     * along; a copy a leader has renamed locally keeps their name.
     */
    public function update(Request $request, GlobalShowTemplate $promo)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'play_mode' => ['sometimes', Rule::in(Show::PLAY_MODES)],
        ]);

        DB::transaction(function () use ($promo, $data) {
            if (isset($data['name'])) {
                $promo->shows()->where('name', $promo->name)->update(['name' => $data['name']]);
            }
            // Like the name, a copy a leader switched to another mode keeps theirs.
            if (isset($data['play_mode'])) {
                $promo->shows()->where('play_mode', $promo->play_mode)->update(['play_mode' => $data['play_mode']]);
            }
            $promo->update($data);
        });

        return back()->with('success', 'Playlist updated.');
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

        return back()->with('success', 'Slide removed from the playlist at every site.');
    }

    /**
     * Delete the promo and every site's copy of it (including any frame a
     * leader added to theirs). Its slides stay, managed from Slides.
     */
    public function destroy(GlobalShowTemplate $promo)
    {
        SlideMedia::whereIn('show_id', $promo->shows()->withTrashed()->select('id'))
            ->each(function (SlideMedia $m) {
                $m->deleteFiles();
                $m->delete();
            });

        // Copies cascade with the template (shows.global_template_id).
        $promo->delete();

        return redirect()->route('admin.playlists.index')->with('success', 'Playlist deleted from every site.');
    }
}
