<?php

class AMS_Batcher {
    /**
     * @var array<AMS_Batcher_Impl_Interface> $_sources
      * An array of data sources that implement the AMS_Batcher_Impl_Interface. Each source is responsible for providing a batch of data when requested.
     */
    private array $_sources = [];

    public function __construct() {
        $this->load_sources();
    }

    private function load_sources() {
        // Dynamically load all batcher implementations from the 'batcher/implements' directory
        $implementations_dir = __DIR__ . '/implements';
        if ( is_dir( $implementations_dir ) ) {
            foreach ( glob( $implementations_dir . '/*.php' ) as $file ) {
                require_once $file;
                // Instantiate the class if it implements the interface
                $class_name = basename( $file, '.php' );
                if ( class_exists( $class_name ) && in_array( 'AMS_Batcher_Impl_Interface', class_implements( $class_name ) ) ) {
                    $_instance = new $class_name();
                    if ( $_instance->is_enabled() ) { 
                        $this->_sources[] = $_instance;
                    }
                }
            }
        }
    }

    public function do_batch(): array {
        $results = [];
        foreach ( $this->_sources as $source ) {
            $data = $source->get_data();
            if ( ! empty( $data ) ) {
                $results = array_merge( $results, $data );
            }
        }
        return $results;
    }
}