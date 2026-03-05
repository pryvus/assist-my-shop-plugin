<?php

function ams_psr0_autoloader($class)
{
    // Check if the class belongs to our plugin's namespace
    if (strpos($class, 'AMS_') === 0) {
        $basedir = plugin_dir_path(__FILE__) . 'includes' . DIRECTORY_SEPARATOR;
        return ams_load_class_or_inetrface($class, $basedir);
    }
}

function ams_load_class_or_inetrface($class, $basedir)
{
    $class_short = substr($class, 4);
    $filename_base = strtolower(str_replace('_', '-', $class_short));
    $class_file = $basedir . "class-ams-" . $filename_base . '.php';
    if (file_exists($class_file)) {
        require_once $class_file;
        return true;
    }
    # if not found in root, try load submodules
    $parts = explode('_', $class_short);
    if (count($parts) < 2) {
        $implemnt = null;
        $sub = $parts[0];
    } else {
        [$sub, $implemnt] = $parts;
    }
    if (!in_array($implemnt, ['Interface', 'Base'])) {
        // Loadign implementation file
        if ($implemnt != null) {
            $impl_file = $basedir . strtolower($sub) . DIRECTORY_SEPARATOR . 'implements' . DIRECTORY_SEPARATOR . "class-ams-$filename_base.php";
            if (file_exists($impl_file)) {
                require_once $impl_file;
                return true;
            }
        } else {
            $sub_class = $basedir . strtolower($sub) . DIRECTORY_SEPARATOR . "class-ams-$filename_base.php";
            if (file_exists($sub_class)) {
                require_once $sub_class;
                return true;
            }
        }
    } 
    elseif ($implemnt === 'Interface') {
        // Loading interface or base class
        $interface_file = $basedir . strtolower($sub) . DIRECTORY_SEPARATOR . "class-ams-$filename_base.php";
        if (file_exists($interface_file)) {
            require_once $interface_file;
            return true;
        }
    }
    elseif ($implemnt === 'Base') {
        $base_file = $basedir . strtolower($sub) . DIRECTORY_SEPARATOR . "class-ams-$filename_base.php";
        if (file_exists($base_file)) {
            require_once $base_file;
            return true;
        }
    }
}