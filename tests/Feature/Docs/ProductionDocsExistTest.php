<?php

namespace Tests\Feature\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 041 — smoke check that the operator handbook is present.
 *
 * These files are the deploy path for a new operator; accidentally
 * losing one (a `git rm docs/*.md`, a stray rebase, an ignore-file
 * misconfiguration) would silently strip the deploy story from the
 * repo. This test catches the deletion, not the content quality.
 *
 * The heading assertion pins a single required top-level heading per
 * file so a truncated / half-written file still trips.
 */
class ProductionDocsExistTest extends TestCase
{
    public static function docFiles(): array
    {
        return [
            'installation guide' => [
                'docs/installation.md',
                '# Installation',
            ],
            'deployment playbook' => [
                'docs/deployment.md',
                '# Deployment playbook',
            ],
            'backup strategy' => [
                'docs/backup.md',
                '# Backup strategy',
            ],
            'production env template' => [
                'docs/env.production.example',
                'APP_ENV=production',
            ],
        ];
    }

    #[DataProvider('docFiles')]
    public function test_production_doc_exists_and_contains_required_marker(
        string $relativePath,
        string $requiredMarker,
    ): void {
        $path = base_path($relativePath);

        $this->assertFileExists(
            $path,
            "Missing operator doc: {$relativePath}",
        );

        $contents = file_get_contents($path);

        $this->assertNotEmpty(
            $contents,
            "Empty operator doc: {$relativePath}",
        );

        $this->assertStringContainsString(
            $requiredMarker,
            $contents,
            "Operator doc {$relativePath} missing required marker: {$requiredMarker}",
        );
    }
}
