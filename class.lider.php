<?php

use WpOrg\Requests\Response;

class Lider
{
    private $clave_licencia;
    private $api_url;
    private static $identy = ["stripe", "paypal", "coinbase"];
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

        register_rest_route('lider/v1', '/select_payment/', array(
            'methods' => 'POST',
            'callback' => array('Lider', 'select_payment'),
            'content_type' => 'application/json',
        ));

        register_rest_route('lider/v1', '/webhook_stripe/', array(
            'methods' => 'POST',
            'callback' => array('Lider', 'webhook_stripe'),
            'content_type' => 'application/json',
        ));
    }

    public static function get_stripe_rate($currency)
    {
        error_log('get_stripe_rate' . $currency);
        $striperates_key = get_option('striperates_key');
        $urlStripeRates = "https://api.striperates.com/rates/".strtolower($currency);
        $args = array(
            'method' => 'GET',
            'timeout' => 15,
            'headers' => array(
                'x-api-key' => $striperates_key,
            ),
        );
        $response = wp_remote_post($urlStripeRates, $args);
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("Se produjo un error: $error_message");
            return null;
        } else {
           # error_log("response:" . json_encode($response));
            $response_body = wp_remote_retrieve_body($response);
            $decodeBody = json_decode($response_body);
            $rates = $decodeBody->data[0]->rates;
            // La solicitud se realizó con éxito
          #  error_log("rates: " . strval($rates->usd));
            return $rates->usd;
        }
    }

    public static function webhook_stripe(WP_REST_Request $request)
    {
        global $wpdb;
        $data = $request->get_json_params();
        #  error_log('entrando a webhook' . json_encode($data));
        $payment_intent = $data["data"]["object"]["id"];
        $amount_received = $data["data"]["object"]["amount_received"];
        $status = $data["data"]["object"]["status"];
        $receipt_url = $data["data"]["object"]["charges"]["data"][0]["receipt_url"];
        #ahora si buscamos por el payment intent el token payment en la tabla local 
        $dataPayment = self::get_data_payment_by_pi($payment_intent);
        if ($dataPayment == null) {
            wp_send_json_error('error in get token payment', 404);
        }
        #seguimos 
        #verificamos montos 
        $amount_db = $dataPayment->amount;
        $amount_received = $amount_received / 100;
        if ($amount_received < $amount_db) {
            wp_send_json_error('error el monto pagado es menor al monto de pago', 404);
        }
        #seguimos 
        if ($status != "succeeded") {
            wp_send_json_error('error el status no es succeeded', 404);
        }
        $amount_conversion = $dataPayment->net_amount;
        if (strtoupper($dataPayment->currency) == "EUR") {
            #consultar en la api de rates
            $rate = self::get_stripe_rate($dataPayment->currency);
           # wp_send_json_error('error probando ', 404);
           $amount_conversion = $amount_conversion * $rate; 
           error_log("amount_conversion: " . strval($amount_conversion));
        }

        if (strtoupper($dataPayment->currency) == "GBP") {
            #consultar en la api de rates
            $rate = self::get_stripe_rate($dataPayment->currency);
           # wp_send_json_error('error probando ', 404);
           $amount_conversion = $amount_conversion * $rate; 
        }

        #ahora mandamos update en lider 
        $updateLider = self::update_lider_payment(
            $dataPayment->lider_token_id,
            $dataPayment->processor_identy,
            $dataPayment->fee,
            $dataPayment->net_amount,
            $amount_conversion,
            $receipt_url
        );
        if ($updateLider == null) {
            wp_send_json_error('error update token lider', 404);
        }

        #ahora si podemos actualizar los datos en db y en lider 
        $table_name = $wpdb->prefix . 'payment_token';
        $wpdb->update(
            $table_name,
            array(
                'paid' => 1,
                'receipt_url' => $receipt_url, 
                'amount_conversion' => $amount_conversion
            ),
            array('processor_token_paid' => $payment_intent),
            array('%s', '%s'),
            array('%s')
        );
        #  error_log('payment_intent' . json_encode($payment_intent));

        $response["status"] = "succeeded";
        return $response;
    }

    public static function update_lider_payment(
        $lider_token_id,
        $processor_identy,
        $fee,
        $net_amount,
        $amount_conversion,
        $receipt_url
    ) {
        error_log('update_lider_payment' . $processor_identy);
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
                'lider_token_id' => $lider_token_id,
                'identy' => $processor_identy,
                'fee' => $fee,
                'net_amount' => $net_amount,
                'amount_conversion' => $amount_conversion,
                'receipt_url' => $receipt_url
            )),
        );
        $response = wp_remote_post($url . "/payment-token/update_token", $args);
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("Se produjo un error: $error_message");
            return null;
        } else {
            error_log("response:" . json_encode($response));
            // La solicitud se realizó con éxito
            return $response;
        }
    }

    public static function get_data_payment_by_pi($payment_intent)
    {
        global $wpdb;
        $tabla = $wpdb->prefix . 'payment_token'; // Nombre de la tabla
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabla WHERE processor_token_paid = %s AND paid = 0",
            $payment_intent
        ));

        if ($result) {
            // Single result object    
            return $result;
        } else {
            return null;
        }
    }

    public static function get_data_processor($identy, $token, $id_processor)
    {
        error_log('get_data_processor' . $id_processor);
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
                'identy' => $identy,
                'id_processor' => $id_processor
            )),
        );
        $response = wp_remote_post($url . "/payment-token/get_processor", $args);
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("Se produjo un error: $error_message");
            return null;
        } else {
            // error_log("response:".json_encode($response));
            // La solicitud se realizó con éxito
            return $response;
        }
    }

    public static function select_payment(WP_REST_Request $request)
    {
        global $wpdb;
        $data = $request->get_json_params();
        error_log('entrando a select_payment' . json_encode($data));
        $identy = $data["identy"];
        $token = $data["token"];
        $id_processor = $data["id_processor"];
        error_log('entrando a select_payment2' . $id_processor);
        if ($identy == "" || $token == "" || $id_processor == "") {
            wp_send_json_error('identy o token vacio', 400);
        }
        #teniendo eso necsito los datos de stripe que estan en lider 
        #referente a este dominio
        $get_data_processor = self::get_data_processor($identy, $token, $id_processor);
        if ($get_data_processor == null) {
            wp_send_json_error('error', 404);
        }
        $response_body = wp_remote_retrieve_body($get_data_processor);
        $dataProcessor = json_decode($response_body);
        error_log('dataProcessor' . json_encode($dataProcessor));
        #ahora necesito los datos del payment token 
        $dataPayment = self::get_data_payment($token);
        if ($dataPayment == null) {
            wp_send_json_error('error in token payment', 404);
        }
        #ahora si tengo la data para crear el checkout 
        error_log('dataPayment' . json_encode($dataPayment));
        if (!in_array($identy, self::$identy)) {
            #no existe
            wp_send_json_error('error identy not found', 404);
        }
        $checkout = "";
        #ahora si listo vamos pa stripe 
        if ($identy == "stripe") {
            #VAMOS PA STRIPE 
            $checkout = self::get_stripe_checkout($dataPayment, $dataProcessor, $token);
        }
        return $checkout;
    }

    public static function get_stripe_checkout($dataPayment, $dataProcessor, $token)
    {
        /*
            dataProcessor{"public_key":"","secret_key":"","fee_extra":{"type":"%","value":10},
            "custom_fee":5},
        */
        require_once(LIDER__PLUGIN_DIR . '/libs/stripe/init.php');
        global $wpdb;
        $stripeSecretKey = $dataProcessor->secret_key;
        \Stripe\Stripe::setApiKey($stripeSecretKey);
        $url = get_site_url();
        $amount = $dataPayment->amount * 100; #50 * 100 = 500
        $feeExtra = 0;
        if ($dataProcessor->fee_extra->type == "%") { #fee=4
            $feeExtra = ($amount * $dataProcessor->fee_extra->value) / 100; #20
        } else {
            $feeExtra = $dataProcessor->fee_extra->value * 100;
        }
        $amount = round($amount + $feeExtra, 2);

        $amount_conversion = $amount;


        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $dataPayment->currency,

                    'product_data' => [
                        'name' => $dataPayment->invoice,
                    ],
                    'unit_amount' => $amount,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $url,
            'cancel_url' => $url . "/pay/" . $token,

        ]);
        //actualizar 
        $table_name = $wpdb->prefix . 'payment_token';

        $wpdb->update(
            $table_name,
            array(
                'processor_token_paid' => $session->payment_intent,
                'fee' => $feeExtra / 100, 'net_amount' => $amount / 100,
                'amount_conversion' => $amount_conversion / 100,
                'processor_identy' => 'stripe',
            ),
            array('token' => $token),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%s')
        );
        return $session;
    }

    public static function get_data_payment($token)
    {
        global $wpdb;
        $tabla = $wpdb->prefix . 'payment_token'; // Nombre de la tabla
        $result = $wpdb->get_row("
        SELECT * 
        FROM $tabla
        WHERE token = '" . $token . "'  
        ");

        if ($result) {
            // Single result object    
            return $result;
        } else {
            return null;
        }
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
        add_option('striperates_key', '');
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
            processor_token_paid VARCHAR(255) NULL,
            paid TINYINT(1) NOT NULL DEFAULT 0,
            lider_token_id VARCHAR(255) NOT NULL,
            processor_identy VARCHAR(50) NULL ,
            fee FLOAT NOT NULL DEFAULT 0,
            net_amount FLOAT NOT NULL DEFAULT 0,
            amount_conversion FLOAT NOT NULL DEFAULT 0,
            receipt_url VARCHAR(255) NULL,
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
        // error_log("url send post :".$url);
        //error_log("token_domain :".$token_domain);
        //error_log("args :".json_encode($args));

        $response = wp_remote_post($url . "/payment-token/get", $args);
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("Se produjo un error: $error_message");
            return null;
        } else {
            // error_log("response:".json_encode($response));
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
            update_option('striperates_key', $_POST['striperates_key']);
            // Mostrar un mensaje de éxito
            echo '<div class="updated"><p>Las opciones se han guardado correctamente.</p></div>';
        }
        // Obtener la clave de licencia y la URL de la API desde la configuración del plugin
        $lider_domain_key = get_option('lider_domain_key');
        $lider_url_api = get_option('lider_url_api');
        $striperates_key = get_option('striperates_key');
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
                        <tr>
                            <th scope="row"><label for="striperates_key">Stripe rates key</label></th>
                            <td><input type="text" id="striperates_key" name="striperates_key" value="<?php echo esc_attr($striperates_key); ?>" class="regular-text"></td>
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