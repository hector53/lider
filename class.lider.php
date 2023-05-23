<?php

use WpOrg\Requests\Response;

class Lider
{

    private $clave_licencia;
    private $api_url;
    // Constructor del plugin

    public static function init()
    {
        error_log('entrando a init');

        add_filter('template_redirect', array('Lider', 'show_page_liderpay'));
        // Agregar la página de opciones del plugin
        add_action('admin_menu', array('Lider', 'agregar_pagina_opciones'));
    }

    public static function register_rest_routes()
    {

        register_rest_route('lider/v1', '/token/', array(
            'methods' => 'POST',
            'callback' => array('Lider', 'save_token'),
            'content_type' => 'application/json',
        ));
    }

    public static function save_token(WP_REST_Request $request)
    {
        global $wpdb;
        $data = $request->get_json_params();
        error_log('entrando a save_token' . json_encode($data));
        $datos = $data["row"];
        $token_domain = $data["token_domain"];
        $lider_domain_token = get_option('lider_domain_key');
        if ($token_domain != $lider_domain_token) {
            wp_send_json_error('No estás autorizado para realizar esta acción.', 401);
        }
        $tabla = $wpdb->prefix . 'payment_token'; // Nombre de la tabla
        $datos = array(
            'invoice' => $datos['invoice'],
            'amount' => $datos['amount'],
            'currency' => $datos['currency'],
            'public_key' => $datos['public_key'],
            'token' => $datos['token'],
            'processor_id' => '',
            'template' => $datos['template'],
            'processor_token_paid' => '',
            'receipt_url' => '',
            'paid' => 0,
            'lider_token_id' => $datos['_id'],
            'created' => current_time('mysql'),
            'updated' => current_time('mysql'),
        ); // Datos a insertar
        $response = array(
            'status' => false,
            'id' => ''
        );
        $resultado = $wpdb->insert($tabla, $datos);
        if ($resultado === false) {
            // Ocurrió un error al insertar los datos
            wp_send_json_error('error al crear token', 404);
        } else {
            // Los datos se insertaron correctamente
            $response["status"] = true;
            $response["id"] = $wpdb->insert_id; // Obtener el ID de la fila insertada
        }
        return $response;
    }
    // Método para registrar la regla de reescritura de URL para la página "liderpay"
    public static function registrar_reescritura_url()
    {
        error_log('entrando a registrar_reescritura_url');
        add_rewrite_rule('^pay/([^/]*)/?', 'index.php?pagename=pay&token=$matches[1]', 'top');
        // Agregar la variable "token" a la lista de variables permitidas en la consulta GET
        add_filter('query_vars', function ($vars) {
            $vars[] = 'token';
            return $vars;
        });
    }

    // Método para registrar los endpoints REST
    public static function registrar_endpoints_rest()
    {
        error_log('entrando a registrar_endpoints_rest');
        // Agregar el endpoint para guardar el token
        register_rest_route('lider', '/token/', array(
            'methods' => 'PÖST',
            'callback' => array('Lider', 'guardar_token'),
        ));
    }
    function guardar_token($request)
    {
        // Obtener el token del cuerpo de la solicitud
        //   $token = $request->get_param('token');

        // Guardar el token en la base de datos o en otro lugar
        // ...

        // Devolver una respuesta con el token guardado
        return $obj["status"] = "hola";
    }
    // Método para crear la página "liderpay"
    private static function crear_pagina_liderpay()
    {
        $pagina = array(
            'post_title' => 'pay',
            'post_content' => '',
            'post_status' => 'publish',
            'post_type' => 'page'
        );

        wp_insert_post($pagina);
    }

    public static function show_page_liderpay()
    {
        error_log('entrando a la funcion show page liderpay:  ' . get_query_var('pagename') . 'ahora token:' . get_query_var('token'));
        if (get_query_var('pagename') === 'pay' && get_query_var('token')) {
            

            $token = get_query_var('token');
            //quiero verificar si el token existe aqui y en lider 
            $search_token_db = self::search_token_in_db($token);
            if ($search_token_db == null) {
                wp_redirect(esc_url(home_url('/404')));
                exit;
            }
            
            require_once(plugin_dir_path(__FILE__) . 'templates/payment_token.php');
            exit;
        }

        if (get_query_var('pagename') === 'pay') {
            wp_redirect(esc_url(home_url('/404')));
            exit;
        }
    }

    // Método para activar el plugin
    public static function plugin_activation()
    {
        // Agregar las opciones del plugin a la tabla de opciones de WordPress
        add_option('lider_domain_key', '');
        add_option('lider_url_api', '');
        global $wpdb;

        $tabla = $wpdb->prefix . 'payment_token';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $tabla (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            invoice VARCHAR(50) NOT NULL,
            amount FLOAT NOT NULL,
            currency VARCHAR(10) NOT NULL,
            public_key VARCHAR(100) NOT NULL,
            token VARCHAR(100) NOT NULL,
            processor_id VARCHAR(50) NOT NULL,
            template TINYINT(1) NOT NULL DEFAULT 0,
            processor_token_paid VARCHAR(255) NOT NULL,
            receipt_url VARCHAR(255) NOT NULL,
            paid TINYINT(1) NOT NULL DEFAULT 0,
            lider_token_id VARCHAR(255) NOT NULL,
            created DATETIME NOT NULL,
            updated DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);


        // Crear la página "liderpay"

        $pagina = get_page_by_path('pay');
        if (!$pagina) {
            self::crear_pagina_liderpay();
        }

        // Registrar la regla de reescritura de URL para la página "liderpay"
        self::registrar_reescritura_url();

        // Vaciar la caché de reglas de reescritura de URL
        flush_rewrite_rules();
    }

    public static function search_token_in_db($token)
    {
        global $wpdb;
        $tabla = $wpdb->prefix . 'payment_token'; // Nombre de la tabla
        $registro = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $tabla WHERE token = %s", $token)
        );
        if ($registro === null) {
            // No se encontró ningún registro con el token especificado
            return null;
        } else {
            // Se encontró un registro con el token especificado
            return $registro;
        }
    }

    public static function search_token_in_lider($token)
    {
        $url = get_option('lider_url_api');
        $token_domain = get_option('lider_domain_key');
        $args = array(
            'method' => 'POST',
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'token_domain' => $token_domain,
                'token_url' => $token,
            )),
        );
        $response = wp_remote_post($url . "/payment-token/get", $args);
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("Se produjo un error: $error_message");
            return null;
        } else {
            // La solicitud se realizó con éxito
            return $response;
        }
    }

    // Método para desactivar el plugin
    public static function plugin_deactivation()
    {
        // Eliminar las opciones del plugin de la tabla de opciones de WordPress
        //     delete_option('lider_domain_key');
        //   delete_option('lider_url_api');
        // Eliminar la tabla 'payment_token' de la base de datos de WordPress
        /*     global $wpdb;

          $tabla = $wpdb->prefix . 'payment_token';
  
          $sql = "DROP TABLE IF EXISTS $tabla;";
  
          $wpdb->query($sql);*/
    }

    // Método para agregar la página de opciones del plugin
    public static function agregar_pagina_opciones()
    {
        add_menu_page(
            'Opciones de Lider',
            'Lider',
            'manage_options',
            'lider',
            array('Lider', 'mostrar_pagina_opciones'),
            'dashicons-admin-plugins'
        );
    }

    // Método para mostrar la página de opciones del plugin
    public static function mostrar_pagina_opciones()
    {
        // Comprobar si el usuario actual tiene permiso para acceder a esta página
        if (!current_user_can('manage_options')) {
            return;
        }

        // Comprobar si se ha enviado el formulario de opciones
        if (isset($_POST['lider_guardar_opciones'])) {
            // Actualizar la clave de licencia y la URL de la API
            update_option('lider_domain_key', $_POST['lider_domain_key']);
            update_option('lider_url_api', $_POST['lider_url_api']);

            // Mostrar un mensaje de éxito
            echo '<div class="updated"><p>Las opciones se han guardado correctamente.</p></div>';
        }

        // Obtener la clave de licencia y la URL de la API desde la configuración del plugin
        $lider_domain_key = get_option('lider_domain_key');
        $lider_url_api = get_option('lider_url_api');
?>

        <div class="wrap">
            <h1>Opciones de Lider</h1>

            <form method="post">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="lider_domain_key">Domain key</label></th>
                            <td><input type="text" id="lider_domain_key" name="lider_domain_key" value="<?php echo esc_attr($lider_domain_key); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="lider_url_api">URL de la API</label></th>
                            <td><input type="text" id="lider_url_api" name="lider_url_api" value="<?php echo esc_attr($lider_url_api); ?>" class="regular-text"></td>
                        </tr>
                    </tbody>
                </table>

                <input type="hidden" name="lider_guardar_opciones" value="1">
                <?php submit_button('Guardar opciones'); ?>
            </form>
        </div>

<?php
    }
}

?>