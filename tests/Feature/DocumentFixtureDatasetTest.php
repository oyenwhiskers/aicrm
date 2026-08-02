<?php

namespace Tests\Feature;

use Tests\Support\DocumentFixtureDataset;
use Tests\TestCase;

class DocumentFixtureDatasetTest extends TestCase
{
    public function test_document_fixture_manifest_has_required_top_level_structure(): void
    {
        $manifest = DocumentFixtureDataset::load();

        $this->assertArrayHasKey('schema_version', $manifest);
        $this->assertSame(1, $manifest['schema_version']);
        $this->assertArrayHasKey('last_reviewed_at', $manifest);
        $this->assertArrayHasKey('fixtures', $manifest);
        $this->assertIsArray($manifest['fixtures']);
    }

    public function test_document_fixture_entries_follow_expected_schema(): void
    {
        $manifest = DocumentFixtureDataset::load();
        $ids = [];

        foreach ($manifest['fixtures'] as $fixture) {
            $this->assertIsArray($fixture);
            $this->assertArrayHasKey('id', $fixture);
            $this->assertArrayHasKey('file_path', $fixture);
            $this->assertArrayHasKey('document_type', $fixture);
            $this->assertArrayHasKey('expected_outcome', $fixture);
            $this->assertArrayHasKey('workflow_ready', $fixture);
            $this->assertArrayHasKey('quality_tags', $fixture);
            $this->assertArrayHasKey('expected', $fixture);

            $this->assertIsString($fixture['id']);
            $this->assertNotSame('', trim($fixture['id']));
            $this->assertFalse(in_array($fixture['id'], $ids, true), "Duplicate fixture id [{$fixture['id']}].");
            $ids[] = $fixture['id'];

            $this->assertContains($fixture['document_type'], DocumentFixtureDataset::ALLOWED_DOCUMENT_TYPES);
            $this->assertContains($fixture['expected_outcome'], DocumentFixtureDataset::ALLOWED_OUTCOMES);
            $this->assertIsBool($fixture['workflow_ready']);
            $this->assertIsArray($fixture['quality_tags']);
            $this->assertIsArray($fixture['expected']);

            foreach ($fixture['quality_tags'] as $tag) {
                $this->assertContains($tag, DocumentFixtureDataset::ALLOWED_QUALITY_TAGS);
            }

            $absolutePath = DocumentFixtureDataset::fixtureAbsolutePath($fixture);
            $this->assertFileExists($absolutePath, "Fixture file missing for [{$fixture['id']}]: {$absolutePath}");
        }
    }
}
