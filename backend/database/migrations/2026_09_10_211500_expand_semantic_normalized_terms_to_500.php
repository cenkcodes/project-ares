<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Align all semantic normalized-term storage with the canonical
     * video_source_terms.normalized_term VARCHAR(500) contract.
     *
     * We deliberately do not truncate source terms. Semantic identities must
     * remain lossless so unique keys, concept aliases, dirty queues and topic
     * intelligence all refer to the exact same canonical normalized value.
     */
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
ALTER TABLE seo_video_term_memberships
ALTER COLUMN normalized_term TYPE VARCHAR(500)
SQL
        );

        DB::statement(
            <<<'SQL'
ALTER TABLE seo_catalog_dirty_terms
ALTER COLUMN normalized_term TYPE VARCHAR(500)
SQL
        );

        DB::statement(
            <<<'SQL'
ALTER TABLE seo_term_concept_aliases
ALTER COLUMN normalized_term TYPE VARCHAR(500)
SQL
        );
    }

    /**
     * Rollback is intentionally non-destructive.
     *
     * PostgreSQL will reject the rollback automatically if any semantic term
     * currently exceeds 255 characters. We never truncate semantic identities
     * merely to make a rollback succeed.
     */
    public function down(): void
    {
        DB::statement(
            <<<'SQL'
ALTER TABLE seo_term_concept_aliases
ALTER COLUMN normalized_term TYPE VARCHAR(255)
SQL
        );

        DB::statement(
            <<<'SQL'
ALTER TABLE seo_catalog_dirty_terms
ALTER COLUMN normalized_term TYPE VARCHAR(255)
SQL
        );

        DB::statement(
            <<<'SQL'
ALTER TABLE seo_video_term_memberships
ALTER COLUMN normalized_term TYPE VARCHAR(255)
SQL
        );
    }
};
