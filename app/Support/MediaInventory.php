<?php

namespace App\Support;

use App\Models\Slide;
use App\Models\SlideAnnouncerRelease;
use App\Models\SlideMedia;
use Illuminate\Support\Facades\Schema;

/**
 * Every stored file the database refers to, and where it should be: media
 * files (every version), their thumbnails and posters, slide composites and
 * Slide Announcer release files. Used by `media:verify` (is everything
 * there?) and by backups, whose manifest lists the offloaded objects a
 * restore needs from the bucket (or from a mirror of it).
 */
class MediaInventory
{
    public function __construct(private MediaStore $store) {}

    /**
     * @return \Generator<array{disk: string, path: string, size: ?int, kind: string, owner: string}>
     *   kind: media | thumbnail | poster | composite | release
     */
    public function files(): \Generator
    {
        $tiered = Schema::hasColumn('slide_media', 'disk');

        foreach (SlideMedia::query()->orderBy('id')->lazyById(500) as $media) {
            $owner = "slide_media #{$media->id}";
            foreach ($media->versions() as $version) {
                if (!empty($version['disk_path'])) {
                    yield [
                        'disk' => $tiered ? ($version['disk'] ?? MediaStore::LOCAL) : MediaStore::LOCAL,
                        'path' => $version['disk_path'],
                        'size' => isset($version['file_size']) ? (int) $version['file_size'] : null,
                        'kind' => 'media',
                        'owner' => $owner,
                    ];
                }
                foreach (['thumbnail' => 'thumbnail_path', 'poster' => 'poster_path'] as $kind => $field) {
                    if (!empty($version[$field])) {
                        yield ['disk' => MediaStore::LOCAL, 'path' => $version[$field], 'size' => null, 'kind' => $kind, 'owner' => $owner];
                    }
                }
            }
        }

        foreach (Slide::withTrashed()->whereNotNull('overlay_thumbnail_path')->lazyById(500) as $slide) {
            yield ['disk' => MediaStore::LOCAL, 'path' => $slide->overlay_thumbnail_path, 'size' => null, 'kind' => 'composite', 'owner' => "slide #{$slide->id}"];
        }

        foreach (SlideAnnouncerRelease::query()->orderBy('id')->lazyById(200) as $release) {
            yield [
                'disk' => $tiered ? ($release->disk ?: MediaStore::LOCAL) : MediaStore::LOCAL,
                'path' => $release->disk_path,
                'size' => null,
                'kind' => 'release',
                'owner' => "release {$release->kind} {$release->version} ({$release->release_type})",
            ];
        }
    }

    /**
     * The objects on offload disks that the database refers to: what a
     * restore needs from the bucket or a mirror of it. Each key appears once.
     *
     * @return list<array{disk: string, key: string, size: ?int}>
     */
    public function offloadedObjects(): array
    {
        $objects = [];
        foreach ($this->files() as $file) {
            if (!$this->store->isLocal($file['disk'])) {
                $objects[$file['disk'] . ':' . $file['path']] = ['disk' => $file['disk'], 'key' => $file['path'], 'size' => $file['size']];
            }
        }

        return array_values($objects);
    }
}
