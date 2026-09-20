<?php
declare(strict_types=1);

namespace Metis\Modules\Help\Controllers;

use Metis\Core\Application;
use Metis\Core\HelpSearchStore;
use Metis\Core\ModulePathRegistry;
use Metis\Http\Request;
use Metis\Http\Response;
use Metis\Modules\Help\HelpModule;
use Metis\Modules\Help\Policies\HelpPolicy;
use Metis\Modules\Help\Requests\HelpAdminListRequest;
use Metis\Modules\Help\Requests\HelpArticleRequest;
use Metis\Modules\Help\Requests\HelpCategoryRequest;
use Metis\Modules\Help\Requests\HelpEditorRequest;
use Metis\Modules\Help\Requests\HelpSearchRequest;

final class HelpPageController {
    public static function handleIndexRoute( Request $request ): Response {
        if ( $redirect = HelpPolicy::requireViewAccess() ) {
            return $redirect;
        }

        $store = new HelpSearchStore();

        return HelpModule::shellResponse(
            'library',
            [
                'page_kind' => 'landing',
                'page_title' => 'How can we help?',
                'page_subtitle' => 'Search practical guidance for Metis modules, account access, admin workflows, and common fixes.',
                'landing' => $store->landingData(),
                'search_query' => '',
                'search_category' => '',
                'tree' => $store->navigationTree(),
            ]
        );
    }

    public static function handleSearchRoute( Request $request ): Response {
        if ( $redirect = HelpPolicy::requireViewAccess() ) {
            return $redirect;
        }

        $searchRequest = HelpSearchRequest::fromRequest( $request );
        $store = new HelpSearchStore();

        return HelpModule::shellResponse(
            'search',
            [
                'page_kind' => 'search',
                'page_title' => 'Help Search',
                'page_subtitle' => 'Search the Help library by title, module, action, or likely user phrase.',
                'results' => $store->search( $searchRequest->query(), $searchRequest->category(), 12, $searchRequest->page(), false ),
                'categories' => $store->categorySummaries(),
                'search_query' => $searchRequest->query(),
                'search_category' => $searchRequest->category(),
                'tree' => $store->navigationTree(),
                'active_category_slug' => $searchRequest->category(),
            ]
        );
    }

    public static function handleArticleRoute( Request $request ): Response {
        if ( $redirect = HelpPolicy::requireViewAccess() ) {
            return $redirect;
        }

        $articleRequest = HelpArticleRequest::fromRequest( $request );
        $store = new HelpSearchStore();
        $article = $store->articleBySlug( $articleRequest->slug(), false );
        if ( ! is_array( $article ) ) {
            return HelpModule::errorResponse( 404, 'Help Article Not Found', 'The requested help article could not be found.' );
        }

        return HelpModule::shellResponse(
            'article',
            [
                'page_kind' => 'article',
                'page_title' => (string) $article['title'],
                'page_subtitle' => (string) ( $article['summary'] ?? '' ),
                'article' => $article,
                'related_articles' => $store->relatedArticles( (int) $article['id'], (int) $article['category_id'], 4 ),
                'tree' => $store->navigationTree(),
                'active_category_slug' => (string) ( $article['category_slug'] ?? '' ),
                'active_article_slug' => (string) ( $article['slug'] ?? '' ),
            ]
        );
    }

    public static function handleCategoryRoute( Request $request ): Response {
        if ( $redirect = HelpPolicy::requireViewAccess() ) {
            return $redirect;
        }

        $categoryRequest = HelpCategoryRequest::fromRequest( $request );
        $store = new HelpSearchStore();
        $category = $store->categoryBySlug( $categoryRequest->slug() );
        if ( ! is_array( $category ) ) {
            return HelpModule::errorResponse( 404, 'Help Category Not Found', 'The requested help category could not be found.' );
        }

        return HelpModule::shellResponse(
            'category',
            [
                'page_kind' => 'category',
                'page_title' => (string) $category['name'],
                'page_subtitle' => 'Browse help articles in this category.',
                'category' => $category,
                'results' => $store->articlesForCategory( $categoryRequest->slug(), 12, $categoryRequest->page() ),
                'tree' => $store->navigationTree(),
                'active_category_slug' => (string) $category['slug'],
            ]
        );
    }

    public static function handleAdminArticlesRoute( Request $request ): Response {
        if ( $redirect = HelpPolicy::requireManageAccess() ) {
            return $redirect;
        }

        $listRequest = HelpAdminListRequest::fromRequest( $request );
        $store = new HelpSearchStore();

        return HelpModule::shellResponse(
            'articles',
            [
                'page_kind' => 'admin_list',
                'page_title' => 'Help Articles',
                'page_subtitle' => 'Search, review, and manage the Help library.',
                'admin_content' => HelpModule::renderTemplate(
                    (string) ModulePathRegistry::modulePath( 'help' ) . '/admin/articles.php',
                    [
                        'mode' => 'list',
                        'listing' => $store->adminList( $listRequest->search(), $listRequest->category(), $listRequest->status(), $listRequest->page(), 20 ),
                        'article' => null,
                        'filters' => [
                            'q' => $listRequest->search(),
                            'category' => $listRequest->category(),
                            'status' => $listRequest->status(),
                        ],
                        'categories' => $store->categorySummaries(),
                        'preview_requested' => false,
                    ] + self::nonceState()
                ),
                'tree' => $store->navigationTree(),
                'search_query' => $listRequest->search(),
                'active_category_slug' => $listRequest->category(),
            ]
        );
    }

    public static function handleAdminCreateRoute( Request $request ): Response {
        if ( $redirect = HelpPolicy::requireManageAccess() ) {
            return $redirect;
        }

        $editorRequest = HelpEditorRequest::fromCreateRequest( $request );
        $store = new HelpSearchStore();

        return HelpModule::shellResponse(
            'editor',
            [
                'page_kind' => 'admin_editor',
                'page_title' => 'Create Help Article',
                'page_subtitle' => 'Draft or publish a help article in the Help library.',
                'admin_content' => HelpModule::renderTemplate(
                    (string) ModulePathRegistry::modulePath( 'help' ) . '/admin/articles.php',
                    [
                        'mode' => 'create',
                        'listing' => null,
                        'article' => [
                            'id' => 0,
                            'title' => '',
                            'slug' => '',
                            'summary' => '',
                            'content' => '',
                            'category_id' => 0,
                            'category_slug' => '',
                            'status' => 'draft',
                            'tags' => [],
                            'search_terms' => '',
                            'system_seeded' => 0,
                        ],
                        'filters' => [],
                        'categories' => $store->categorySummaries(),
                        'preview_requested' => $editorRequest->previewRequested(),
                    ] + self::nonceState()
                ),
                'tree' => $store->navigationTree(),
            ]
        );
    }

    public static function handleAdminIssueResolutionRoute( Request $request ): Response {
        if ( $redirect = HelpPolicy::requireManageAccess() ) {
            return $redirect;
        }

        unset( $request );
        $coverage = Application::service( 'hermes_repository' )->helpIssueCoverage( 25 );

        return HelpModule::shellResponse(
            'issue-resolution',
            [
                'page_kind' => 'admin_issue_resolution',
                'page_title' => 'Hermes Issue Resolution',
                'page_subtitle' => 'Review unresolved phrases, weak classifications, and help search coverage gaps.',
                'admin_content' => HelpModule::renderTemplate(
                    (string) ModulePathRegistry::modulePath( 'help' ) . '/admin/issue-resolution.php',
                    [
                        'coverage' => $coverage,
                        'rebuild_nonce' => \metis_runtime_create_nonce( 'metis_help_index_rebuild' ),
                    ]
                ),
                'tree' => ( new HelpSearchStore() )->navigationTree(),
            ]
        );
    }

    public static function handleAdminEditRoute( Request $request ): Response {
        if ( $redirect = HelpPolicy::requireManageAccess() ) {
            return $redirect;
        }

        $editorRequest = HelpEditorRequest::fromEditRequest( $request );
        $store = new HelpSearchStore();
        $article = $store->articleById( $editorRequest->articleId(), true );
        if ( ! is_array( $article ) ) {
            return HelpModule::errorResponse( 404, 'Help Article Not Found', 'The requested help article could not be found.' );
        }

        return HelpModule::shellResponse(
            'editor',
            [
                'page_kind' => 'admin_editor',
                'page_title' => 'Edit Help Article',
                'page_subtitle' => 'Update article content, search terms, and publication state.',
                'admin_content' => HelpModule::renderTemplate(
                    (string) ModulePathRegistry::modulePath( 'help' ) . '/admin/articles.php',
                    [
                        'mode' => 'edit',
                        'listing' => null,
                        'article' => $article,
                        'filters' => [],
                        'categories' => $store->categorySummaries(),
                        'preview_requested' => $editorRequest->previewRequested(),
                    ] + self::nonceState()
                ),
                'tree' => $store->navigationTree(),
                'active_category_slug' => (string) ( $article['category_slug'] ?? '' ),
                'active_article_slug' => (string) ( $article['slug'] ?? '' ),
            ]
        );
    }

    private static function nonceState(): array {
        return [
            'save_nonce' => \metis_runtime_create_nonce( 'metis_help_article_save' ),
            'publish_nonce' => \metis_runtime_create_nonce( 'metis_help_article_publish' ),
            'unpublish_nonce' => \metis_runtime_create_nonce( 'metis_help_article_unpublish' ),
            'rebuild_nonce' => \metis_runtime_create_nonce( 'metis_help_index_rebuild' ),
        ];
    }
}
