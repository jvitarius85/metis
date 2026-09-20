<?php
declare(strict_types=1);

$items = $normalizeRows( is_array( $results['results'] ?? null ) ? $results['results'] : [] );
$total = (int) ( $results['total'] ?? 0 );
$categoryOptions = $normalizeRows( $categories );

echo '<section class="metis-help-card">';
echo '<form class="metis-help-search-form" action="' . metis_escape_url( $searchUrl ) . '" method="get">';
echo '<label class="screen-reader-text" for="help-search-query">Search help</label>';
echo '<input id="help-search-query" class="metis-input" type="search" name="q" value="' . metis_escape_attr( $searchQuery ) . '" placeholder="Search help by module, task, or issue">';
echo '<label class="screen-reader-text" for="help-search-category">Filter by category</label>';
echo '<select id="help-search-category" class="metis-input" name="category" aria-label="Help category">';
echo '<option value="">All categories</option>';
foreach ( $categoryOptions as $searchCategoryOption ) {
    $slug = (string) ( $searchCategoryOption['slug'] ?? '' );
    echo '<option value="' . metis_escape_attr( $slug ) . '"' . ( $searchCategory === $slug ? ' selected' : '' ) . '>' . metis_escape_html( (string) ( $searchCategoryOption['name'] ?? '' ) ) . '</option>';
}
echo '</select>';
echo '<button class="metis-btn" type="submit">Search</button>';
echo '</form>';
echo '</section>';

echo '<section class="metis-help-card">';
if ( $items === [] ) {
    echo '<div class="metis-help-empty-state">';
    echo '<h2>No matching help articles found.</h2>';
    if ( $searchQuery !== '' ) {
        echo '<p>No results matched <strong>' . metis_escape_html( $searchQuery ) . '</strong>.</p>';
    }
    echo '<ul class="metis-help-empty-list">';
    echo '<li>Check spelling.</li><li>Try fewer words.</li><li>Search by module name.</li><li>Contact an administrator if the issue continues.</li>';
    echo '</ul>';
    echo '</div>';
    echo '<div class="metis-help-search-fallback">';
    echo '<h3>Try These Instead</h3>';
    $renderResultsList(
        $featuredArticles,
        'No suggested articles are available yet.',
        'Use the category tree to browse Help areas.'
    );
    echo '</div>';
} else {
    echo '<p class="metis-help-results-count">' . $total . ' matching article' . ( $total === 1 ? '' : 's' ) . '</p>';
    $renderResultsList( $items, '', '' );
}
echo '</section>';
