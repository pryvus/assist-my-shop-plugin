<?php

interface AMS_Batcher_Impl_Interface {

    public function get_data() : array;

    public function is_enabled() : bool;
}