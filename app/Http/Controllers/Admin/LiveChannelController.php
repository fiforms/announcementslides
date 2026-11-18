<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveChannel;
use App\Support\LiveChannelSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The global catalog of live channels church leaders can pick as a show's
 * background. Admin-only: a channel puts a third party's live content on
 * church screens, so it is vetted here once rather than per church. Editing
 * a channel's link updates every show using it at the next sync.
 */
class LiveChannelController extends Controller
{
    public function index(): Response
    {
        $channels = LiveChannel::withCount('shows')->orderBy('name')->get()->map(fn (LiveChannel $c) => [
            'id'             => $c->id,
            'name'           => $c->name,
            'provider'       => $c->provider,
            'provider_label' => LiveChannelSource::label($c->provider),
            'kind'           => LiveChannelSource::kind($c->provider),
            'url'            => $c->embedUrl(false),
            'supports_mute'  => $c->supportsMute(),
            'shows_count'    => $c->shows_count,
        ]);

        return Inertia::render('Admin/LiveChannels', [
            'channels'  => $channels,
            'providers' => collect(LiveChannelSource::PROVIDERS)->map(fn ($p) => $p['label'])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        LiveChannel::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Live channel added.');
    }

    public function update(Request $request, LiveChannel $liveChannel): RedirectResponse
    {
        $liveChannel->update($this->validated($request));

        return back()->with('success', 'Live channel saved.');
    }

    /** Shows using it lose their background (the foreign key nulls out). */
    public function destroy(LiveChannel $liveChannel): RedirectResponse
    {
        $liveChannel->delete();

        return back()->with('success', 'Live channel deleted.');
    }

    /** @return array{name: string, provider: string, source: array} */
    private function validated(Request $request): array
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'url'  => 'required|string|max:2000',
        ]);

        $parsed = LiveChannelSource::parse($request->input('url'));
        if (!$parsed) {
            throw ValidationException::withMessages([
                'url' => 'Not a supported player link. Paste a JetStream or Zype embed link (or the iframe code), or a direct https .m3u8 stream.',
            ]);
        }
        $provider = $parsed['provider'];
        unset($parsed['provider']);

        return ['name' => trim($request->input('name')), 'provider' => $provider, 'source' => $parsed];
    }
}
