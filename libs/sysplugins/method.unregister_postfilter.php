<?php

/**
* Smarty method Unregister_Postfilter
* 
* Unregister a postfilter
* 
* @package Smarty
* @subpackage SmartyMethod
* @author Uwe Tews 
*/

/**
* Smarty class Unregister_Postfilter
* 
* Unregister a postfilter
*/

class Smarty_Method_Unregister_Postfilter extends Smarty_Internal_Base {
    /**
    * Unregisters a postfilter function
    * 
    * @param callback $function 
    */
    public function execute($function)
    {
        unset($this->smarty->plugins['postfilter'][$function]);
    } 
} 

?>
