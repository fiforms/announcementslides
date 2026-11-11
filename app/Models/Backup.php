<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata for one backup archive; see App\Services\Backup\BackupService. */
class Backup extends Model
{
    public const MODE_DATABASE = 'database';
    public const MODE_FULL = 'full';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_CLI = 'cli';
    public const TRIGGER_PRE_MIGRATE = 'pre-migrate';
    public const TRIGGER_PRE_RESTORE = 'pre-restore';

    /** Triggers whose archives are pruned automatically (in two groups). */
    public const AUTO_TRIGGERS = [self::TRIGGER_SCHEDULED];
    public const SAFETY_TRIGGERS = [self::TRIGGER_PRE_MIGRATE, self::TRIGGER_PRE_RESTORE];

    protected $fillable = [
        'filename', 'mode', 'trigger', 'status', 'size', 'error', 'manifest',
        'created_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest'    => 'array',
            'size'        => 'integer',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
