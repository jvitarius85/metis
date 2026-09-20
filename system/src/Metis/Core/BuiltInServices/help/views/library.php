<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_render_sidebar_module_layout' ) ) {
    require_once METIS_SRC_PATH . 'Metis/Core/Runtime/SidebarModuleLayout.php';
}

$state = metis_get_query_var( 'metis_help_state', [] );
$state = is_array( $state ) ? $state : [];

$pageKind = (string) ( $state['page_kind'] ?? 'landing' );
$pageTitle = trim( (string) ( $state['page_title'] ?? 'Help' ) );
$pageSubtitle = trim( (string) ( $state['page_subtitle'] ?? '' ) );
$tree = is_array( $state['tree'] ?? null ) ? $state['tree'] : [];
$searchQuery = (string) ( $state['search_query'] ?? '' );
$searchCategory = (string) ( $state['search_category'] ?? '' );
$activeCategorySlug = (string) ( $state['active_category_slug'] ?? '' );
$activeArticleSlug = (string) ( $state['active_article_slug'] ?? '' );
$landing = is_array( $state['landing'] ?? null ) ? $state['landing'] : [];
$categories = is_array( $state['categories'] ?? null ) ? $state['categories'] : [];
$results = is_array( $state['results'] ?? null ) ? $state['results'] : [];
$article = is_array( $state['article'] ?? null ) ? $state['article'] : [];
$relatedArticles = is_array( $state['related_articles'] ?? null ) ? $state['related_articles'] : [];
$category = is_array( $state['category'] ?? null ) ? $state['category'] : [];
$adminContent = (string) ( $state['admin_content'] ?? '' );
$canManage = function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'help.manage' );

$homeUrl = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help' ) : '/admin/help';
$searchUrl = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help/search' ) : '/admin/help/search';
$articlesUrl = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help/articles' ) : '/admin/help/articles';

$breadcrumbs = [ [ 'label' => 'Help', 'url' => $homeUrl ] ];

if ( $pageKind === 'search' ) {
    $breadcrumbs[] = [ 'label' => 'Search' ];
} elseif ( $pageKind === 'category' && $pageTitle !== '' ) {
    $breadcrumbs[] = [ 'label' => $pageTitle ];
} elseif ( $pageKind === 'article' ) {
    $categoryLabel = trim( (string) ( $article['category_name'] ?? '' ) );
    $categorySlug = trim( (string) ( $article['category_slug'] ?? '' ) );
    if ( $categoryLabel !== '' && $categorySlug !== '' ) {
        $breadcrumbs[] = [
            'label' => $categoryLabel,
            'url' => ( function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help/category/' . $categorySlug ) : '/admin/help/category/' . $categorySlug ),
        ];
    }
    $breadcrumbs[] = [ 'label' => $pageTitle !== '' ? $pageTitle : 'Article' ];
} elseif ( $pageKind === 'admin_list' ) {
    $breadcrumbs[] = [ 'label' => 'Manage Articles' ];
} elseif ( $pageKind === 'admin_editor' ) {
    $breadcrumbs[] = [ 'label' => 'Manage Articles', 'url' => $articlesUrl ];
    $breadcrumbs[] = [ 'label' => $pageTitle !== '' ? $pageTitle : 'Editor' ];
} elseif ( $pageKind === 'error' ) {
    $breadcrumbs[] = [ 'label' => 'Error' ];
}

if ( function_exists( 'metis_breadcrumb' ) ) {
    metis_breadcrumb( $breadcrumbs );
}

$treeCategories = [];
$treeArticles = [];
foreach ( $tree as $treeRow ) {
    if ( ! is_array( $treeRow ) ) {
        continue;
    }
    $treeCategories[] = $treeRow;
    foreach ( (array) ( $treeRow['articles'] ?? [] ) as $treeArticleRow ) {
        if ( is_array( $treeArticleRow ) ) {
            $treeArticles[] = $treeArticleRow;
        }
    }
}

$featuredCategories = array_slice( $treeCategories, 0, 6 );
$featuredArticles = array_slice( $treeArticles, 0, 8 );

$normalizeRows = static function ( array $items ): array {
    $normalized = [];
    foreach ( $items as $item ) {
        if ( is_array( $item ) ) {
            $normalized[] = $item;
            continue;
        }

        if ( is_object( $item ) ) {
            $normalized[] = (array) $item;
        }
    }
    return $normalized;
};

$renderResultsList = static function ( array $items, string $emptyTitle, string $emptyCopy ): void {
    $normalized = [];
    foreach ( $items as $item ) {
        if ( is_array( $item ) ) {
            $normalized[] = $item;
        } elseif ( is_object( $item ) ) {
            $normalized[] = (array) $item;
        }
    }

    if ( $normalized === [] ) {
        echo '<section class="metis-help-empty-state">';
        echo '<h2>' . metis_escape_html( $emptyTitle ) . '</h2>';
        echo '<p>' . metis_escape_html( $emptyCopy ) . '</p>';
        echo '</section>';
        return;
    }

    echo '<ul class="metis-help-result-list">';
    foreach ( $normalized as $item ) {
        $url = (string) ( $item['url'] ?? '#' );
        $title = (string) ( $item['title'] ?? '' );
        $summary = (string) ( $item['summary'] ?? '' );
        $meta = trim( (string) ( $item['category'] ?? '' ) );
        echo '<li class="metis-help-result-card">';
        echo '<a class="metis-help-result-link" href="' . metis_escape_url( $url ) . '">';
        echo '<span class="metis-help-result-title">' . metis_escape_html( $title ) . '</span>';
        if ( $summary !== '' ) {
            echo '<span class="metis-help-result-summary">' . metis_escape_html( $summary ) . '</span>';
        }
        if ( $meta !== '' ) {
            echo '<small>' . metis_escape_html( $meta ) . '</small>';
        }
        echo '</a>';
        echo '</li>';
    }
    echo '</ul>';
};

$renderCategoryCards = static function ( array $items ): void {
    if ( $items === [] ) {
        echo '<p class="metis-help-muted">No help categories are available yet.</p>';
        return;
    }
    echo '<div class="metis-help-category-grid">';
    foreach ( $items as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }
        echo '<a class="metis-help-category-card" href="' . metis_escape_url( (string) ( $item['url'] ?? '#' ) ) . '">';
        echo '<strong>' . metis_escape_html( (string) ( $item['name'] ?? '' ) ) . '</strong>';
        echo '<span>' . (int) ( $item['article_count'] ?? 0 ) . ' article' . ( (int) ( $item['article_count'] ?? 0 ) === 1 ? '' : 's' ) . '</span>';
        echo '</a>';
    }
    echo '</div>';
};
$renderContentPartial = static fn( string $partial, array $vars = [] ): string => \Metis\Modules\Help\HelpModule::renderTemplate( __DIR__ . '/library/' . $partial . '.php', $vars );
?>
<section class="metis-help-shell">
    <?php
    metis_render_sidebar_module_layout(
        [
            'class' => 'metis-help-workspace',
            'title' => $pageTitle !== '' ? $pageTitle : 'Help',
            'subtitle' => $pageSubtitle,
            'shell_class' => 'metis-help-workspace-shell',
            'sidebar_class' => 'metis-help-workspace-sidebar',
            'content_class' => 'metis-help-workspace-content',
            'header_actions' => static function () use ( $homeUrl, $searchUrl, $articlesUrl, $canManage ): void {
                echo '<div class="metis-help-header-actions">';
                echo '<a class="metis-btn metis-btn-secondary" href="' . metis_escape_url( $homeUrl ) . '">Help Home</a>';
                echo '<a class="metis-btn metis-btn-secondary" href="' . metis_escape_url( $searchUrl ) . '">Search</a>';
                if ( $canManage ) {
                    echo '<a class="metis-btn" href="' . metis_escape_url( $articlesUrl ) . '">Manage Articles</a>';
                }
                echo '</div>';
            },
            'sidebar' => static function () use ( $tree, $searchQuery, $searchCategory, $categories, $activeCategorySlug, $activeArticleSlug, $searchUrl ): void {
                if ( $categories === [] ) {
                    $categories = [];
                    foreach ( $tree as $treeCategory ) {
                        $categories[] = [
                            'id' => (int) ( $treeCategory['id'] ?? 0 ),
                            'name' => (string) ( $treeCategory['name'] ?? '' ),
                            'slug' => (string) ( $treeCategory['slug'] ?? '' ),
                        ];
                    }
                }

                echo '<div class="metis-list-sidebar-section">';
                echo '<div class="metis-list-sidebar-label">Search Help</div>';
                echo '<form class="metis-help-search-form metis-help-search-form--stacked" action="' . metis_escape_url( $searchUrl ) . '" method="get">';
                echo '<label class="screen-reader-text" for="metis-help-sidebar-query">Search help</label>';
                echo '<input id="metis-help-sidebar-query" class="metis-input" type="search" name="q" value="' . metis_escape_attr( $searchQuery ) . '" placeholder="Search help by task, issue, or module">';
                echo '<label class="screen-reader-text" for="metis-help-sidebar-category">Help category</label>';
                echo '<select id="metis-help-sidebar-category" class="metis-input" name="category" aria-label="Help category">';
                echo '<option value="">All categories</option>';
                foreach ( $categories as $sidebarCategory ) {
                    $slug = (string) ( $sidebarCategory['slug'] ?? '' );
                    $name = (string) ( $sidebarCategory['name'] ?? '' );
                    echo '<option value="' . metis_escape_attr( $slug ) . '"' . ( $searchCategory === $slug ? ' selected' : '' ) . '>' . metis_escape_html( $name ) . '</option>';
                }
                echo '</select>';
                echo '<button class="metis-btn" type="submit">Search Help</button>';
                echo '</form>';
                echo '</div>';

                echo '<div class="metis-list-sidebar-section">';
                echo '<div class="metis-list-sidebar-label">Navigation</div>';
                echo '<div class="metis-list-sidebar-actions">';
                echo '<a class="metis-btn metis-btn-secondary" href="' . metis_escape_url( $searchUrl ) . '">Open Search</a>';
                echo '<a class="metis-btn metis-btn-secondary" href="' . metis_escape_url( function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help' ) : '/admin/help' ) . '">Help Home</a>';
                echo '</div>';
                echo '</div>';

                echo '<div class="metis-list-sidebar-section">';
                echo '<div class="metis-list-sidebar-label">Help Library</div>';

                if ( $tree === [] ) {
                    echo '<p class="metis-help-muted">No help documents are available yet.</p>';
                } else {
                    foreach ( $tree as $treeCategory ) {
                        $categorySlug = (string) ( $treeCategory['slug'] ?? '' );
                        $isActiveCategory = $activeCategorySlug === $categorySlug;
                        $isOpen = $isActiveCategory || $activeArticleSlug !== '' && $isActiveCategory;
                        echo '<details class="metis-help-tree-group"' . ( $isOpen ? ' open' : '' ) . '>';
                        echo '<summary class="metis-help-tree-summary">';
                        echo '<span class="metis-help-tree-summary-main">';
                        echo '<span class="metis-help-tree-caret" aria-hidden="true"></span>';
                        echo '<a class="metis-list-sidebar-nav-item metis-help-tree-category' . ( $isActiveCategory && $activeArticleSlug === '' ? ' is-active' : '' ) . '" href="' . metis_escape_url( (string) ( $treeCategory['url'] ?? '#' ) ) . '" onclick="event.stopPropagation();">';
                        echo metis_escape_html( (string) ( $treeCategory['name'] ?? '' ) );
                        echo '</a>';
                        echo '</span>';
                        echo '<span class="metis-help-tree-count">' . (int) ( $treeCategory['article_count'] ?? 0 ) . '</span>';
                        echo '</summary>';
                        echo '<nav class="metis-list-sidebar-nav metis-help-tree-nav" aria-label="' . metis_escape_attr( (string) ( $treeCategory['name'] ?? 'Category' ) ) . ' articles">';
                        foreach ( (array) ( $treeCategory['articles'] ?? [] ) as $treeArticle ) {
                            $articleSlug = (string) ( $treeArticle['slug'] ?? '' );
                            echo '<a class="metis-list-sidebar-nav-item metis-help-tree-article' . ( $activeArticleSlug === $articleSlug ? ' is-active' : '' ) . '" href="' . metis_escape_url( (string) ( $treeArticle['url'] ?? '#' ) ) . '">' . metis_escape_html( (string) ( $treeArticle['title'] ?? '' ) ) . '</a>';
                        }
                        echo '</nav>';
                        echo '</details>';
                    }
                }

                echo '</div>';
            },
            'content' => static function () use ( $pageKind, $landing, $results, $article, $relatedArticles, $category, $adminContent, $normalizeRows, $renderResultsList, $renderCategoryCards, $renderContentPartial, $homeUrl, $searchUrl, $searchQuery, $searchCategory, $categories, $featuredCategories, $featuredArticles, $treeCategories, $treeArticles ): void {
                $sharedVars = [
                    'pageKind' => $pageKind,
                    'landing' => $landing,
                    'results' => $results,
                    'article' => $article,
                    'relatedArticles' => $relatedArticles,
                    'category' => $category,
                    'adminContent' => $adminContent,
                    'normalizeRows' => $normalizeRows,
                    'renderResultsList' => $renderResultsList,
                    'renderCategoryCards' => $renderCategoryCards,
                    'homeUrl' => $homeUrl,
                    'searchUrl' => $searchUrl,
                    'searchQuery' => $searchQuery,
                    'searchCategory' => $searchCategory,
                    'categories' => $categories,
                    'featuredCategories' => $featuredCategories,
                    'featuredArticles' => $featuredArticles,
                    'treeCategories' => $treeCategories,
                    'treeArticles' => $treeArticles,
                ];

                if ( $pageKind === 'landing' ) {
                    echo $renderContentPartial( 'landing', $sharedVars );
                    return;
                }

                if ( $pageKind === 'search' ) {
                    echo $renderContentPartial( 'search', $sharedVars );
                    return;
                }

                if ( $pageKind === 'category' ) {
                    echo $renderContentPartial( 'category', $sharedVars );
                    return;
                }

                if ( $pageKind === 'article' ) {
                    echo $renderContentPartial( 'article', $sharedVars );
                    return;
                }

                if ( $pageKind === 'admin_list' || $pageKind === 'admin_editor' ) {
                    echo $renderContentPartial( 'admin', $sharedVars );
                    return;
                }

                echo $renderContentPartial( 'empty', $sharedVars );
            },
        ]
    );
    ?>
</section>
