<?php
/**
@package WPMEGACloud
Plugin Name: MEGA Cloud
Description: Gestiona tus ficheros en la nube con MEGA desde tu website con este plugin.
Version: 1.0
Author: Togacharls
License: GPL2+
*/

require_once 'defines.php';
//require_once 'MEGA_node.php';
require_once 'lib/mega.class.php';
require_once ABSPATH.'wp-load.php';
require_once ABSPATH . 'wp-includes/pluggable.php';
require_once ABSPATH . 'wp-includes/rest-api.php';
    
/*
 * Clase principal del plugin
 */
class WPMEGALOUD{
    
    private $client;
    private $html;
    private $error;
    private $debug;
    private $log;
    private $nodes;
    
    private $wp_user;
    
    public function __construct(){
        $this->debug = FALSE;
        $this->log = "";
        $this->error = array();
        $this->init();
    }
    
    /*
     * Muestra la entrada en el menú de administración
     */
    public function admin_menu(){
        //Aparece directamente en el menú de la izquierda
        add_menu_page(
            'MEGA Cloud',                  //Page-title
            'MEGA Cloud',                  //Menu-Title
            'manage_options',               //Capability
            'wpmegacloud',                  //Menu_slug (URL, .../wp-admin/admin.php?page=<Menu_slug>)
            array($this, 'admin_gui'),  //$gui//,
            PLUGIN_URL.'img/wpmegacloud.png' //'icono',
        );
        //Aparece en el submenú "Medios"
        /*add_media_page(
            'WP_MEGA',                  //Page-title
            'WP_MEGA',                  //Menu-Title
            'manage_options',           //Capability
            'wp-mega',                  //Menu_slug (URL, .../wp-admin/admin.php?page=<Menu_slug>)
            array($this, 'admin_gui')  //$gui//,
        );*/
    }
    
    public function wp_url(){
        echo' <script> var WP_URL = "'.get_site_url().'"; </script>';
    }
    /*
     * Muestra la interfaz de usuario del backend
     */
    public function admin_gui(){
        echo $this->printNavTabs();
        echo $this->printError();
        echo $this->printConfig();
        echo $this->printNodes();
        //TODO dar un botón para limpiar los nodos corruptos de MEGA desde aquí
        //$this->clean_corrupt_nodes(); *Esta función únicamente debe ser utilizada si se crean nodos corruptos en la cuenta de MEGA
        if($this->debug){
            echo '<h3>Debug:</h3> <div class="wpmega_log">'.$this->log.'</div>';
        }
    }
    
    /*
     * Añade los ficheros JS/CSS necesarios para el plugin
     */
    public function enqueue_scripts(){
        //Se registran los scripts
        wp_register_script( 'wpmega-main-js', PLUGIN_URL."js/wpmegacloud.js");
        wp_register_style( 'wpmega-main-css', PLUGIN_URL."css/wpmegacloud.css");
        
        //Se encolan los scripts
        wp_enqueue_script( 'wpmega-main-js');
        wp_enqueue_style( 'wpmega-main-css');
    }
    
    /*
     * Se encarga de subir un fichero privado a MEGA
     * Se lanza con el hook add_attachment
     * No implementado en la API
     * @param $post_id = ID del POST asociado al fichero. Lo envía el propio hook
     * @return TRUE/FALSE
     */
    public function upload_file($post_id){
        //Se determina en función de WPMEGA_UPLOAD_OPTION si el usuario actual puede o no subir a MEGA
        switch (get_option(WPMC_UPLOAD_OPTION)){
            case WPMC_UPLOAD_ONLY_USERS:
                if(is_super_admin($this->wp_user->ID)){
                    $dir = NULL;
                    return ;
                }
                break;
            case WPMC_UPLOAD_ONLY_ADMIN:
                if(!is_super_admin($this->wp_user->ID)){
                    $dir = NULL;
                    return ;
                }
                break;
            case WPMC_UPLOAD_BOTH:
                $dir = NULL;
                return ;
            case WPMC_UPLOAD_NOBODY:
                break;
        }
        
        //Como este método se llama a través de un Hook es necesario volver a hacer login en MEGA
        $sessionLoaded = $this->getMEGASession();
        $post = get_post($post_id, ARRAY_A);
        
        if(!current_user_can("attach_files")){
            return ERROR_NOT_ALLOWED;
        }
        
        $guid = $post["guid"];
        $user_dir = $this->get_user_folder();
 
        $response_uload_class = $this->client->upload_file($guid, $user_dir);
        
        //Se borra el fichero temporal si el usuario NO es admin y la configuración lo permite
        if(get_option(WPMC_REMOVE_LOCAL_FILES_OPTION) === 'yes'){
            $node_id = $response_uload_class[0]["f"][0]["h"];
            add_post_meta( $post_id, WPMC_NODE_META_KEY, $node_id);
            $local_path = parse_url($guid, PHP_URL_PATH);
            $path = ABSPATH.substr($local_path, 1);
            unlink($path);
        }
    }
    
    /*
     * Obtiene un fichero público o privado de MEGA
     * @param $node_id = ID del nodo
     * @return ERROR_CODE
     */
    public function download_file($node_id){
        
        $post = $this->getPost($node_id);
        
        if(!isset($post) && get_option(WPMC_ALLOW_NO_WPMC_FILES_OPTION) === "no"){
            return __("ERROR: Este fichero solo es accesible desde MEGA");
        }
        
        if(/*!user_can($this->wp_user->ID, 'view_ticket')
          &&*/ (!is_super_admin($this->wp_user->ID) && (isset($post) && $post->post_author !== $this->wp_user->ID)) ){
            return __("No estas autorizado para acceder a este archivo");
        }

        $fileFound = FALSE;
        foreach($this->nodes as &$node){
            if($node->getId() == $node_id){
                $file = $this->client->node_file_download($node->toArray());
                $fileFound = TRUE;
                $filename = $node->getAttributes("a")["n"];
                break;
            }
        }
        
        if(!$fileFound){
            return __("ERROR: No se ha encontrado el archivo");
        }

        return $this->sendFile($filename, $file);
    }
    
    /*
     * Descarga un fichero adjunto a un ticket del plugin "Awesome-support" en lugar de mostrarlo
     */
    public function download_wpas_attachment(){
        if(isset($_GET["wpas-attachment"])){
            $post_id = $_GET["wpas-attachment"];
            $node = $this->getNode($post_id);
            
            if(isset($node)){
                $this->download_file($node->getId());
            }else{
                //TODO implementar. ERROR no se ha encontrado el fichero
                echo 'No se ha podido obtener el fichero. Puede que éste haya sido eliminado de MEGA';exit();
                array_push($this->error, __('*No se ha podido obtener el fichero. Puede que éste haya sido eliminado de MEGA'));
            }  
        }
    }
    
    /*
     * Elimina un fichero/directorio de MEGA
     * @param $node_id = ID del nodo
     */
    public function delete_node($node_id){
        $found = FALSE;
        foreach($this->nodes as &$node){
            if($node->getId() === $node_id && ($node->getType() === MEGA_FILE || $node->getType() === MEGA_FOLDER) ){
                $this->client->delete($node_id);
                $found = TRUE;
                break;
            }
        }
        if(!$found){
            array_push($this->error, __('*No se ha podido encontrado el fichero o directorio en tu disco de MEGA."'));
        }else{
            $this->sortNodes();
        }
    }
    
    /*
     * Hace que los ficheros que se han subido a MEGA no aparezcan
     * en la biblioteca de medios
     */
    public function dont_load_media($query){
        
        $current_uri = $_SERVER['REQUEST_URI'];
        if($current_uri === MEDIA_LIBRARY_URI){ 
            $meta_query = array(
                array(
                    'key' => WPMC_NODE_META_KEY,
                    'compare' => 'NOT EXISTS'
                )
            );
            $query->set("meta_query", $meta_query);
            return $query;
        }
        return $query;
    }
    
    /*
     * Limpia los nodos corruptos de MEGA
     */
    public function clean_corrupt_nodes(){
        $this->log .= "<h2> To clean: </h2>";
        foreach($this->nodes as &$node){
            $attributes = $node->getAttributes();
            $json_attributes = json_encode($attributes);
            if(isset($attributes) 
              && (strpos($json_attributes, '"p":null' )!= FALSE || strpos($json_attributes, '[{"f"'))
              && ($node->getType() === MEGA_FILE || $node->getType() === MEGA_FOLDER )){
                $this->log .= "<p>".json_encode($node->toArray())."</p>";
                $this->client->delete($node->getId());
            }
        }   
    }
    
    /*
     * Limpia la base de datos al DESINSTALAR el plugin
     */
    public function uninstall_wpmega(){
        delete_option(WPMC_ROOT_FOLDER_VISIBILITY_OPTION);
        delete_option(WPMC_TRASH_BIN_VISIBILITY_OPTION);
        delete_option(WPMC_UPLOAD_OPTION);
        delete_option(WPMC_ROOT_FOLDER_OPTION);
        delete_option(WPMC_MAIL_OPTION);
        delete_option(WPMC_SESSION_OPTION);
        delete_option(WPMC_REMOVE_LOCAL_FILES_OPTION);
        delete_option(WPMC_REMOVE_MEGA_FILES_OPTION);
    }
    
    /*
     * Limpia la sesión y el nodo del directorio principal de datos al DESACTIVAR el plugin.
     */
    public function deactivation_wpmega(){
        delete_option(WPMC_ROOT_FOLDER_VISIBILITY_OPTION);
        delete_option(WPMC_TRASH_BIN_VISIBILITY_OPTION);
        delete_option(WPMC_UPLOAD_OPTION);
    }
   
    /*
     * Actualiza los valores de las diferentes opciones de configuración (REST)
     */
    public function rest_options(){
        
        if(!is_super_admin($this->wp_user->ID)){
            return array("code" => "ERROR", "message" => "No tienes permiso para realizar esta acción");
        }
        
        if(isset($_POST['wpmega_root_folder_visibility_option'])) {
            $value = $_POST['wpmega_root_folder_visibility_option'];
            $result = update_option(WPMC_ROOT_FOLDER_VISIBILITY_OPTION, $value);
        }
            
        if(isset($_POST['wpmega_trash_bin_visibility_option'])) {
            $value = $_POST['wpmega_trash_bin_visibility_option'];
            $result = update_option(WPMC_TRASH_BIN_VISIBILITY_OPTION, $value);
        }
            
        if(isset($_POST['wpmega_upload_option'])) {
            $value = $_POST['wpmega_upload_option'];
            $result = update_option(WPMC_UPLOAD_OPTION, $value);
        }
            
        if(isset($_POST['wpmega_remove_local_files_option'])) {
            $value = $_POST['wpmega_remove_local_files_option'];
            $result = update_option(WPMC_REMOVE_LOCAL_FILES_OPTION, $value);
        }
            
        if(isset($_POST['wpmega_remove_mega_files_option'])) {
            $value = $_POST['wpmega_remove_mega_files_option'];
            $result = update_option(WPMC_REMOVE_MEGA_FILES_OPTION, $value);
        }
        
        if(isset($_POST['wpmega_allow_no_wp_mega_files_option'])) {
            $value = $_POST['wpmega_allow_no_wp_mega_files_option'];
            $result = update_option(WPMC_ALLOW_NO_WPMC_FILES_OPTION, $value);
        }
        
        if($result){
            return array("code" => "OK");
        }else{
            return array("code" => "ERROR", "message" => "Ha ocurrido un error modificando la configuración.");
        }
    }
    
    /*
     * Gestiona las diferentes peticiones REST sobre nodos de MEGA
     */
    public function rest_nodes(){
        //Download File
        if(isset($_GET["wpmn"])){
            $node_id=$_GET["wpmn"];
            foreach($this->nodes as &$node){
                if($node->getId() == $node_id){
                    if ($node->getType() == MEGA_FILE){
                        return $this->download_file($node_id);
                    }else{
                        return array(
                            "code" => "ERROR",
                            "message" => "El nodo recibido no es un fichero",
                        );
                    }
                }
            }
        }
        
        //Remove Node
        if(isset($_POST["wpmn_r"])){
            if(!is_super_admin($this->wp_user->ID)){
                return array("code" => "ERROR", "message" => "No tienes permiso para realizar esta acción");
            }
            $node_id=$_POST["wpmn_r"];
            $this->delete_node($node_id);
            return array( "code" => "OK");
        }
        
        //Si se hace una petición para recargar los nodos, se envía únicamente el HTML de éstos
        if(isset($_POST["wpmega_reload_nodes"])){
            $html = $this->printNodes();
            $firstTag = '<div id="tab_disc" class="wraper">';
            $lastTag = '</div>';
                
            $html = str_replace($firstTag, '', $html);
            $html = substr($html, 0, strlen($html) - strlen($lastTag));
            
            return array( "code" => "OK", "data" => $html);
        }
    }

    /*
     * Actualiza la sesión de MEGA
     * */
    public function rest_session(){
        if(!empty($_POST["user"]) && !empty($_POST["password"])){
            $user = $_POST["user"];
            $password = $_POST["password"];
            $this->updateMEGASession($user, $password);
            header("location: ./");
        }else{
            return array(
                "code" => 'ERROR',
                "message" => 'No se han rellenado los campos "Usuario" y "Contraseña"',
            );
        }
    }
    
    /*
     * Define la funcionalidad de la API REST
     */
    public function rest_api_routes(){
        $options_args = array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_session')
        );
        register_rest_route(WPMC_NAMESPACE, 'session', $options_args);

        $options_args = array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_options')
        );
        register_rest_route(WPMC_NAMESPACE, 'options', $options_args);
        
        $nodes_args = array(
            'methods' => 'GET, POST',
            'callback' => array($this, 'rest_nodes')
        );
        register_rest_route(WPMC_NAMESPACE, 'nodes', $nodes_args);
    }

    /*******PRIVATE*******/
    /*
     * Inicializa el plugin
     */
    private function init() {
        $sessionLoaded = $this->getMEGASession();
        //Si se inicia correctamente una sesión, se carga el listado de nodos
        if($sessionLoaded !== FALSE){
            $this->sortNodes();
            //Una vez se carga la sesión y los nodos, se comprueba si se ha registrado el directorio base
            if(isset($_POST["wpmega_user"]) && isset($_POST["wpmega_password"])){
                $this->update_base_folder();
            }
        }else{
            if(isset($_POST['wpmega_root_folder_visibility_option']) ||
               isset($_POST['wpmega_trash_bin_visibility_option']) ||
               isset($_POST['wpmega_upload_option'])){
                array_push($this->error, __('*Para realizar cambios en la configuración de este plugin es necesario iniciar sesión en MEGA'));
            }
        }
        
        //Cuando se accede a la biblioteca de medios sólo se cargarán aquellos ficheros que no tengan "?wpmn="
        add_filter('pre_get_posts', array($this, "dont_load_media"));
        //Añade el Menú y la Interfaz de Admin
        add_action('admin_menu', array($this, 'admin_menu'));
        //Al subir cada fichero, lo sube a MEGA. Este hook pasa el id del POST correspondiente al fichero
        add_action("add_attachment", array($this, 'upload_file'));
        //Si se tiene instalado el plugin "Awesome support" cuando se acceda al enlace de un fichero adjunto,
        //éste se descargará en lugar de mostrarse. Es necesario poner una prioridad de 9 (o menor) para garantizar que este método
        //se lanzará en el hook previamente al de "Awesome support" (prioridad = 10).
        add_action('template_redirect', array( $this, 'download_wpas_attachment' ), 9);
        
        add_action('wp_print_scripts', array($this, 'wp_url'));
        
        //add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts') );
        
        add_filter( 'rest_authentication_errors', function( $result ) {
            if ( ! empty( $result ) ) {
		return $result;
            }
            if ( ! is_user_logged_in() ) {
		return new WP_Error( 'restx_logged_out', 'Sorry, you must be logged in to make a request.', array( 'status' => 401 ) );
            }
            return $result;
        });
        
        /*
         *Es necesario almacenar la sesión dado que si se hace wp_get_current_user() desde el callback
         *del servicio REST, esta función devuelve el usuario con ID = 0.
         */
        $this->wp_user = wp_get_current_user();
        add_action('rest_api_init', array($this, 'rest_api_routes'));
        
        //Al DESACTIVAR el plugin se elimina la información relacionada con la sesión de MEGA
        register_deactivation_hook( __FILE__, array($this, 'deactivation_wpmega'));
        //Al DESINSTALAR el plugin se elimina TODA la información que se registra en la BD
        register_uninstall_hook(__FILE__, array($this, 'uninstall_wpmega'));
    }
    
    /*
     * Actualiza el directorio raíz sobre el cual trabajará el plugin. En caso de que no exista, lo crea en MEGA
     * y lo almacena en la base de datos asignado al user_id WPMEGA_FOLDER_USER_ID
     * TODO->añadir algún campo que permita gestionar desde una misma cuenta MEGA varios sitios web
     * @return String (node_id)
     */
    private function update_base_folder(){
        $root_node = $this->nodes[0];
        //Se comprueba si no se ha registrado previamente el base_folder del sitio web en la BD
        $wpmega_site_folder_node_id = get_option(WPMC_ROOT_FOLDER_OPTION);
        
        if(isset($wpmega_site_folder_node_id)){
            $exist_node = FALSE;
            foreach($this->nodes as &$node){
                if($node->getId() === $wpmega_site_folder_node_id){
                    $exist_node = TRUE;
                    break;
                }
            }
            if(!$exist_node){
                delete_option(WPMC_ROOT_FOLDER_OPTION);
                $wpmega_site_folder_node_id = FALSE;
            }
        }
        
        //Se registra el directorio Raíz donde se almacenarán los directorios de los usuarios en caso de que no exista
        if(!$wpmega_site_folder_node_id){
            $website_name = get_bloginfo( 'name' );
            //Se comprueba si existe un directorio en la raíz de MEGA con el nombre del website.
            foreach($this->nodes as &$node){
                if($node->getAttributes()["n"] === $website_name && $node->getParent() === $root_node->getId()){
                    $wpmega_site_folder_node_id = $node->getId();
                    break;
                }
            }
            //En caso de que no exista ningún directorio con el nombre del website, éste se crea
            if(!$wpmega_site_folder_node_id){
                $wpmega_site_folder_node = $this->client->create_folder($website_name, $root_node->getId());
                $wpmega_site_folder_node_id = $wpmega_site_folder_node[0]["f"][0]["h"];
            }
            if(isset($wpmega_site_folder_node_id) && $wpmega_site_folder_node_id !== FALSE){
                update_option(WPMC_ROOT_FOLDER_OPTION, $wpmega_site_folder_node_id);
            }
        }
        return $wpmega_site_folder_node_id;
    }
    /*
     * Devuelve un nodo de MEGA a partir del ID del post asociado al fichero
     * @param $post_id = ID del post
     * @return WP_MEGA_NODE/NULL
     */
    private function getNode($post_id){        
        $node_id = get_post_meta($post_id, WPMC_NODE_META_KEY)[0];
        foreach ($this->nodes as &$node){
            if($node->getId() === $node_id){
                return $node;
            }
        }
        return NULL;
    }
    
    /*
     * Devuelve un post a partir nodo de MEGA asociado
     * @param $node_id = ID del nodp
     * @return POST (ARRAY_A)/NULL
     */
    private function getPost($node_id){
        global $wpdb;
        $array = array($node_id, WPMC_NODE_META_KEY);
        
        $query = "SELECT post_id FROM ".$wpdb->prefix."postmeta WHERE meta_value=%s AND meta_key=%s LIMIT 1";
        $prepared = $wpdb->prepare($query, $array);
        $data = $wpdb->get_results($prepared, ARRAY_A);
        
        if(empty($data)){
            return NULL;
        }
        
        $post_id = $data[0]["post_id"];
        return get_post($post_id);
    }
    
    /*
     * Recupera la sesión de MEGA almacenada en la base de datos
     * @return MEGA/FALSE
     */
    private function getMEGASession(){
        $this->load_default_data();
        
        $session = get_option(WPMC_SESSION_OPTION);
        $mail = get_option(WPMC_MAIL_OPTION);
        
        if(empty($session) || empty($mail)){
            $this->log .= "<p>No session o no mail</p>";
            array_push($this->error, __("*No se ha iniciado ninguna sesión en MEGA"));
            return FALSE;
        }else{
           $this->log .= "<p>Loading session...</p>";
           $this->log .= "<p>Mail: ".$mail."</p>";
           $this->client = MEGA::create_from_session($session);
           $this->user = $mail;
        }
        return TRUE;
    }
    
    /*
     * Guarda la sesión de MEGA en la base de datos
     * @return TRUE/FALSE
     */
    private function updateMEGASession($mail, $password){
        if(empty($mail) || empty($password)){
            array_push($this->error, __('*No se han rellenado los campos "Usuario" y "Contraseña"'));
            return FALSE;
        }
        
        $this->client = MEGA::create_from_login($mail, $password);
        $token = MEGA::session_save($this->client);
        
        if ($token === ERROR_NO_VALID_SESSION_TOKEN){
            unset($this->client);
            array_push($this->error, __('*Usuario o contraseña de MEGA inválido'));
            return FALSE;
        }
        
        update_option(WPMC_SESSION_OPTION, $token);
        update_option(WPMC_MAIL_OPTION, $mail);
        
        //Se elimina de la base de datos el registro del Root folder del usuario de MEGA anterior.
        delete_option(WPMC_ROOT_FOLDER_OPTION);

        //Se eliminan las referencias a los nodos asociados a usuarios de la base de datos
        $users = get_users();
        foreach ($users as &$user){
            delete_user_meta($user->ID, WPMC_USER_FOLDER_META_KEY);
        }
        
        return TRUE;
    }
    
    /*
     * Devuelve el id del nodo del directorio asociado al usuario actual.
     * En caso de que éste no tenga ningún directorio asociado, se crea uno en MEGA
     * @return INT (node_id)
     */
    private function get_user_folder(){
        $current_user = wp_get_current_user();
        //Se comprueba si existe una carpeta asociada al usuario        
        $client_folder_node_id = get_user_meta($current_user->ID, WPMC_USER_FOLDER_META_KEY);
        
        //Se comprueba si el nodo registrado en la BD existe (Puede haber sido borrado desde MEGA)
        $exists_node = FALSE;
        foreach ($this->nodes as $node){
            if($node->getId() === $client_folder_node_id){
                $exists_node = TRUE;
                break;
            }
        }
        
        //En caso de que no exista el logo registrado en la BD, éste se elimina de la misma
        if(!$exists_node){
            delete_user_meta($current_user->ID, WPMC_USER_FOLDER_META_KEY);
        }
        
        //En caso de que el usuario no tenga una carpeta asociada, ésta se crea.
        //TODO descomentar -> if(empty(get_option(WPMEGA_FOLDER_OPTION))){}
        if(!isset($client_folder_node_id) || !$exists_node){
            //Se obtiene el ID del directorio raíz de WP_MEGA en MEGA 
            //$base_folder_node_id = $this->get_base_folder();
            $base_folder_node_id = get_option(WPMC_ROOT_FOLDER_OPTION);
            //Se crea el directorio del usuario dentro del directorio raíz de WP_MEGA
            $client_folder_node = $this->client->create_folder($current_user->user_login, $base_folder_node_id);
            $client_folder_node_id = $client_folder_node[0]["f"][0]["h"];
            
            update_user_meta($current_user->ID, WPMC_USER_FOLDER_META_KEY, $client_folder_node_id);
            
            //Como se ha añadido un nodo nuevo, es necesario volver a cargar los nodos
            $this->sortNodes();
        }
        return $client_folder_node_id;
    }
    
    /*
     * Carga la información necesaria por el plugin en la BD
     */
    private function load_default_data(){
        if(!get_option(WPMC_ROOT_FOLDER_VISIBILITY_OPTION)){
            update_option(WPMC_ROOT_FOLDER_VISIBILITY_OPTION, "no");
        }
        if(!get_option(WPMC_TRASH_BIN_VISIBILITY_OPTION)){
            update_option(WPMC_TRASH_BIN_VISIBILITY_OPTION, "no");
        }
        if(!get_option(WPMC_UPLOAD_OPTION)){
            update_option(WPMC_UPLOAD_OPTION, WPMC_UPLOAD_ONLY_USERS);
        }
        if(!get_option(WPMC_REMOVE_MEGA_FILES_OPTION)){
            update_option(WPMC_REMOVE_MEGA_FILES_OPTION, "no");
        }
        if(!get_option(WPMC_REMOVE_LOCAL_FILES_OPTION)){
            update_option(WPMC_REMOVE_LOCAL_FILES_OPTION, "yes");
        }
        if(!get_option(WPMC_ALLOW_NO_WPMC_FILES_OPTION)){
            update_option(WPMC_ALLOW_NO_WPMC_FILES_OPTION, "no");
        }
    }
    
    /*
     * Ordena los nodos y le asigna el grado de profundidad que le corresponde a cada uno
     */
    private function sortNodes(){
       
        $nodes = $this->client->node_list();
        if(!$nodes){
            return false;
        }
        $this->log .= "<div>";
        
        $root_node = NULL;
        $trash_bin_node = NULL;
        $tmpNodes = array();
        
        foreach ($nodes["f"] as &$node){
            $this->log .= "<p>NODE: ".  json_encode($node);
            switch ($node["t"]){
                case MEGA_ROOT_FOLDER:
                    $this->log .= " - Root Folder";
                    $root_node = new MEGA_NODE($node);
                    break;
                case MEGA_FILE:
                    $this->log .= " - File";
                    array_push($tmpNodes, new MEGA_NODE($node));
                    break;
                case MEGA_FOLDER:
                    $this->log .= " - Folder";
                    array_push($tmpNodes, new MEGA_NODE($node));
                    break;
                case MEGA_TRASH_BIN:
                    $this->log .= " - Trash bin";
                    $trash_bin_node = new MEGA_NODE($node);
                    break;
                default :
                    $this->log .= " - Another one";
                    break;
            }
            $this->log .= "</p>";
        }
        
        if(get_option(WPMC_ROOT_FOLDER_VISIBILITY_OPTION) === 'no'){
            $website_name = get_bloginfo( 'name' );
            foreach ($tmpNodes as &$node){
                if($node->getDeep() < 2){
                    if($node->getAttributes()["n"] === $website_name){
                        $root_node = $node;
                    }else{
                        unset($node);
                        continue;
                    }
                }
                $node->setDeep($node->getDeep() -1 );
            }
        }
        
        $this->nodes = $this->buildTree($tmpNodes, $root_node);
        if(get_option(WPMC_TRASH_BIN_VISIBILITY_OPTION) === 'yes'){
            $deletedNodes = $this->buildTree($tmpNodes, $trash_bin_node, 1);
            foreach ($deletedNodes as $node){
                array_push($this->nodes, $node);
            }
        }
        $this->log .= "</div>";
    }
    
    /*
     * Algoritmo recursivo para ordenar un árbol dentro de un array
     */
    private function buildTree($elements, $parent, $deep=0) {
        $branch = array();
        $parent->setDeep($deep);
        array_push($branch, $parent);
        
        foreach ($elements as $element) {
            if ($element->getParent() === $parent->getId()) {
                $children = $this->buildTree($elements, $element, $deep + 1);
                foreach ($children as $child){
                    array_push($branch, $child);
                }
            }
        }
        return $branch;
    }
    
    /*
     * Devuelve el HTML con la barra de navegación
     * @return String (HTML)
     */
    private function printNavTabs(){
        $html = '
        <div class="wrap">
            <h1>MEGA Cloud</h1>
            <h2 id="wpmega_tabs" class="nav-tab-wrapper">';
        //if(!empty($session) && !empty($mail)){
            $html .= '
                <a id="nav-tab-disc" class="nav-tab nav-tab-active" href="#disc">'.__('Disco en la nube').'</a>';
        //}
        $html .='
                <a id="nav-tab-config" class="nav-tab" href="#config">'.__('Configuración').'</a>
            </h2>
        </div>';

        return $html;
    }
    
    /*
     * Devuelve el HTML con el listado de errores registrados
     * @return String (HTML)
     */
    private function printError(){
        $html = "";
        if(!empty($this->error)){
            foreach ($this->error as &$error){
                $html .= '<p class="wpmega_error">'.$error.'</p>';
            }
        }
        return $html;
    }
    
    /*
     * Devuelve el HTML con la sección de configuración
     * @return String (HTML)
     */
    private function printConfig(){
        $html = '<div id="tab_config" class="wrap wpmc_hide">
            <p>'.__('Introduce tu usuario y contraseña de MEGA para integrar tu disco en la nube con Wordpress').'</p>
            <div class="wpmega_form">
                <!--<form>-->
                    '.__('Usuario').': <input id="wpmc_user" type="text" value="'.$this->user.'"><br/>
                    '.__('Contraseña').': <input id="wpmc_password" type="password"/><br/>
                    <!--<input type="submit" id="wpmega_button" value="Actualizar" onclick="WPMEGACLOUD.updateSession()"/>-->
                    <button id="wpmega_button" onclick="WPMEGACLOUD.updateSession()">'.__('Actualizar').'</button>
                <!--</form>-->
            </div>';
        
        if(get_option(WPMC_ROOT_FOLDER_VISIBILITY_OPTION) === "no"){
            $html .= '<br/><input type="checkbox" id="wpmega_checkbox_root_folder"/><label>';
        }else{
           $html .= '<br/><input type="checkbox" id="wpmega_checkbox_root_folder" checked/><label>';
        }
        $html .= __('Cargar mi directorio raíz de MEGA con este plugin').'</label><br/>';
        
        if(get_option(WPMC_TRASH_BIN_VISIBILITY_OPTION) === "no"){
            $html .= '<input type="checkbox" id="wpmega_checkbox_trash_bin"/><label>';
        }else{
           $html .= '<input type="checkbox" id="wpmega_checkbox_trash_bin" checked/><label>';
        }
        $html .= __('Cargar mi papelera de MEGA con este plugin').'</label><br/>';
        
        if(get_option(WPMC_REMOVE_LOCAL_FILES_OPTION) === "no"){
            $html .= '<input type="checkbox" id="wpmega_remove_local_files"/><label>';
        }else{
           $html .= '<input type="checkbox" id="wpmega_remove_local_files" checked/><label>';
        }
        $html .= __('Eliminar automáticamente del servidor local los ficheros subidos a MEGA').'</label><br/>';
        
        if(get_option(WPMC_REMOVE_MEGA_FILES_OPTION) === "no"){
            $html .= '<input type="checkbox" id="wpmega_remove_mega_files"/><label>';
        }else{
           $html .= '<input type="checkbox" id="wpmega_remove_mega_files" checked/><label>';
        }
        $html .= __('Permitir eliminar ficheros de MEGA desde este sitio web').'</label><br/>';

        if(get_option(WPMC_ALLOW_NO_WPMC_FILES_OPTION) === "no"){
            $html .= '<input type="checkbox" id="wpmega_allow_no_wp_mega_files_option"/><label>';
        }else{
           $html .= '<input type="checkbox" id="wpmega_allow_no_wp_mega_files_option" checked/><label>';
        }
        $html .= __('Permitir descargar archivos que no hayan sido subidos a través de Wordpress').'</label><br/>';

        $html .='<p>'.__('¿Qué usuarios suben ficheros a MEGA?').'</p>
            <!--<form action=""> -->
                <input type="radio" name="wpmega_upload" value="'.WPMC_UPLOAD_ONLY_USERS.'"'.
                ( get_option(WPMC_UPLOAD_OPTION) === WPMC_UPLOAD_ONLY_USERS? 'checked':'').'>'.__('Sólo los usuarios').'<br/>
                <input type="radio" name="wpmega_upload" value="'.WPMC_UPLOAD_ONLY_ADMIN.'"'.
                ( get_option(WPMC_UPLOAD_OPTION) === WPMC_UPLOAD_ONLY_ADMIN? ' checked':'').'>'.__('Sólo administradores').'<br/>
                <input type="radio" name="wpmega_upload" value="'.WPMC_UPLOAD_BOTH.'"'.
                (get_option(WPMC_UPLOAD_OPTION) === WPMC_UPLOAD_BOTH? ' checked':'').'>'.__('Ambos').'<br/>
                <input type="radio" name="wpmega_upload" value="'.WPMC_UPLOAD_NOBODY.'"'.
                (get_option(WPMC_UPLOAD_OPTION) === WPMC_UPLOAD_NOBODY? ' checked': '').'>'.__('Ninguno').'
                <p><i>*Ten en cuenta que los ficheros subidos a MEGA se muestran como un enlace de descarga y no pueden visualizarse directamente mediante un navegador web</i></p>
            <!--</form>-->
        </div>';
        return $html;
    }
    
    /*
     * Devuelve el HTML con la sección "disco" y la estructura de los nodos para visualizarlos
     * @return String (HTML)
     */
    private function printNodes(){
        $html = '<div id="tab_disc" class="wraper">';
        if(!isset($this->client)){
            array_push($this->error, __('*Actualmente no se ha establecido conexión con ninguna cuenta de MEGA'));
            array_push($this->error, __('¿No tienes cuenta en MEGA? <a class="wpmega_link_register" href="https://mega.nz/#register">créala de forma gratuita</a>'));
        }else if($this->nodes[0]){
            $previous_node = NULL;
            $html .= '<div id="wpmc_nodes"> <ul id="'.$this->nodes[0]->getId().'" class="root_node">';
            foreach($this->nodes as &$node){
                $previous_html = "";
                $later_html = "";
                if(isset($previous_node) ){
                    $difference = $previous_node->getDeep() - $node->getDeep();                
                    if($previous_node->getType() !== MEGA_ROOT_FOLDER && $difference > 0){
                        for($i=0; $i < $difference; $i++){
                            $previous_html .= '</ul></li>';
                        }
                    }
                    if($previous_node->getType() === MEGA_FOLDER && $difference >= 0){
                        $previous_html .= '</ul></li>';
                    }
                }
                switch ($node->getType()){
                    case MEGA_ROOT_FOLDER:
                        $nodeHtml = '<a target="_blank" class="folder_node" href="https://mega.nz/">Tu disco en la nube:</a>';
                        if(count($this->nodes) > 2 ){
                            $nodeHtml .= '<div class="wpmega_searcher">
                                <input type="text" id="wpmega_searcher_input"/>
                                <input type="submit" id="wpmega_searcher_button" value="Buscar"></input>
                            </div>';
                        }
                        break;
                    case MEGA_FOLDER:
                        if($node->getDeep() > 1){
                            $previous_html .='<li id="'.$node->getId().'" class="wpmc_hide" parent="'.$node->getParent().'">';
                        }else if($node->getDeep() === 1){
                            $previous_html .='<li id="'.$node->getId().'" parent="'.$node->getParent().'">';
                        }else if($node->getDeep() === 0){
                            $previous_html .= '
                                <div class="wpmega_searcher">
                                    <input type="text" id="wpmega_searcher_input"/>
                                    <input type="submit" id="wpmega_searcher_button" value="Buscar"></input>
                                </div>';
                        }else{
                            $previous_html .='<li id="'.$node->getId().'" parent="'.$node->getParent().'">';
                        }
                        $previous_html .='<ul class="wpmc_folder_node">';
                        $nodeHtml = '<a target="_blank" class="folder_node" href="https://mega.nz/#fm/'.$node->getId().'">'.$node->getAttributes()["n"].'</a>';
                        
                        if(get_option(WPMC_REMOVE_MEGA_FILES_OPTION) === "yes"){
                            $nodeHtml.= '<img id="wpmtrash_'.$node->getId().'" class="wpmega_trash" src="'.PLUGIN_URL.'img/trash.png"/>';
                        }else{
                             $nodeHtml.= '<img id="wpmtrash_'.$node->getId().'" class="wpmega_trash hidden" src="'.PLUGIN_URL.'img/trash.png"/>';
                        }
                        
                        $nodeHtml .= '<img src="'.PLUGIN_URL.'img/asc.gif" class="wpmc_folder_lock"/>';
                        break;
                    case MEGA_FILE:
                        if($node->getDeep() > 1){
                            $previous_html .='<li id="'.$node->getId().'" class="wpmc_hide" parent="'.$node->getParent().'">';
                        }else if($node->getDeep() === 1){
                            $previous_html .='<li id="'.$node->getId().'" parent="'.$node->getParent().'">';
                        }
                        $nodeHtml = '<a id="'.$node->getId().'" class="file_node" target="_blank" href="'.get_site_url().'/wp-json/wpmegacloud/nodes?wpmn='.$node->getId().'">'.$node->getAttributes()["n"].'</a>';
                        
                        if(get_option(WPMC_REMOVE_MEGA_FILES_OPTION) === "yes"){
                            $nodeHtml.= '<img id="wpmtrash_'.$node->getId().'" class="wpmega_trash" src="'.PLUGIN_URL.'img/trash.png"/>';
                        }else{
                            $nodeHtml.= '<img id="wpmtrash_'.$node->getId().'" class="wpmega_trash hidden" src="'.PLUGIN_URL.'img/trash.png"/>';
                        }
                        $later_html .= '</li>';
                        break;
                    case MEGA_TRASH_BIN:
                        foreach($this->nodes as &$tmpNode){
                            if($tmpNode->getDeep() === 0){
                                $zeroDeepNode = $tmpNode;
                            }
                        }
                        if(get_option(WPMC_TRASH_BIN_VISIBILITY_OPTION) === "yes"){
                            $previous_html .= '<li id="'.$node->getId().'" parent="'.$zeroDeepNode->getId().'"><ul class="wpmc_folder_node">';
                            $nodeHtml = '<a target="_blank" class="folder_node" href="https://mega.nz/#fm/'.$node->getId().'">Papelera</a>';
                        }
                        break;
                    default :
                        break;
                }
                
                for($i=0; $i < $node->getDeep(); $i++){
                    $previous_html .= "<blockquote>";
                    $later_html = "</blockquote>".$later_html;
                }
                
                $html .= $previous_html.$nodeHtml.$later_html;
                $previous_node = $node;
            }
            
            //Se cierra la papelera o el último nodo que haya
            if(isset($previous_node)){
                for($i=0; $i < $previous_node->getDeep();$i++){
                    $html .= "</ul>";
                }
            }
            //Se cierra .wpmc_nodes
            $html .= "</ul></div>";
        }
        $html .= "</div>";
        return $html;
    }

    /*
     * Envía al usuario un fichero mediante echo.
     * @filename: STRING,
     * @file: STRING
     * */
    private function sendFile(&$filename, &$file){
        header('Content-Description: File Transfer');
        //TODO reemplazar el texto plano!
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename='.$filename.'');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: '.strlen($file));
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');
        header('Pragma: public');
        echo $file;
    }
}

global $wpmegacloud;
$wpmegacloud = new WPMEGALOUD();