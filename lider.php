<?php
/*
Plugin Name: Lider
Plugin URI: https://lider.io/
Description: Lider
Version: 1.0.0
Author: Héctor Acosta
Author URI: https://hectoracosta.dev/
License: GPL2
*/

// Aquí va el código de tu plugin.
// Definir constantes para la clave de licencia y la URL de la API

define( 'LIDER__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define('LIDER_PLUGIN_URL',plugins_url('', __FILE__));
register_activation_hook( __FILE__, array( 'Lider', 'plugin_activation' ) );
register_deactivation_hook( __FILE__, array( 'Lider', 'plugin_deactivation' ) );
require_once( LIDER__PLUGIN_DIR . 'class.lider.php' );
add_action('init', array('Lider', 'registrar_reescritura_url'));

add_action( 'init', array( 'Lider', 'init' ) );
// Agregar los endpoints REST para guardar y leer el token
add_action('rest_api_init', array('Lider', 'register_rest_routes') );



?>