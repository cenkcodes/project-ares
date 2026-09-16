<?php

return [
    'index' => [
        'title' => 'Adult Video Category Guides | Xurvexa',
        'h1' => 'Xurvexa Category Guides',
        'meta_description' => 'Explore Xurvexa guides that explain how related adult video categories differ and how the site organizes canonical browsing destinations.',
        'intro' => 'These editorial guides explain the differences between closely related Xurvexa categories. They are designed to make browsing clearer, show how source tags can overlap, and connect visitors to the most relevant canonical category pages.',
        'updated_at' => '2026-09-02',
    ],

    'guides' => [
        'milf-vs-mature' => [
            'is_active' => true,
            'title' => 'MILF vs Mature Videos: What\'s the Difference? | Xurvexa',
            'h1' => 'MILF vs Mature: Understanding the Difference',
            'meta_description' => 'Learn how MILF and Mature differ as browsing categories on Xurvexa, why they stay separate, and where their themes can overlap.',
            'intro' => 'MILF and Mature can appear close in adult-video searches, but they represent different browsing intents. Xurvexa keeps them as separate canonical categories so visitors can move between a specific MILF-focused collection and a broader Mature collection without treating the two labels as interchangeable.',
            'published_at' => '2026-09-02',
            'updated_at' => '2026-09-02',
            'category_links' => [
                [
                    'slug' => 'milf',
                    'label' => 'MILF Videos',
                    'context' => 'Browse the dedicated MILF collection.',
                ],
                [
                    'slug' => 'mature',
                    'label' => 'Mature Videos',
                    'context' => 'Browse the broader Mature collection.',
                ],
            ],
            'sections' => [
                [
                    'title' => 'The short version',
                    'paragraphs' => [
                        'MILF is a more specific performer archetype and search intent. People using the term are usually looking for content explicitly presented or tagged around that established adult-category label. Mature is broader: it groups content centered on older or experienced adult performers across a wider range of scene types and presentation styles.',
                        'That distinction matters because a broad age-oriented theme and a specific category archetype are not the same thing. Keeping separate pages gives each intent a clear destination while still allowing individual videos to carry source tags that overlap with both ideas.',
                    ],
                ],
                [
                    'title' => 'How Xurvexa treats MILF',
                    'paragraphs' => [
                        'On Xurvexa, MILF is a canonical browsing category rather than a synonym for every video featuring an older adult performer. The page is intended for videos whose primary category assignment is MILF, while related source terms can include common MILF-oriented wording. This keeps the category focused instead of expanding it until it becomes indistinguishable from Mature.',
                        'Terms such as “milf” and “milfs” naturally support this browsing intent. Xurvexa also keeps the established “cougar” and “cougars” source aliases with MILF. Those aliases help normalize provider terminology, but they do not change the broader distinction between the MILF and Mature category pages.',
                    ],
                ],
                [
                    'title' => 'How Xurvexa treats Mature',
                    'paragraphs' => [
                        'Mature is the wider age-style category. Its purpose is to collect videos where an older or experienced adult performer is a central characteristic without requiring the more specific MILF archetype. That makes Mature useful for visitors who want a broader collection and do not want results restricted to one narrower label.',
                        'The source terms “mature” and “mature-woman” therefore resolve to the Mature category in Xurvexa taxonomy. This is deliberately separate from MILF so future imports and category discovery follow the meaning of the term instead of an older convenience mapping.',
                    ],
                ],
                [
                    'title' => 'Why the same video can still have overlapping tags',
                    'paragraphs' => [
                        'A video has one primary canonical category for Xurvexa browsing, but its source metadata can contain several tags. A video assigned to Mature may also include a MILF-related source term, and a MILF video may carry a mature-related term. That overlap is normal because provider tags describe multiple characteristics at once.',
                        'The important difference is between metadata and the destination used for primary browsing. Xurvexa does not need to move a video between categories every time an additional source tag appears. This preserves stable category pages while keeping richer source-term information available for taxonomy and future discovery features.',
                    ],
                ],
                [
                    'title' => 'Which category should you browse?',
                    'paragraphs' => [
                        'Choose MILF when the specific MILF category is the main thing you want to browse. Choose Mature when you want the broader collection centered on older or experienced adult performers. If both interests are relevant, the two pages are intentionally connected so you can compare them directly instead of relying on a mixed search result.',
                        'This separation also improves the meaning of internal links, category descriptions and future guide content: each page can explain and serve its own intent without duplicating the other.',
                    ],
                ],
                [
                    'title' => 'How the separation improves discovery',
                    'paragraphs' => [
                        'Clear category boundaries make the rest of the site easier to navigate. Search results, related-category links and editorial guides can point to MILF or Mature with a predictable meaning instead of sending both terms to the same destination. That gives visitors a better reason to follow an internal link because the next page offers a meaningfully different collection rather than a renamed copy.',
                        'It also keeps future taxonomy maintenance safer. If a provider adds a new source term, Xurvexa can decide whether that term supports the specific MILF intent or the broader Mature intent without rewriting the public definitions. The category pages remain stable, while the underlying source-term resolver can evolve as evidence improves. For visitors, the practical result is simple: two related categories, each with a clear purpose and an easy path to the other.',
                    ],
                ],
            ],
            'related_guides' => [
                'bbw-vs-big-ass-vs-big-tits',
            ],
        ],
        'asian-vs-japanese' => [
            'is_active' => true,
            'title' => 'Asian vs Japanese Videos: What\'s the Difference? | Xurvexa',
            'h1' => 'Asian vs Japanese: Understanding the Difference',
            'meta_description' => 'Understand how Asian and Japanese differ as Xurvexa browsing categories, from broad regional discovery to Japan-specific content intent.',
            'intro' => 'Asian and Japanese are related concepts, but they are not equivalent search intents. Xurvexa uses Asian as a broad regional browsing category and Japanese as a dedicated Japan-specific category, giving visitors a useful choice between wider discovery and a more precise destination.',
            'published_at' => '2026-09-02',
            'updated_at' => '2026-09-02',
            'category_links' => [
                [
                    'slug' => 'asian',
                    'label' => 'Asian Videos',
                    'context' => 'Browse the broader regional collection.',
                ],
                [
                    'slug' => 'japanese',
                    'label' => 'Japanese Videos',
                    'context' => 'Browse the dedicated Japan-specific collection.',
                ],
            ],
            'sections' => [
                [
                    'title' => 'The short version',
                    'paragraphs' => [
                        'Asian is the broader regional category. It can include adult content associated with performers, productions or source tags from different parts of Asia. Japanese is more specific: it is a dedicated country-focused browsing destination for content tagged around Japanese performers, productions or scene styles.',
                        'Because one label is broad and the other is specific, merging them would reduce precision. A visitor looking generally for Asian content and a visitor looking specifically for Japanese content are expressing different levels of intent, even when the two collections are conceptually related.',
                    ],
                ],
                [
                    'title' => 'What belongs in the Asian browsing intent',
                    'paragraphs' => [
                        'The Asian page is designed for broad regional discovery. It is useful when the visitor is not narrowing the request to one country-specific label. Source terms such as “asian”, “asia” and common Asian-focused variants can support this category without implying that every item must also be Japanese.',
                        'This broader role is why the Asian category remains independent even after Xurvexa added a dedicated Japanese page. The Asian page is not a fallback name for Japanese; it serves a wider discovery purpose.',
                    ],
                ],
                [
                    'title' => 'What belongs in the Japanese browsing intent',
                    'paragraphs' => [
                        'Japanese is the more precise destination. Xurvexa maps provider terms such as “japanese”, “japan” and “jav” to the Japanese canonical category for taxonomy resolution. That makes future classification consistent with the visitor intent expressed by those terms instead of sending them to the broader Asian page.',
                        'JAV is a source term commonly associated with Japanese adult video content, so it is treated as supporting the Japanese browsing intent. The public category remains named Japanese because that label is clearer and more understandable as the canonical destination.',
                    ],
                ],
                [
                    'title' => 'Why tags can appear across both categories',
                    'paragraphs' => [
                        'Provider metadata is multi-dimensional. A video can carry “japanese” or another Japan-related tag while its primary category assignment reflects a different dominant theme. Conversely, a video in the Japanese category can also have broad Asian source tags. These signals are useful metadata, but they are not a requirement to duplicate the video across every possible category page.',
                        'Xurvexa keeps one primary canonical category for browsing and preserves source terms separately. This avoids unstable category assignments and prevents the category structure from becoming a collection of near-duplicate pages.',
                    ],
                ],
                [
                    'title' => 'Which category should you browse?',
                    'paragraphs' => [
                        'Use Asian when you want broad regional discovery across Asian-focused content. Use Japanese when Japan-specific performer, production or tagging intent is central to what you are looking for. The related-category links between the two make it easy to move from a broad collection to the more specific one.',
                        'That relationship is intentional: Japanese is conceptually connected to the wider Asian context, while remaining an independent Xurvexa category with its own URL, metadata, content and video collection.',
                    ],
                ],
                [
                    'title' => 'How the separation improves discovery',
                    'paragraphs' => [
                        'A broad-to-specific relationship works best when both levels remain visible. The Asian page supports discovery across a wider regional theme, while Japanese gives a direct destination to people who have already narrowed their intent. Internal links can connect the two without pretending that the terms mean the same thing, and editorial copy can explain the relationship in plain language.',
                        'This structure also makes future taxonomy decisions easier to audit. Country-specific source terms can resolve to the country-specific page, while genuinely broad Asian terms can remain on the regional page. Xurvexa can therefore expand its metadata or recommendation logic later without having to merge the public landing pages. The result is a cleaner hierarchy for visitors and a more stable canonical structure for search engines.',
                    ],
                ],
            ],
            'related_guides' => [
            ],
        ],
        'bbw-vs-big-ass-vs-big-tits' => [
            'is_active' => true,
            'title' => 'BBW vs Big Ass vs Big Tits: Category Differences | Xurvexa',
            'h1' => 'BBW vs Big Ass vs Big Tits: How the Categories Differ',
            'meta_description' => 'Compare BBW, Big Ass and Big Tits on Xurvexa and see how overall performer profile differs from body-feature-focused browsing.',
            'intro' => 'BBW, Big Ass and Big Tits can overlap in source tags, but they describe different ways of browsing. Xurvexa keeps all three as independent canonical categories so an overall fuller-figured performer profile does not get confused with pages focused on one particular body feature.',
            'published_at' => '2026-09-02',
            'updated_at' => '2026-09-02',
            'category_links' => [
                [
                    'slug' => 'bbw',
                    'label' => 'BBW Videos',
                    'context' => 'Browse the fuller-figured performer category.',
                ],
                [
                    'slug' => 'big-ass',
                    'label' => 'Big Ass Videos',
                    'context' => 'Browse the booty-focused category.',
                ],
                [
                    'slug' => 'big-tits',
                    'label' => 'Big Tits Videos',
                    'context' => 'Browse the bust-focused category.',
                ],
            ],
            'sections' => [
                [
                    'title' => 'The short version',
                    'paragraphs' => [
                        'BBW is an overall performer-profile category centered on plus-size or fuller-figured adult performers. Big Ass and Big Tits are feature-focused categories: one centers on prominent booty-focused visuals, while the other centers on a fuller chest. Those intents can occur together, but one does not automatically define the others.',
                        'Separating the three lets a visitor choose whether they care about an overall body profile or a particular body feature. It also makes Xurvexa category pages more specific instead of combining several high-level interests into one broad destination.',
                    ],
                ],
                [
                    'title' => 'BBW is an overall profile',
                    'paragraphs' => [
                        'The BBW category describes the overall fuller-figured or plus-size performer profile. It is not limited to one body part, and it should not be treated as a synonym for Big Ass or Big Tits. A BBW video can have either of those characteristics, both, or neither as the dominant reason the video belongs in the BBW collection.',
                        'That broader profile-based meaning is why BBW has its own description, metadata and related-category connections. Visitors choosing BBW are expressing a different browsing preference from someone selecting a single feature-focused page.',
                    ],
                ],
                [
                    'title' => 'Big Ass and Big Tits are feature-focused',
                    'paragraphs' => [
                        'Big Ass is organized around a prominent booty-focused visual theme. Big Tits is organized around a fuller chest as the defining visual theme. Both categories can include performers with many different overall body types, so neither page should be restricted to BBW content.',
                        'This distinction prevents a common taxonomy problem: assuming that one physical characteristic defines a performer’s entire body-profile category. By keeping the pages separate, Xurvexa can offer more accurate browsing without creating artificial exclusions between related interests.',
                    ],
                ],
                [
                    'title' => 'Why source tags still overlap',
                    'paragraphs' => [
                        'External providers often assign several tags to the same video. A single item may arrive with BBW, Big Ass and Big Tits terminology together. Xurvexa preserves source-term evidence while maintaining one primary canonical category for the listing. The extra tags describe the video; they do not require the same listing to become a primary member of every category.',
                        'This model gives taxonomy room to become more useful later for search, recommendations or related-content logic while keeping the public category architecture stable and understandable today.',
                    ],
                ],
                [
                    'title' => 'Which category should you browse?',
                    'paragraphs' => [
                        'Choose BBW when the overall fuller-figured performer profile is the main intent. Choose Big Ass when booty-focused visuals are the main feature you want to browse, and choose Big Tits when bust-focused content is the main feature. The related links among the three are there because these interests can naturally overlap without being identical.',
                        'If you move between these pages, you should expect different primary collections rather than three copies of the same result set. That distinction is central to Xurvexa’s category design.',
                    ],
                ],
                [
                    'title' => 'How the separation improves discovery',
                    'paragraphs' => [
                        'Keeping profile-based and feature-based categories separate gives visitors more useful choices. Someone starting with BBW can follow related links to Big Ass or Big Tits when a particular feature becomes more important, while someone arriving directly on a feature page is not forced into an overall body-profile assumption. The pages complement each other instead of competing as near duplicates.',
                        'That clarity also improves future classification. Xurvexa can preserve several source tags on one video while selecting the most appropriate primary destination for browsing. Related-category links then expose the natural overlap without multiplying the same listing across every possible landing page. This creates a stable foundation for later recommendation or tag-based discovery features while keeping the public category architecture understandable now.',
                    ],
                ],
            ],
            'related_guides' => [
                'milf-vs-mature',
            ],
        ],
        'cumshot-vs-creampie' => [
            'is_active' => true,
            'title' => 'Cumshot vs Creampie: What\'s the Category Difference? | Xurvexa',
            'h1' => 'Cumshot vs Creampie: Understanding the Difference',
            'meta_description' => 'Learn the taxonomy difference between Cumshot and Creampie on Xurvexa and why the broader and narrower finishing themes stay separate.',
            'intro' => 'Cumshot and Creampie are both finishing-theme labels in adult-video taxonomy, but they express different levels of specificity. Xurvexa keeps them as separate canonical categories so a broad climax-focused browsing intent does not replace the narrower Creampie intent.',
            'published_at' => '2026-09-02',
            'updated_at' => '2026-09-02',
            'category_links' => [
                [
                    'slug' => 'cumshot',
                    'label' => 'Cumshot Videos',
                    'context' => 'Browse the broader visible-climax category.',
                ],
                [
                    'slug' => 'creampie',
                    'label' => 'Creampie Videos',
                    'context' => 'Browse the narrower Creampie category.',
                ],
            ],
            'sections' => [
                [
                    'title' => 'The short version',
                    'paragraphs' => [
                        'Cumshot is the broader category on Xurvexa. It covers videos where a visible climax or finishing moment is a defining tag or theme. Creampie is narrower and is used for videos specifically categorized around the creampie finishing theme. The two terms can be related in provider metadata, but they are not interchangeable.',
                        'The taxonomy distinction is intentionally descriptive rather than instructional. Its purpose is to help visitors understand why two separate browsing pages exist and to keep source terminology mapped to the most precise canonical destination available.',
                    ],
                ],
                [
                    'title' => 'Cumshot is the broader finishing-theme category',
                    'paragraphs' => [
                        'The Cumshot page is designed for a broader climax-focused search intent. It can contain different scene formats and performer combinations as long as the visible finishing theme is central to the category assignment. This makes Cumshot useful as a wider destination rather than a container that must be defined by one very specific subtype.',
                        'Because the category is broad, it naturally connects to other act-focused pages as well as to Creampie. Those relationships help browsing, but they do not mean every linked category has the same definition.',
                    ],
                ],
                [
                    'title' => 'Creampie is the narrower category',
                    'paragraphs' => [
                        'Creampie has a more specific taxonomy meaning, so Xurvexa gives it its own canonical page. When that precise source term and browsing intent are central, directing it to a separate category is clearer than collapsing it into Cumshot.',
                        'A narrower category also gives the page room to carry its own title, description, metadata and internal links. That produces a more useful site structure than treating all finishing-related terminology as one undifferentiated label.',
                    ],
                ],
                [
                    'title' => 'Why a video can have both kinds of source terms',
                    'paragraphs' => [
                        'Source metadata can contain both broad and narrow labels for the same item. Xurvexa stores source terms separately from the primary category assignment, so overlap does not create a taxonomy conflict. A video can retain multiple descriptive terms while still having one stable primary category for public browsing.',
                        'This is the same principle used elsewhere in the site: source tags provide evidence and context, while canonical categories provide clear landing pages. The approach reduces duplicate category experiences and makes internal linking more meaningful.',
                    ],
                ],
                [
                    'title' => 'Which category should you browse?',
                    'paragraphs' => [
                        'Choose Cumshot when you want the broader visible-climax or finishing-theme collection. Choose Creampie when that more specific finishing theme is the actual browsing intent. The pages link to one another because the concepts are related, but their scopes remain deliberately different.',
                        'That separation is also useful for future imports: provider tags can resolve to the most accurate category meaning without rewriting the category assignment of existing videos simply because their metadata overlaps.',
                    ],
                ],
                [
                    'title' => 'How the separation improves discovery',
                    'paragraphs' => [
                        'Broad and narrow intent pages are most useful when the difference is explicit. Cumshot can serve visitors who want a wider finishing-theme collection, while Creampie provides a direct path for the more specific term. Internal links connect the concepts without collapsing one into the other, so moving between the pages has a clear reason and a predictable result.',
                        'This structure is also easier to maintain as new source metadata arrives. A broad tag can continue supporting the Cumshot taxonomy, while a precise Creampie tag can resolve to its dedicated destination. Xurvexa can retain overlapping source evidence without changing old video assignments just to make every tag match the primary category. That keeps public URLs stable and lets taxonomy evolve without creating duplicate or confusing landing pages.',
                        'For visitors, the distinction means fewer ambiguous results and a clearer choice between a general category and a specific one. For the site, it means descriptions, canonical URLs and contextual links can stay consistent even when provider terminology overlaps.',
                    ],
                ],
            ],
            'related_guides' => [
            ],
        ],
    ],

    'category_guides' => [
        'milf' => ['milf-vs-mature'],
        'mature' => ['milf-vs-mature'],
        'asian' => ['asian-vs-japanese'],
        'japanese' => ['asian-vs-japanese'],
        'bbw' => ['bbw-vs-big-ass-vs-big-tits'],
        'big-ass' => ['bbw-vs-big-ass-vs-big-tits'],
        'big-tits' => ['bbw-vs-big-ass-vs-big-tits'],
        'cumshot' => ['cumshot-vs-creampie'],
        'creampie' => ['cumshot-vs-creampie'],
    ],
];
