<?php


class AMS_WP_Post_Source implements AMS_Source_Impl_Interface
{

    private string $post_type = 'post';

    /**
     * Get total count of items in the source
     * @return int
     */
    public function get_items_count(): int
    {
        $args = [
            'post_type'      => $this->post_type,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        $query = new WP_Query($args);
        return (int) $query->found_posts;
    }


    /**
     * Get all items from the source
     * @return array
     */
    public function get_items(): array
    {
        $args = [
            'post_type'      => $this->post_type,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        $query = new WP_Query($args);
        $ids = $query->posts;
        $poasts_data = [];
        foreach ($ids as $pid) {
            $post_data = $this->get_post_data($pid);
            if ($post_data) {
                $poasts_data[] = $post_data;
            }
        }
        return $poasts_data;
    }

    /** 
     * Get single item by ID
     * @param int $id
     * @return array|null
     */
    public function get_item_by_id(int $id): array | null
    {
        return $this->get_post_data($id);
    }

    /**
    * Get items for a specific page
    * @param int $page
    * @param int $per_page
    * @return array
    */
    public function get_items_page(int $page, int $per_page): array
    {
        $offset = ($page - 1) * $per_page;
        $args = [
            'post_type'      => $this->post_type,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => $per_page,
            'offset'         => $offset,
        ];

        $query = new WP_Query($args);
        $ids = $query->posts;
        $poasts_data = [];
        foreach ($ids as $pid) {
            $post_data = $this->get_post_data($pid);
            if ($post_data) {
                $poasts_data[] = $post_data;
            }
        }
        return $poasts_data;
    }

    /**
     * Get post data by ID
     * @param int $pid
     * @return array|null
     */
    private function get_post_data(int $pid): array | null
    {
        $post = get_post($pid);
        if (! $post) {
            return null;
        }

        $taxonomies = get_object_taxonomies($post->post_type);
        $terms_data = [];
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($pid, $taxonomy, ['fields' => 'names']);
            if (! empty($terms) && ! is_wp_error($terms)) {
                $terms_data[$taxonomy] = $terms;
            }
        }

        $thumb_id = get_post_thumbnail_id($pid);
        $image_url = $thumb_id ? wp_get_attachment_url($thumb_id) : '';

        $author_id = get_post_field('post_author', $pid);
        $author_name = $author_id ? get_the_author_meta('display_name', $author_id) : '';

        $posts_data[] = [
            'id'                => $pid,
            'name'              => $post->post_title,
            'description'       => $post->post_content,
            'short_description' => $post->post_excerpt,
            'url'               => get_permalink($pid),
            'date_created'      => $post->post_date,
            'date_modified'     => $post->post_modified,
            'author'            => $author_name,
            'taxonomies'        => $terms_data,
            'image_url'         => $image_url,
            'type'              => $this->post_type,
        ];
        return $posts_data;
    }
}
