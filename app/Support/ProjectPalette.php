<?php

namespace App\Support;

/**
 * Single source of truth for the curated project color + icon palette.
 *
 * Used by:
 *  - validation (`Store/UpdateProjectRequest::rules()`)
 *  - form-options (`ProjectController::formOptions()`)
 *  - factory (`ProjectFactory`)
 *  - seeder (`ProjectSeeder`)
 *
 * The frontend mirrors the icon list in `resources/js/lib/projectIcons.ts`
 * and the color tokens in the `KpiCard` accent map. Keep all four in sync.
 */
final class ProjectPalette
{
    /** Token-aligned color shorthands used for a project's accent. */
    public const COLORS = [
        'cyan',
        'blue',
        'purple',
        'magenta',
        'success',
        'warning',
    ];

    /** Curated lucide-vue-next icon names safe to render on the dashboard. */
    public const ICONS = [
        'FolderKanban', 'Rocket', 'GitBranch', 'Server',
        'Globe', 'BarChart3', 'Bell', 'Activity',
        'HeartPulse', 'Cpu', 'Database', 'Cloud',
    ];
}
