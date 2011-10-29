<?php  if (!defined('BASEPATH')) exit('No direct script access allowed');
/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info...
|
*/

$hook['post_controller_constructor'][] = array(
                                'class'    => '',
                                'function' => 'pre_filter',
                                'filename' => 'init.php',
                                'filepath' => 'hooks/filters',
                                'params'   => array()
                                );

$hook['post_controller'][] = array(
                                'class'    => '',
                                'function' => 'post_filter',
                                'filename' => 'init.php',
                                'filepath' => 'hooks/filters',
                                'params'   => array()
                                );
                                
?>