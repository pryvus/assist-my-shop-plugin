<?php

abstract class AMS_Abstract_Bather implements AMS_Batcher_Impl_Interface
{

    use AMS_Logger;

    private $_enabled_types = [];
    private $_batch_size = 50;
    protected $_handled_types = [];

    public function __construct()
    {
        $enabled_types = get_option('ams_post_types', []);
        $this->_enabled_types = array_intersect($enabled_types, $this->_handled_types);
        if (empty($this->_enabled_types)) {
            self::log(__CLASS__ . ': Disabled.', null, 'warning');
        } else {
            self::log(__CLASS__ . 'Batcher initialized with enabled post types.', ['enabled_types' => $this->_enabled_types], 'info');
        }
    }

    public function is_enabled(): bool
    {
        return ! empty($this->_enabled_types);
    }
}
