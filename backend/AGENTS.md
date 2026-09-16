# Project Ares / Xurvexa — Codex Working Rules

## 1. Scope

This repository is the local development copy of:

Project Ares / Xurvexa

Local backend root:

C:\Projects\Project-Ares\backend

Production backend root:

/var/www/project-ares/backend

Production operations root:

/var/www/project-ares/ops/video-import

Unless an explicit task says otherwise, work ONLY inside the local backend repository.

## 2. Safety — MUST

- Never connect to production unless the task explicitly instructs it.
- Never use SSH unless the task explicitly instructs it.
- Never perform a production deploy unless explicitly instructed.
- Never perform database writes unless explicitly instructed.
- Never run destructive database commands.
- Never run git reset, git clean, git checkout --, restore, rebase, force-push, or similar destructive operations without explicit instruction.
- Never delete or overwrite unrelated existing work.
- The Git working tree may already contain unrelated user changes. Preserve them.
- Do not assume untracked or modified files were created by the current task.
- Do not automatically “clean up” the repository.

## 3. User workflow — MUST

The user works one step at a time.

For every task:

1. Inspect current files first.
2. Change only files required by the task.
3. Do not broaden scope without explicit approval.
4. Validate locally.
5. Report results.
6. Stop and wait for the next instruction.

Do not start the next development stage automatically.

## 4. File editing rules — MUST

When a file needs to be created or replaced:

- Work with the complete file.
- Do not provide or apply partial replacement snippets when a full file replacement is required.
- Preserve unrelated existing behavior.
- Preserve formatting and readable structure.
- Do not compress code merely to reduce file length.
- Always report the exact full Windows path of every created or changed file.

When reporting a new file, label it:

YENİ DOSYA

When reporting a full replacement of an existing file, label it:

MEVCUT DOSYA – TAMAMINI REPLACE ET

## 5. Validation — MUST

For PHP files:

- Run `php -l` after modification.
- Report lint result.

For changed files:

- Calculate SHA256.
- Report the final SHA256.

When relevant:

- run targeted tests;
- run route checks;
- run Blade/view compile checks;
- inspect git diff only for files changed by the task.

Do not treat pre-existing unrelated Git changes as task failures.

## 6. Git — MUST

Git is primarily a visibility and diff tool in this workflow.

Unless explicitly requested:

- do not commit;
- do not push;
- do not create branches;
- do not merge;
- do not stage files.

Before and after a task, distinguish:

- pre-existing changes;
- files changed by the current task.

Never discard pre-existing changes.

## 7. Production — MUST

Production changes are a separate controlled stage.

Local development must succeed before production deployment.

Do not:

- upload files to production;
- SSH to production;
- restart services;
- clear production caches;
- run production Artisan write commands;
- modify the production database

unless the task explicitly authorizes that exact production action.

## MUST — Decision Quality Before Code

This MUST gate applies to all ChatGPT/Codex work on Project Ares, especially SEO, traffic growth, content generation, metadata, internal linking, taxonomy/semantic intelligence, large-scale backfills, architecture changes, automation, and anything replicated across many pages or videos.

Before proposing or implementing a change, do not jump directly to code.

First evaluate whether the proposed change is actually the best decision for:

1. Organic traffic / SEO impact
2. User value
3. Search intent relevance
4. Scalability across the full inventory
5. Boilerplate / duplication risk
6. Keyword stuffing or spam risk
7. Crawl/indexation impact
8. Maintainability and architecture
9. Data quality and factual reliability
10. Opportunity cost versus a higher-value alternative

Do not include a field, sentence, keyword, feature, metric, or data point merely because it is available.

Ask internally:
- Does this information materially help search relevance, CTR, UX, crawlability, or conversion?
- Is there a more valuable use of the same space, engineering effort, or crawl budget?
- Will this still look correct when replicated across 50,000+ pages?
- Does it create repetitive boilerplate?
- Is it factual and directly supported by trusted canonical/precomputed data?
- Could the same signal be used more effectively elsewhere, such as internal linking, structured data, title/H1, category hubs, or topic pages?
- Is the proposed change solving a real problem or merely filling space?

For SEO/content work:
- Quality is more important than target word count.
- Do not fill meta descriptions to an arbitrary character target.
- Do not add generic SEO keywords merely because they have search volume.
- Prefer verified query-relevant canonical information.
- Avoid repeated brand/site-name boilerplate when it adds no useful context.
- Use structured data for facts better suited to structured data instead of forcing them into prose.
- Use semantic/topic intelligence only in the layer where it creates the highest value; do not inject it into prose solely because it exists.
- Before a large-scale backfill or rollout, manually inspect representative real outputs for naturalness, usefulness, search-intent alignment, and factual accuracy.
- A technical PASS is not sufficient for a content/SEO PASS.

For large-scale changes:
- Evaluate the effect at full inventory scale before rollout.
- Run a small representative production sample first.
- Review actual generated outputs, not only counters/tests.
- Do not expand to the full inventory until quality is explicitly acceptable.

When several technically valid options exist:
- choose the safest, highest-value, most sustainable option;
- do not ask the user to choose between implementation details unless user preference is genuinely required.

If current knowledge is insufficient to make a high-quality decision:
- research first when current external information would materially improve the decision;
- inspect the existing architecture/data before inventing a new mechanism;
- do not guess.

Core principle:

"First decide whether it is worth doing. Then decide the best way to do it. Only then write code."

Production samples remain subject to the explicit authorization requirements in Sections 2 and 7; this gate does not authorize production access or writes.

### Project-specific interpretation

For Xurvexa specifically, traffic growth priority remains:

crawl/index quality
→ semantic/query coverage
→ useful landing/watch-page content
→ internal linking
→ impressions
→ organic sessions
→ engagement
→ monetization

Do not optimize low-value metadata while a higher-value traffic bottleneck remains.

Do not let technically interesting work displace higher-impact traffic work.

### User override phrase

Also record:

If the user says:

"Karar kalitesi"

treat it as an explicit instruction to stop before implementation and
re-evaluate the proposed solution from first principles using the
Decision Quality Before Code rules.

However, these rules MUST be applied even when the user does not say
"Karar kalitesi".

## 8. Architecture principles — MUST

Project Ares uses a provider-independent canonical architecture.

New development should prefer:

- provider-independent canonical core;
- reusable services;
- configuration-driven behavior;
- persisted/precomputed intelligence;
- idempotent processing;
- fingerprints and versioning;
- incremental reconciliation;
- periodic full reconciliation where justified;
- measurable quality gates;
- failure-safe pipelines.

Avoid:

- provider-specific SEO hacks;
- render-time semantic generation;
- hard-coded one-off fixes in the core;
- duplicated business logic;
- speculative abstractions unrelated to the current task.

## 9. SEO architecture — MUST

SEO intelligence must attach to the canonical database layer, not provider-specific importer logic.

Important rules:

- generated SEO content is persisted;
- do not generate SEO content during page rendering;
- original provider descriptions remain separate from generated SEO editorial content;
- avoid thin spun content;
- avoid blind combinatorial landing-page generation;
- only quality-gated content should become indexable;
- internal linking should be semantic and useful;
- sitemap exposure must be controlled.

Traffic priority order:

crawl/index
→ semantic coverage
→ useful landing pages
→ impressions
→ organic sessions
→ engagement
→ monetization optimization

Do not prioritize low-value admin polish or speculative advertising work while organic traffic remains the primary objective.

## 10. Reusability for future sites — MUST

Xurvexa is intended to become the reusable base for future category-focused sites.

When touching new architecture, prefer reusable/config-driven implementation when practical.

However:

- do not initiate a large platform refactor solely for future reuse;
- improve reusability naturally while working on required modules;
- site-specific branding/content must remain separable from reusable core logic.

## 11. Closed systems — MUST

Do not reopen already closed and validated systems without concrete evidence of regression or an explicit request.

In particular:

- Topic Intelligence V1 is CLOSED / PASS.
- Topic quality ruleset v1.1 is CLOSED / PASS.
- Concept Intelligence V1 is CLOSED / PASS.
- Category Relation V2.1 is CLOSED / PASS.
- Internal Discovery is CLOSED / PASS.
- Traffic Analytics V2 is CLOSED / PASS.
- Video Sitemap V2.1 is CLOSED / PASS.

Do not repeat calibration, audits or rebuilds for these systems unless a real trigger exists.

## 12. Current Topic baseline

Current authoritative production Topic baseline:

- generator version: topic-intelligence-v1
- quality ruleset: topic-quality-v1.1
- stored topics: 425
- indexable topics: 117
- categories with indexable topics: 24
- topic components: 850
- materialized topic-video memberships: 118980
- Topic content rows: 0
- dirty concept terms: 0

Only topics satisfying the public eligibility rules may become landing pages.

Candidate, generic_hold, redundant_hold and retired topics must not become public SEO landing pages.

## 13. Current active development

Current development track:

Topic Landing Page V1

Current local files already created and validated:

C:\Projects\Project-Ares\backend\app\Models\SeoTopic.php

Expected SHA256:

5BF81CB25451BE27A9360EB2F4A94416BA8333B5AB92117F89DFBF5621330B9F

C:\Projects\Project-Ares\backend\app\Http\Controllers\TopicController.php

Expected SHA256:

9CED13153A759E925CBE3800AD004FA0A830BB014DDE01E343F16D5B70CCDD6A

These files passed `php -l`.

Topic Landing Page V1 currently has:

- no public Topic route yet;
- no Topic Blade view yet;
- no sitemap exposure yet;
- no Topic editorial content yet.

Do not expose Topic pages to search-engine indexing until the controlled index-exposure stage.

## 14. Communication/reporting

At the end of each coding task, report only useful information:

CHANGED_FILES=
NEW_FILES=
LINT=
TESTS=
SHA256=
GIT_DIFF_SCOPE=
DATABASE_WRITE=
PRODUCTION_CHANGE=
RESULT=PASS or FAIL

If something unexpected is discovered, stop and report it rather than silently redesigning the system.
