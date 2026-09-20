<?php
declare(strict_types=1);

$articleUrl = function_exists( 'metis_home_url' )
    ? (string) metis_home_url( '/admin/help/article/' . (string) ( $article['slug'] ?? '' ) )
    : '/admin/help/article/' . (string) ( $article['slug'] ?? '' );

echo '<div class="metis-help-article-layout">';
echo '<article class="metis-help-article metis-help-article--embedded">';
echo '<div class="metis-help-article__meta-row">';
if ( ! empty( $article['category_name'] ) ) {
    echo '<span class="metis-help-badge">' . metis_escape_html( (string) $article['category_name'] ) . '</span>';
}
if ( ! empty( $article['updated_at'] ) ) {
    echo '<span class="metis-help-updated">Updated ' . metis_escape_html( (string) $article['updated_at'] ) . '</span>';
}
echo '</div>';
echo '<div class="metis-help-article__body">' . (string) ( $article['content'] ?? '' ) . '</div>';
echo '</article>';

echo '<aside class="metis-help-article-rail">';
echo '<section class="metis-help-card metis-help-card--accent">';
echo '<p class="metis-help-eyebrow">Need More Help?</p>';
echo '<h2>Still stuck on this step?</h2>';
echo '<p>If the documented workflow does not match what you see on screen, send a message to the system admin with the article and page context already attached.</p>';
echo '<button type="button" class="metis-btn" data-help-support-open'
    . ' data-article-title="' . metis_escape_attr( (string) ( $article['title'] ?? 'Help Article' ) ) . '"'
    . ' data-article-slug="' . metis_escape_attr( (string) ( $article['slug'] ?? '' ) ) . '"'
    . ' data-article-url="' . metis_escape_attr( $articleUrl ) . '">Contact System Admin</button>';
echo '</section>';

echo '<section class="metis-help-card">';
echo '<h2>Related Articles</h2>';
$renderResultsList( $relatedArticles, 'No related articles are available yet.', 'Use the Help search to find nearby topics.' );
echo '</section>';
echo '</aside>';
echo '</div>';

echo '<div class="metis-modal-backdrop" id="metis-help-support-modal" aria-hidden="true" hidden>';
echo '<div class="metis-modal metis-help-support-modal" role="dialog" aria-modal="true" aria-labelledby="metis-help-support-title">';
echo '<div class="metis-modal-header">';
echo '<h2 id="metis-help-support-title" class="metis-modal-title">Contact System Admin</h2>';
echo '<button type="button" class="metis-modal-close" data-help-support-close aria-label="Close">&times;</button>';
echo '</div>';
echo '<div class="metis-modal-body">';
echo '<form class="metis-help-support-form" data-help-support-form>';
echo '<input type="hidden" name="article_title" value="' . metis_escape_attr( (string) ( $article['title'] ?? 'Help Article' ) ) . '">';
echo '<input type="hidden" name="article_slug" value="' . metis_escape_attr( (string) ( $article['slug'] ?? '' ) ) . '">';
echo '<input type="hidden" name="article_url" value="' . metis_escape_attr( $articleUrl ) . '">';
echo '<input type="hidden" name="route" value="' . metis_escape_attr( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) . '">';
echo '<div class="metis-help-support-context">';
echo '<div><strong>Article</strong><span>' . metis_escape_html( (string) ( $article['title'] ?? 'Help Article' ) ) . '</span></div>';
echo '<div><strong>Category</strong><span>' . metis_escape_html( (string) ( $article['category_name'] ?? 'Help' ) ) . '</span></div>';
echo '</div>';
echo '<label for="metis-help-support-message">What do you need help with?</label>';
echo '<textarea id="metis-help-support-message" class="metis-input" name="message" rows="6" placeholder="Describe what you expected to see, what actually happened, and what you were trying to finish." required></textarea>';
echo '<p class="metis-help-muted">Your message includes the article and current Help page so the system admin has context.</p>';
echo '</form>';
echo '</div>';
echo '<div class="metis-modal-footer">';
echo '<button type="button" class="metis-btn metis-btn-secondary" data-help-support-close>Cancel</button>';
echo '<button type="button" class="metis-btn" data-help-support-submit>Send Request</button>';
echo '</div>';
echo '</div>';
echo '</div>';
