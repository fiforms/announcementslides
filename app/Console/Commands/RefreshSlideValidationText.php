<?php

namespace App\Console\Commands;

use App\Models\SlideMedia;
use App\Services\ImageValidationService;
use Illuminate\Console\Command;

/**
 * One-off refresh for slide_media.validation_issues wording after a change
 * to ImageValidationService's message formatting (e.g. rounding megapixels
 * to 1 decimal instead of showing full float precision). Recomputes from
 * the already-stored image_width/image_height/file_size — never re-reads
 * the underlying file — so it's safe to run even if some files are gone.
 */
class RefreshSlideValidationText extends Command
{
    protected $signature = 'slides:refresh-validation-text';

    protected $description = 'Recompute stored image-quality warning text from already-known dimensions/file size';

    public function handle(ImageValidationService $validationService): int
    {
        $updated = 0;

        SlideMedia::whereNotNull('validation_issues')
            ->where('media_type', 'slide')
            ->each(function (SlideMedia $media) use ($validationService, &$updated) {
                if (!$media->mime_type || !str_starts_with($media->mime_type, 'image/')) {
                    return;
                }

                $result = $validationService->validateDimensions($media->image_width, $media->image_height, $media->file_size);

                if ($result['issues'] !== $media->validation_issues || $result['status'] !== $media->validation_status) {
                    $media->update([
                        'validation_issues' => $result['issues'],
                        'validation_status' => $result['status'],
                    ]);
                    $updated++;
                }
            });

        $this->info("Refreshed validation text on {$updated} slide media row(s).");

        return self::SUCCESS;
    }
}
