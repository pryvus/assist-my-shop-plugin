<?php

class AMS_Batcher_WP extends AMS_Abstract_Bather implements AMS_Batcher_Impl_Interface {

    protected array $_handled_types = ['post']; 
    
    public function get_data(): array {
        $content_batcher = new AMS_Content_Batcher();
        return $content_batcher->get_posts_data( 'post' );
    }
}