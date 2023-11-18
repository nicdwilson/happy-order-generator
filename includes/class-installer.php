<?php
/**
 * 
 */

namespace Happy_Order_Generator;

 if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

 class Installer {
 
 
     /**
      * Install The instance of Install
      *
      * @var    object
      * @access  private
      * @since    1.0.0
      */
     private static object $instance;
 
     /**
      * Main Installer Instance
      *
      * Ensures only one instance of Install is loaded or can be loaded.
      *
      * @return Installer instance
      * @since 1.0.0
      * @static
      */
     public static function instance(): object {
         if ( empty( self::$instance ) ) {
             self::$instance = new self();
         }
 
         return self::$instance;
     }
 
     public function __construct(){
 
     }

	 //todo switch to uninstall
	 public static function deactivate_plugin():void{
	 }

     public static function activate_plugin():void{
    }
}