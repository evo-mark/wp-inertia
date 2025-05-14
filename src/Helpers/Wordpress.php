<?php

namespace EvoMark\InertiaWordpress\Helpers;

use DOMDocument;
use EvoMark\InertiaWordpress\Data\Archive;
use EvoMark\InertiaWordpress\Resources\ImageResource;
use EvoMark\InertiaWordpress\Resources\PostSimpleResource;
use EvoMark\InertiaWordpress\Resources\ArchivePaginationResource;
use EvoMark\InertiaWordpress\Resources\CommentResource;
use EvoMark\InertiaWordpress\Resources\MenuItemResource;
use stdClass;

class Wordpress
{
    public static function getFeaturedImage(\WP_Post|int|null $post)
    {
        $image_id = \get_post_thumbnail_id($post);
        $image = ImageResource::single($image_id, [
            'fallback' => false,
        ]);

        return $image;
    }

    public static function filterArchiveTitle($title)
    {
        if (is_category()) {
            $title = single_cat_title('', false);
        } elseif (is_tag()) {
            $title = single_tag_title('', false);
        } elseif (is_author()) {
            $title = '<span class="vcard">' . get_the_author() . '</span>';
        } elseif (is_post_type_archive()) {
            $title = post_type_archive_title('', false);
        } elseif (is_tax()) {
            $title = single_term_title('', false);
        }

        return $title;
    }

    public static function getCurrentArchiveTitle(): string
    {
        add_filter('get_the_archive_title', [__CLASS__, 'filterArchiveTitle']);
        $title = get_the_archive_title();
        remove_filter('get_the_archive_title', [__CLASS__, 'filterArchiveTitle']);
        return $title;
    }

    public static function getArchiveData(?string $title = null, ?array $posts = null)
    {
        if (empty($title)) {
            $title = self::getCurrentArchiveTitle();
        }
        if (empty($posts)) {
            global $wp_query;
            $posts = $wp_query->posts;
        }

        return new Archive($title, PostSimpleResource::collection($posts), ArchivePaginationResource::collection());
    }

    public static function getUserCapabilities(\WP_User $user): array
    {
        if (empty($user)) {
            return [];
        }
        return $user->get_role_caps();
    }

    public static function getGlobalPost()
    {
        global $post;
        return $post;
    }

    public static function getCustomLogo(): ?stdClass
    {
        if (! has_custom_logo()) {
            return null;
        }
        $logoId = get_theme_mod('custom_logo');
        return ImageResource::single($logoId);
    }

    public static function getNavigationMenus(): array
    {
        $menus = [];
        $registered = get_registered_nav_menus();
        $locations = get_nav_menu_locations();
        foreach ($locations as $name => $menuId) {
            if (!array_key_exists($name, $registered)) {
                continue;
            }

            $menus[$name] = [
                'label' => $registered[$name],
                ...self::getNavigationMenu($menuId),
            ];
        }
        return $menus;
    }

    public static function getNavigationMenu($menuId): array
    {
        $menuObject = wp_get_nav_menu_object($menuId);

        if (empty($menuObject)) {
            return [];
        }

        /**
         * Filter the arguments supplied to each call to `wp_get_nav_menu_items` when generating menus
         *
         * @since 0.2.0
         *
         * @param array $args The args to pass
         * @param \WP_Term $menuObject The menu term object
         * @return array $args
         */
        $args = apply_filters(HookFilters::SHARE_MENU_ITEMS_ARGS, [], $menuObject);

        $data = [
            'menuId' => $menuObject->term_id,
            'menuName' => $menuObject->name,
            'menuSlug' => $menuObject->slug,
            'menuCount' => $menuObject->count,
            'menuDescription' => $menuObject->description,
            'items' => self::createMenuTree($menuId, $args),
        ];

        /**
         * Called before each compiled menu object is passed to the frontend
         *
         * @since 0.2.0
         *
         * @param array $data The compiled menu data object
         * @param \WP_Term $menuObject The raw Wordpress term object for the menu
         * @param int $menuId The term ID of the menu
         * @return array $data
         */
        return apply_filters(HookFilters::SHARE_MENU, $data, $menuObject, $menuId);
    }

    public static function createMenuTree($menuId, $args): array
    {
        $items = wp_get_nav_menu_items($menuId, $args);

        $items_by_parent = [];
        foreach ($items as $item) {
            $items_by_parent[$item->menu_item_parent][] = $item;
        }

        $buildTree = function ($parentId) use (&$items_by_parent, &$buildTree, $menuId) {
            $tree = [];
            if (isset($items_by_parent[$parentId])) {
                foreach ($items_by_parent[$parentId] as $item) {
                    $rawItem = $item;
                    $item = MenuItemResource::single($item);
                    $children = $buildTree($item->id);
                    $item->items = count($children) > 0 ? $children : null;

                    /**
                     * Called when adding a menu item
                     *
                     * @since 0.2.0
                     *
                     * @param stdClass $item The generated menu item object
                     * @param string $parentId The ID of the menu item's parent. Default is "0"
                     * @param \WP_Post $rawItem The original menu item class
                     * @param int $menuId The ID of the menu that the item belongs to
                     * @return stdClass $item
                     */
                    $tree[] = apply_filters(HookFilters::MENU_ITEM, $item, $parentId, $rawItem, $menuId);
                }
            }
            return $tree;
        };

        return $buildTree("0");
    }

    public static function getAdminBar()
    {
        if (!class_exists('WP_Admin_Bar')) {
            require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
        }
        $bar = new \WP_Admin_Bar();
        wp_admin_bar_comments_menu($bar);
        wp_admin_bar_edit_menu($bar);

        /**
         * Fired before the admin bar update HTML elements are extracted
         *
         * @since 0.3.0
         *
         * @param \WP_Admin_Bar $bar A slimmed down version of the admin bar
         */
        do_action(HookActions::PRE_RENDER_ADMIN_BAR_UPDATE, $bar);

        ob_start();
        $bar->render();
        $html = ob_get_clean();

        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new \DOMXPath($dom);
        $elements = $xpath->query("//li");

        $listItems = [];

        foreach ($elements as $element) {
            $listItems[] = $dom->saveHTML($element);
        }
        return $listItems;
    }

    public static function parseAllBlocks($blocks, $blockName)
    {
        $list = [];

        foreach ($blocks as $block) {
            if ($blockName === $block['blockName']) {
                $list[] = $block;
            }
            if (!empty($block['innerBlocks'])) {
                $list = array_merge($list, self::parseAllBlocks($block['innerBlocks'], $blockName));
            }
            if ('core/block' === $block['blockName']) {
                $id = $block['attrs']['ref'];
                $reusableBlock = get_post($id);
                $reusableContent = parse_blocks($reusableBlock->post_content);
                $list = array_merge($list, self::parseAllBlocks($reusableContent, $blockName));
            }
        }
        return $list;
    }

    public static function getTemplate(): string
    {
        $id = get_the_ID();
        $template = get_page_template_slug($id);
        if (empty($template)) {
            return "";
        }

        /**
         * Selected page template before it's appended to the page
         *
         * @since 0.6.0
         *
         * @param string $template The template slug
         * @param int $id The current page ID
         */
        return "?template=" . base64_encode(apply_filters(HookFilters::PAGE_TEMPLATE, $template, $id));
    }

    public static function getPostComments(\WP_Post $post)
    {
        $isPaged = boolval(get_option('page_comments'));
        $page = intval($_GET['comments_page'] ?? 1);
        $perPage = intval($_GET['comments_per_page'] ?? get_option('comments_per_page'));
        $order = strtoupper($_GET['comments_order'] ?? get_option('comment_order', 'DESC'));

        $attrs = $isPaged ? [
            'number'  => $perPage,
            'offset'  => ($page - 1) * $perPage,
        ] : [];

        $topLevelComments = get_comments([
            'post_id' => $post->ID,
            'status'  => 'approve',
            'orderby' => 'comment_date',
            'order'   => $order,
            'parent'  => 0,
            'hierarchical' => 'threaded',
            ...$attrs,
        ]);

        $commentsHierarchy = CommentResource::collection($topLevelComments);

        return $commentsHierarchy;
    }
}
