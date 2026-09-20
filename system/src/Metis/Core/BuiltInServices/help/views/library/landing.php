<?php
declare(strict_types=1);

$landingCategories = $normalizeRows( is_array( $landing['categories'] ?? null ) ? $landing['categories'] : [] );
$popularArticles = $normalizeRows( is_array( $landing['popular_articles'] ?? null ) ? $landing['popular_articles'] : [] );
$recentArticles = $normalizeRows( is_array( $landing['recent_articles'] ?? null ) ? $landing['recent_articles'] : [] );
$browseCategories = $landingCategories !== [] ? array_map(
    static function ( array $item ) use ( $homeUrl ): array {
        return [
            'name' => (string) ( $item['name'] ?? '' ),
            'article_count' => (int) ( $item['article_count'] ?? 0 ),
            'url' => function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help/category/' . (string) ( $item['slug'] ?? '' ) ) : $homeUrl,
        ];
    },
    $landingCategories
) : $featuredCategories;

echo '<section class="metis-help-hero metis-help-hero--dashboard">';
echo '<div class="metis-help-hero-copy">';
echo '<p class="metis-help-eyebrow">Start Here</p>';
echo '<h2 class="metis-help-hero-title">Find answers fast without leaving the admin.</h2>';
echo '<p class="metis-help-hero-text">Search by task, browse a module area, or jump into the most-used articles below.</p>';
echo '</div>';
echo '<form class="metis-help-search-form" action="' . metis_escape_url( $searchUrl ) . '" method="get">';
echo '<label class="screen-reader-text" for="help-search-home">Search help</label>';
echo '<input id="help-search-home" class="metis-input" type="search" name="q" placeholder="Search help by module, task, or issue">';
echo '<button class="metis-btn" type="submit">Search Help</button>';
echo '</form>';
echo '<div class="metis-help-stat-grid">';
echo '<div class="metis-help-stat-card"><strong>' . count( $treeCategories ) . '</strong><span>help areas</span></div>';
echo '<div class="metis-help-stat-card"><strong>' . count( $treeArticles ) . '</strong><span>published articles</span></div>';
echo '<div class="metis-help-stat-card"><strong>' . count( $featuredArticles ) . '</strong><span>featured topics</span></div>';
echo '</div>';
echo '</section>';

echo '<section class="metis-help-grid">';
echo '<div class="metis-help-card"><h2>Popular Articles</h2>';
$renderResultsList(
    $popularArticles !== [] ? $popularArticles : $featuredArticles,
    'No help documents are available yet.',
    'Run the Help Documents Seeder to create the default help library.'
);
echo '</div>';
echo '<div class="metis-help-card"><h2>Recently Updated</h2>';
$renderResultsList(
    $recentArticles !== [] ? $recentArticles : $featuredArticles,
    'No help documents are available yet.',
    'Run the Help Documents Seeder to create the default help library.'
);
echo '</div>';
echo '</section>';

echo '<section class="metis-help-grid">';
echo '<div class="metis-help-card"><h2>Browse by Area</h2>';
$renderCategoryCards( $browseCategories );
echo '</div>';
echo '<div class="metis-help-card"><h2>Common Tasks</h2>';
$renderResultsList(
    $featuredArticles,
    'No task articles are available yet.',
    'Seed the Help library to populate common tasks.'
);
echo '</div>';
echo '</section>';
