<?php
declare(strict_types=1);

$items = $normalizeRows( is_array( $results['results'] ?? null ) ? $results['results'] : [] );

echo '<section class="metis-help-card">';
echo '<p class="metis-help-eyebrow">Category</p>';
echo '<p class="metis-help-muted">' . count( $items ) . ' article' . ( count( $items ) === 1 ? '' : 's' ) . ' in this category.</p>';
echo '</section>';
echo '<section class="metis-help-card">';
$renderResultsList( $items, 'No help documents are available yet.', 'Run the Help Documents Seeder to create the default help library.' );
echo '</section>';
