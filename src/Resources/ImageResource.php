<?php

namespace EvoMark\InertiaWordpress\Resources;

use EvoMark\InertiaWordpress\Helpers\HookFilters;

class ImageResource
{
    public static function single(?int $attachment_id = null, array $args = [])
    {
        $fallbackToPostImage = !isset($args['fallback']) || $args['fallback'] !== false;

        $sizes = get_intermediate_image_sizes();
        $sizes[] = 'full';
        if (!$attachment_id && $fallbackToPostImage) {
            $attachment_id = get_post_thumbnail_id();
        }

        $images = [];
        foreach ($sizes as $size) {
            $src = wp_get_attachment_image_src($attachment_id, $size);
            if (empty($src)) {
                return null;
            }

            $images[$size] = [
                'url'        => $src[0] ?? null,
                'width'      => $src[1] ?? null,
                'height'     => $src[2] ?? null,
                'is_resized' => $src[3] ?? false,
            ];
        }
        $imageObject =  (object) [
            'sizes' => $images,
            'metadata' => self::getImageMetadata($attachment_id),
            'exists' => !empty($attachment_id),
            'original' => wp_get_original_image_url($attachment_id),
        ];

        /**
         * Modify the image object for an image passed through the ImageResource
         *
         * @param stdClass $image The object containing the image
         * @param int $attachment_id The attachment ID used as the basis for the image
         *
         * @since 0.8.0
         */
        return apply_filters(HookFilters::RESOURCES_IMAGE_ITEM, $imageObject, $attachment_id);
    }

    public static function collection(array $attachment_ids = [], array $args = [])
    {
        $collection = array_map(fn ($id) => self::single($id, $args), $attachment_ids);

        /**
         * Modify a collection of images returned as an array
         *
         * @param array $collection The array of images
         * @param array $attachment_ids The attachment IDs used as the basis for the collection
         * @param array $args Associative array of arguments passed through to the item resource
         *
         * @since 0.8.0
         */
        return apply_filters(HookFilters::RESOURCES_IMAGE_COLLECTION, $collection, $attachment_ids, $args);
    }

    public static function getImageMetadata($id): array
    {
        $meta = [];
        $meta['alt'] = get_post_meta($id, '_wp_attachment_image_alt', true);
        $meta['title'] = get_post_field('post_title', $id);
        $meta['caption'] = get_post_field('post_excerpt', $id);
        $meta['description'] = get_post_field('post_content', $id);
        $meta['name'] = get_post_field('post_name', $id);
        $meta['type'] = get_post_field('post_type', $id);
        $meta['mime'] = get_post_field('post_mime_type', $id);

        /**
         * Get the metadata associated with the image
         *
         * @param array $meta The metadata associative array
         * @param int $id The attachment ID of the image being fetched
         *
         * @since 0.8.0
         */
        return apply_filters(HookFilters::RESOURCES_IMAGE_METADATA, $meta, $id);
    }
}
