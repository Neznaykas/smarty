<?php

/**
* Smarty Internal Plugin Compiler
* 
* This is the main compiler class. It calls the lexer and parser to
* perform the compiling of a template.
* 
* @package Smarty
* @subpackage Compiler
* @author Uwe Tews 
*/
/**
* Main compiler class
*/
class Smarty_Internal_Compiler extends Smarty_Internal_Base {
    // compile tag objects
    static $_tag_objects = array(); 
    // tag stack
    public $_tag_stack = array(); 
    // current template
    public $template = null; 
    // required plugins
    public $plugins = array();

    /**
    * Initialize compiler
    */
    public function __construct()
    {
        parent::__construct(); 
        // set instance object
        self::instance($this); 
        // get required plugins
        $this->smarty->loadPlugin('Smarty_Internal_Templatelexer');
        $this->smarty->loadPlugin('Smarty_Internal_Templateparser');
        if (isset($this->smarty->autoload_filters['pre']) || isset($this->smarty->autoload_filters['post'])) {
            $this->smarty->loadPlugin('Smarty_Internal_Run_Filter');
            $this->filter = new Smarty_Internal_Run_Filter;
        } 
    } 

    /**
    * Method to deliver instance of compiler object
    * 
    * @param object $ |nothing $new_instance $this of compiler object on initial call
    * @return object instance of compiler object
    */
    public static function &instance($new_instance = null)
    {
        static $instance = null;
        if (isset($new_instance) && is_object($new_instance))
            $instance = $new_instance;
        return $instance;
    } 

    /**
    * Methode to compile a Smarty template
    * 
    * @param  $_template template object to compile
    * @return bool true if compiling succeeded, false if it failed
    */
    public function compileTemplate($_template)
    {
        /* here is where the compiling takes place. Smarty
       tags in the templates are replaces with PHP code,
       then written to compiled files. */ 
        // flag for nochache sections
        $this->_compiler_status->nocache = false;
        $this->_compiler_status->tag_nocache = false; 
        // current template file
        $this->_compiler_status->current_tpl_filepath = ""; 
        // assume successfull compiling
        $this->compile_error = false;
        // save template object in compiler class
        $this->template = $_template; 
        // get template filepath for error messages
        $this->tpl_filepath = $_template->getTemplateFilepath(); 
        // get template source
        if (($_content = $_template->getTemplateSource()) === false) {
            throw new SmartyException("Unable to load template {$this->tpl_filepath}");
        } 
        // template header code
        $template_header = "<?php /* Smarty version " . Smarty::$_version . ", created on " . strftime("%Y-%m-%d %H:%M:%S") . "\n";
        $template_header .= "         compiled from \"" . $this->tpl_filepath . "\" */ ?>\n"; 
        // on empty template just return header
        if ($_content == '') {
            $_template->compiled_template = $template_header;
            return true;
        } 

        $this->_compiler_status->current_tpl_filepath = $this->tpl_filepath; 
        // init cacher plugin
        Smarty_Internal_Template::$cacher_object->initCacher($this); 
        // run prefilter if required
        if (isset($this->smarty->autoload_filters['pre'])) {
            $_content = $this->filter->execute('pre', $_content);
        } 
        // init the lexer/parser to compile the template
        $lex = new Smarty_Internal_Templatelexer($_content);
        $parser = new Smarty_Internal_Templateparser($lex); 
        // get tokens from lexer and parse them
        while ($lex->yylex()) {
            // echo "<br>Parsing  {$lex->token} Token {$lex->value} \n";
            $parser->doParse($lex->token, $lex->value);
        } 
        // finish parsing process
        $parser->doParse(0, 0);

        if (!$this->compile_error) {
            // close cacher and return compiled template
            $_template->compiled_template = $template_header . Smarty_Internal_Template::$cacher_object->closeCacher($this, $parser->retvalue); 
            // run postfilter if required
            if (isset($this->smarty->autoload_filters['post'])) {
                $_template->compiled_template = $this->filter->execute('post', $_template->compiled_template);
            } 
            return true;
        } else {
            // compilation error
            return false;
        } 
    } 

    /**
    * Compile Tag
    * 
    *              This is a call back from the lexer/parser
    *              It executes the required compile plugin for the Smarty tag
    * 
    * @param string $tag tag name
    * @param array $args array with tag attributes
    * @return string compiled code
    */
    public function compileTag($tag, $args)
    { 
        // $args contains the attributes parsed and compiled by the lexer/parser
        // assume that tag does compile into code, but creates no HTML output
        $this->has_code = true;
        $this->has_output = false; 
        // compile the smarty tag (required compile classes to compile the tag are autoloaded)
        if (!($_output = $this->$tag($args, $this)) === false) {
            // did we get compiled code
            if ($this->has_code) {
                // Does it create output?
                if ($this->has_output) {
                    $_output .= "\n";
                } 
                // return compiled code
                return $_output;
            } 
            // tag did not produce compiled code
            return ''; 
            // no compile plugin found for this tag, try function plugin
        } elseif (isset($this->smarty->plugins['function'][$tag])) {
            // test if not cacheable
            if (!$this->smarty->plugins['function'][$tag][1]) {
                $this->_compiler_status->tag_nocache = true;
            } 
            // call function plugin compile module
            return $this->function_plugin($args, $this->smarty->plugins['function'][$tag][0], $this);
        } elseif ($this->smarty->loadPlugin("smarty_function_$tag") && is_callable("smarty_function_$tag")) {
            if (!$this->template->security || $this->smarty->security_handler->isTrustedFunctionPlugin($tag, $this)) {
                // call function plugin compile module
                return $this->function_plugin($args, $tag, $this);
            } 
            // try block plugin
        } elseif (substr_compare($tag, 'close', -5, 5) != 0) {
            if ($this->smarty->loadPlugin("smarty_block_$tag") && is_callable("smarty_block_$tag")) {
                // call block plugin compile module
                return $this->block_plugin($args, $tag, $this);
            } 
        } else {
            if (is_callable("smarty_block_" . substr($tag, 0, -5))) {
                // call block plugin compile module
                return $this->block_plugin($args, $tag, $this);
            } 
        } 
        $this->trigger_template_error ("unknow tag \"" . $tag . "\"");
    } 

    /**
    * lazy loads compile plugin for tag and calls the compile methode
    * 
    * compile objects cached for reuse.
    * class name format:  Smarty_Compile_TagName or Smarty_Internal_Compile_TagName
    * plugin filename format: compile.tagname.php  or internal.compile_tagname.php
    * 
    * @param  $tag string tag name
    * @param  $args array with tag attributes
    * @return string compiled code
    */
    public function __call($name, $args)
    { 
        // build class names to search for
        $ucname = ucfirst($name);
        $classes = array("Smarty_Internal_Compile_{$ucname}", "Smarty_Compile_{$ucname}");

        foreach ($classes as $class_name) {
            // re-use object if already exists
            if (!isset(self::$_tag_objects[$name])) {
                if ($this->smarty->loadPlugin($class_name)) {
                    // use plugin if found
                    self::$_tag_objects[$name] = new $class_name; 
                    // compile this tag
                    if (!$this->template->security || $this->smarty->security_handler->isTrustedCompilerTag($name, $this)) {
                        return call_user_func_array(array(self::$_tag_objects[$name], 'compile'), $args);
                    } 
                } 
            } else {
                // compile this tag
                if (!$this->template->security || $this->smarty->security_handler->isTrustedCompilerTag($name, $this)) {
                    return call_user_func_array(array(self::$_tag_objects[$name], 'compile'), $args);
                } 
            } 
        } 
        // no compile plugin for this tag
        return false;
    } 

    /**
    * display compiler error messages without dying
    * 
    * If parameter $args is empty it is a parser detected syntax error.
    * In this case the parser is called to obtain information about exspected tokens.
    * 
    * If parameter $args contains a string this is used as error message
    * 
    * @todo output exact position of parse error in source line
    * @param  $args string individual error message or null
    */
    public function trigger_template_error($args = null)
    {
        $this->lex = Smarty_Internal_Templatelexer::instance();
        $this->parser = Smarty_Internal_Templateparser::instance(); 
        // get template source line which has error
        $line = $this->lex->line;
        if (isset($args)) {
            $line--;
        } 
        $match = preg_split("/\n/", $this->lex->data);
        echo '<br>Syntax Error on line ' . $line . ' in template "' . $this->tpl_filepath . '"<p style="font-family:courier">' . $match[$line-1] . "<br>"; 
        // to do
        if (false) {
            // find position in this line
            $counter = $this->lex->counter;
            for ($i = 0; $i < $this->lex->line-1; $i++) {
                $counter -= strlen($match[$i]);
            } 
            $counter -= ($this->lex->line-1) * 2;
            echo $counter;
            for ($i = 0; $i < $counter-1;$i++) {
                echo "&nbsp";
            } 
        } 
        echo '</p>';

        if (isset($args)) {
            // individual error message
            echo $args;
        } else {
            // exspected token from parser
            foreach ($this->parser->yy_get_expected_tokens($yymajor) as $token) {
                $exp_token = $this->parser->yyTokenName[$token];
                if (isset($this->lex->smarty_token_names[$exp_token])) {
                    // token type from lexer
                    $expect[] = '"' . $this->lex->smarty_token_names[$exp_token] . '"';
                } else {
                    // otherwise internal token name
                    $expect[] = $this->parser->yyTokenName[$token];
                } 
            } 
            // output parser error message
            echo 'Unexpected "' . $this->lex->value . '", expected one of: ' . implode(' , ', $expect);
        } 
        echo "<br>"; 
        // set error flag
        $this->compile_error = true;
    } 
} 

?>
