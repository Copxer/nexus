<?php

namespace App\Models;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'priority',
        'environment',
        'color',
        'icon',
        'health_score',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'priority' => ProjectPriority::class,
            'health_score' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Auto-generate the slug on create. If the natural slug collides we
     * append a 3-char alpha suffix — distinguishable yet compact, and
     * doesn't leak the project count the way a sequential counter would.
     *
     * TODO(multi-team): The check-then-insert is TOCTOU-vulnerable under
     * concurrent inserts; the unique index would 500 the second request.
     * Acceptable while phase-1 is single-user dev. When we go multi-tenant,
     * wrap this in a transactional retry loop or a generated-column slug.
     */
    protected static function booted(): void
    {
        static::creating(function (self $project): void {
            if ($project->slug) {
                return;
            }

            $base = Str::slug($project->name);
            $candidate = $base !== '' ? $base : 'project';

            while (static::where('slug', $candidate)->exists()) {
                $candidate = $base.'-'.Str::lower(Str::random(3));
            }

            $project->slug = $candidate;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
