<?php
/*
Plugin Name: NS MatPress
Plugin URI: http://nakamuraseminars.com/matpress
Description: Execute matlab/octave scripts from WordPress. Embed input forms and output results.
Version: 0.9
Author: Nakamura Seminars
Author URI: http://nakamuraseminars.com
*/

global $wp_version;
if ( version_compare($wp_version,"3.5","<") ) {
  exit ( 'NS MatPress requires WordPress 3.5 or newer. Please update '
       . '<a href="http://codex.wordpress.org/Upgrading_WordPress">to latest WordPress version</a>!');
}

//wp_localize_script('nsmatpress', 'NSMatPress',array('url' => plugin_dir_url(__FILE__), 'nonce' => $nonce));

class ns_matpress {
   
  var $workingdir;
  var $filetypes = "m,csv";
  var $dbgmsg = "";
  var $nslink = "http://nakamuraseminars.org/testcalc/";
  var $nslogo = "http://nakamuraseminars.org/testcalc/wp-content/themes/nstheme/images/headers/nslogo.jpg";

  function __construct() {
    $this->set_variables();
    if( is_admin() ) {
	add_action('admin_menu', array($this, 'add_plugin_page'));
	add_action('admin_init', array($this, 'page_init'));
    }
    add_filter('the_content', array($this,'thecontents') );
    register_activation_hook(__FILE__,  array($this,"set_options") );
    register_deactivation_hook(__FILE__, array($this,"unset_options") );

    // Load the css and localised scripts as head action
    add_action('wp_head', array($this,'headaction') );
    add_action('wp_head', array($this,'scripts_action') );

    // Added to extend allowed files types in Media upload
    add_filter('upload_mimes', array($this,'custom_upload_mimes') );

    // If both logged in and not logged in users can send this AJAX request,
    add_action( 'wp_ajax_nopriv_myajax-submit', array($this,'myajax_submit') );
    add_action( 'wp_ajax_myajax-submit', array($this,'myajax_submit') );
  }

function scripts_action() {
    //wp_enqueue_script('jquery');
    // embed the javascript file that makes the AJAX request
    wp_enqueue_script( 'my-ajax-request', plugin_dir_url( __FILE__ ) . 'ns-matpress.js', array( 'jquery' ) ); 

    // declare the URL to the file that handles the AJAX request (wp-admin/admin-ajax.php)
    wp_localize_script( 'my-ajax-request', 'MyAjax', array( 
	'ajaxurl' => get_admin_url().'admin-ajax.php', 'nonce' => wp_create_nonce('ns-matpress-request-nonce') ) 
    );
  }  

  function standardsplit($content, &$var, &$val) {
    $rst = explode("|", $content, 2);
    $tmp = explode(":", $rst[0], 2);
    $var = $tmp[0];
    $val = $tmp[1];
    return $rst[1];
  }

  function resultfield($content, $mpobj) {
    global $post;
    $pageID = $post->ID; 
    $this->standardsplit($content, $var, $val);
    $txt = $mpobj[result][$var]; //.$mpobj[errmsg];
    $mpobj[ajax] = $txt;  
    //if( $val == "field" ) { 
    //  $txt = '<input type="text" name="'.$var.'" id="'.$var.'" value="'.$txt.'" disabled="disabled"/>';
    //}
    //else{
      $txt = '<span id="ns-matpress-'.$pageID.'-'.$var.'" class="nsmpoutput" >'.$txt.'</span>';
    //}
    return $txt;
  }

  function statusfield($content, $mpobj) {
    global $post;
    $pageID = $post->ID;
    $text = $mpobj[errmsg];
    $temp = '<span id="ns-matpress-'.$pageID.'-__status" class="nsmpoutput" >'.$text.'</span>';
    return $temp;
  }

  function buttontype($content, $mpobj) {
    global $post;
    $pageID = $post->ID;
    $this->standardsplit($content, $var, $val);
    //if submit through ajax mode
      $temp .= '<input type="button" id="ns-matpress-'.$pageID.'" value="Request" class="nsmpsubmit" />';
    //else
    //  $temp .= '<input type="submit" name="submit" value="Submit" />';
    //endif
    $temp .= '<input type="hidden" name="submitted" id="submitted" value="true" class="nsmphidden" />';
    $temp .= '<input type="hidden" name="pageID" id="pageID" value="'.$pageID.'" class="nsmphidden" />';
    $temp .= '<input type="hidden" name="postType" id="postType" value="'.$post->post_type.'" class="nsmphidden" />';
    return $temp;
  }

  function myajax_submit() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'ns-matpress-request-nonce' ) ) die ( 'Busted!');

    $id = $_POST['pageID'];
    $type = $_POST['postType'];
    if($type == "page") {
      $item = get_page($id);
    }   
    else {
      $item = get_post($id);
    }  
    $text = $item->post_content;

    if( $this->extract($text, $mpobj) > 0 ) {
      $this->validate($mpobj); 
    }

    if( trim( $mpobj[errmsg] ) == '' ) {
      $temp = $mpobj[result];
      $temp['__status'] = 'OK';
      $temp['__success'] = true;
    }
    else {
      foreach( $mpobj[errors] as $var => $err ) $temp['_'.$var] = $mpobj[errors][$var];
      $temp['__status'] = $mpobj[errmsg];
      $temp['__success'] = false;
    }
    $temp['__id'] = $id;

    $text = json_encode( $temp ); 
    echo $text;
    exit;
  }
  
  function set_variables() {
    $upload_dir = wp_upload_dir();
    $this->workingdir = trailingslashit($upload_dir[basedir]).dirname(plugin_basename(__FILE__));
  }

  function glob_filetypes( $path ) {
    return glob( trailingslashit($path)."*.{".$this->filetypes."}", GLOB_BRACE );
  }

  function relink_uploaded() {
    $custom = get_option("ns_matpress_content");
    if( trim($custom) == "") return "";
    $list = array_filter(array_unique(array_map("trim",explode(",",$custom))));
    $custom = implode(",", $list);
    if( trim($custom) == "") return;
    $files = $this->get_attachments();
    foreach( $files as $id => $title ) {
      $path = get_attached_file($id);
      $extn = $this->get_extension($path);
      $file = $title.".".$extn;
      $link = trailingslashit($this->workingdir).$file;
      $pattern = "/\b".preg_quote($file)."\b/"; 
      if( preg_match($pattern, $custom) ) { 
        symlink($path,  $link);
        if( ++$temp[$file] > 1 ) $errmsg .= "<br/>Possible duplicate in uploads: ".basename($file);
      }
    }
    foreach( $list as $item ) {
      if( $temp[$item] < 1 ) $test[] = $item;
    }
    if( count($test) > 0 ) $errmsg .= "<br/>Custom script not found in uploads: ".implode(",", $test);
    return $errmsg;
  }

  function unlink_uploaded() {
    $files = $this->glob_filetypes( $this->workingdir );
    foreach($files as $link) {      
      if( is_link($link) ) unlink( $link );
    }
  }

  function set_options() {
    if( !is_dir($this->workingdir) ) mkdir( $this->workingdir ); 
    if( !is_dir($this->workingdir) ) exit( "Disk access denied! Can not activate plug-in." );
    $this->relink_uploaded();
    add_option("ns_matpress_command", "octave --silent --eval ");
    add_option("ns_matpress_library", "/big/dom/xnakamuraseminars/nakamura/dev/bin/");
    add_option("ns_matpress_content", "BS,simplefit,blackscholes");
    add_option("ns_matpress_include", "sin,cos,sqrt,exp,factorial,normcdf,norminv");
  }

  function unset_options() {
    rmdir( $this->workingdir );
    $this->unlink_uploaded();
    delete_option("ns_matpress_command");
    delete_option("ns_matpress_library");
    delete_option("ns_matpress_content");
    delete_option("ns_matpress_include");
  }

  function headaction() {
    echo '<link rel="stylesheet" href="'.plugins_url('ns-matpress.css',__FILE__).'" type="text/css" />';
  }

  function custom_upload_mimes ( $existing_mimes=array() ) {
    // Add filetypes to Media upload (text only allowed)
    foreach( explode(",", $this->filetypes) as $item) 
      $existing_mimes[$item] = 'text/plain';
    return $existing_mimes;
  }

  // this function starts the transform the MatPress short code 

  function thecontents($text) {
    if( $this->extract($text, $mpobj) > 0 ) {
	$this->validate($mpobj); 
	$this->transform($text, $mpobj);
        if( $_REQUEST['content-only'] == 1 ) $text .= $this->includescripts();
    }
    return $text;
  }

  function includescripts() {
    $tmp = '<script type="text/javascript"'
//         . 'src="http://nakamuraseminars.org/testcalc/wp-includes/js/jquery/jquery.js?ver=1.8.3"></script>'
         . 'src="' . includes_url() . '/js/jquery/jquery.js"></script>'
         . '<script type="text/javascript" '
         . 'src="' . plugin_dir_url( __FILE__ ) . 'ns-matpress.js"></script>'
         . '<link href="' . plugin_dir_url( __FILE__ ) . 'ns-matpress.css" type="text/css" rel="stylesheet" />'
         . '<script type="text/javascript">'
         . 'var MyAjax = ' 
         . json_encode( array('ajaxurl' => get_admin_url().'admin-ajax.php', 
			        'nonce' => wp_create_nonce('ns-matpress-request-nonce') ) )
         . '</script>';
    return $tmp;
  }

  // these are utility function used in the transform

function checkoptions($mpobj_cmd, $mpobj_bin, $mpobj_mat) {
  $errmsg = '';
  if( !preg_match('/^(matlab)|(octave)/', trim($mpobj_cmd)) ) $errmsg .= "Non-valid command. ";
  $pattern = '/(matlab)|(octave)|(--silent)|(--eval)/';
  $text = preg_replace($pattern, "", $mpobj_cmd, -1, $count); 
  if($count != 3 || trim($text) != '') $errmsg .= "Non-valid arguments. ";
  if( !is_dir($mpobj_bin) ) $errmsg .= "Non-valid binary directory. ";
  if( !file_exists($mpobj_bin."octave") && !file_exists($mpobj_bin."matlab") ) $errmsg .= "No binary. ";   
  //if( !is_dir($mpobj_mat) ) $errmsg .= "Non-valid content directory. ";
  return $errmsg;   
}

function extract(&$text, &$mpobj) {
  $pattern = '/\[mat:([a-z]+)\[([^]]*)\]mat\]/';
  $i = 0;
  $done = "";
  while( preg_match($pattern, $text, $matches) > 0 && $i < 500) { // 
    $command = $matches[1];
    $content = $matches[2];
    $temp = explode($matches[0], $text, 2);
    $replace = "";
    $text = $temp[1];
    switch ($command) {
      case "remote": //XX this is just for debugging matpress server - should be removed
        $mpobj[remote] = $content;
        break;
      case "server": //XX this is just for debugging matpress server - should be removed
        $mpobj[server] = "XX".$content;
        break;
      case "simple":
        $replace = $replace."<br/> Result: [mat:result[ans]mat] <br/> Status: [mat:status[err]mat]";
      case "octave":
      case "script":
      case "matlab":
        $replace = "[mat:button[submit]mat]".$replace;
        //$mpobj[rawscript] = $content;
        $replace = $this->getsimple($content, $mpobj).$replace; 
        $mpobj[script] = $content;
        break;
      case "input":
      case "select":
        $tmp = explode("|", $content, 2);
        $var = explode(":", $tmp[0], 2); 
        $mpobj[inputs][trim($var[0])] = $tmp[1];
        $replace = $matches[0];
        break; 
      default:
        $replace = $matches[0];
    }
    $done = $done.$temp[0].$replace;
    $i = $i + 1;
  }
  if( $i >0 ) $text = $done.$temp[1];
  return $i;
}

function validate(&$mpobj) {
  if( isset($_POST['script']) ) { // XX this step is added between extract and validate in matpress server
    $mpobj[script] = $_POST['script'];
  }
  if( isset($_POST['mpobj']) ) { // XX this step is added between extract and validate in matpress server
    $mpobj = json_decode($_POST['mpobj']);
  }

  // script validation
  $mpobj[errmsg] = $this->checkscript($mpobj[script]);
  if( !(isset($_POST['submitted']) && trim($mpobj[errmsg]) == '') ) 
    return;
  
  // input validation
  $runscript = "";
  foreach ($mpobj[inputs] as $var => $type) {
    $thiserr = $this->checktype($_POST[$var], $type);
    if(strlen($thiserr) > 0) $inerr[$var] = $thiserr;
    $runscript .= "$var=".$_POST[$var]."; ";
    $mpobj[errmsg] .= $thiserr;
  }  
  $mpobj[errors] = $inerr; 
  if( count($inerr) > 0 ) 
    return; 
  
  // options validation  
  $mpobj_cmd = get_option("ns_matpress_command");
  $mpobj_bin = get_option("ns_matpress_library");
  $mpobj_dir = $this->workingdir;
  $mpobj[errmsg] .= $this->checkoptions($mpobj_cmd, $mpobj_bin, $mpobj_dir); // check if options are valid 
  if( trim($mpobj[errmsg]) != '' ) 
    return;

  // all clear - execute
  if( $mpobj[remote] != '' ) { 
    $url = $mpobj[remote];
    $mpobj[remote] = ''; //XX this is not so clean
    $postbody = $_POST;
    $postbody[mpobj] = json_encode( $mpobj ); 
    $response = wp_remote_post( $url, array(
	'method' => 'POST',
	'body' => $postbody
      )
    );
    if( is_wp_error( $response ) ) {
      $error_message = $response->get_error_message();
      $mpobj[errmsg] .= "MatPress: $error_message";
    } 
    else {
      $mpobj = json_decode( $response['body'] ); //"ans = All is fine.";
      $mpobj[errmsg] .= $response['body'];
    }
  }
  else { //if local-mode
    $runscript = $runscript."cd ".$mpobj_dir."; ".$mpobj[script].", ans";
    $outputs = shell_exec($mpobj_bin.$mpobj_cmd."'".$runscript."'");
    //$outputs = "ans = ".$mpobj_bin.$mpobj_cmd."'".$runscript."'";
    // collect results
    foreach( explode("\n", $outputs) as $output) {
      $answer = explode ( '=', $output); 
      if(count($answer) == 2) $mpobj[result][trim($answer[0])] = trim($answer[1]);
    } 
    $mpobj[errmsg] .= $mpobj[result][err];
  }
   
  if( isset($_POST['mpobj']) ) { //XX this is mpserver event only - take outside
    $text = json_encode( $mpobj ); 
    ob_end_clean();
    echo $text."JKJKJKJKJK";
    exit;
  }
}

function get_extension( $file ) {
  return array_pop( explode(".", basename($file)) );
}

function get_attachments() {
  $args = array(
    'post_type' => 'attachment',
    'numberposts' => -1,
    'post_status' => null,
    'post_parent' => null, // any parent
    'post_mime_type' => "text/plain",
  ); 
  $attachments = get_posts($args);
  $pattern = "/(".implode("|", explode(",", $this->filetypes) ).")/";
  if ($attachments) {
    foreach ($attachments as $post) {
      $path = get_attached_file($post->ID);
      $extn = $this->get_extension($path); 
      if( preg_match($pattern, $extn) ) $files[$post->ID] = $post->post_title; 
    }
  }
  return $files;
}

function link2ns($content) {
  switch ($content) {
    case "large":
	$text = '<div><img src="'.$this->nslogo.'" height="40" width="40" alt="Link to Nakamura Seminars" />'
	      . '<span > <a href="'.$this->nslink.'">'
              . "This calculation is powered by Nakamura Seminars' MatPress Plug-In</a></span></div>";
        break;
    case "small":
	$text = '<img src="'.$this->nslogo.'" height="20" width="20" alt="Link to Nakamura Seminars" />'
              . ' <a href="'.$this->nslink.'">'."Powered by NS MatPress</a>";
        break;
    default:
	$text = '<a href="'.$this->nslink.'">'.$content.'</a>';
  }
  return $text;
}

function debugmsg($content) {
  $dbgmsg = $this->dbgmsg;
  $files = $this->get_attachments();
  if ($files) {
    foreach ($files as $id => $title) {
      $dbgmsg .= $id."<br/>"; 
      $dbgmsg .= $title."<br/>"; 
      $dbgmsg .= get_attached_file($id)."<br/>";
    }
  }
  return "Hgjhghgjghj".$dbgmsg;
}

function transform(&$text, &$mpobj) {
  global $post;
  $pageID = $post->ID;
  $pattern = '/\[mat:([a-z]+)\[([^]]*)\]mat\]/';
  $i = 0;
  $done = "";
  while( preg_match($pattern, $text, $matches) > 0 && $i < 500) { // 
    $command = $matches[1];
    $content = $matches[2];
    $temp = explode($matches[0], $text, 2);
    $replace = "";
    $text = $temp[1];
    switch ($command) {
      case "link":
	$replace = $this->link2ns($content);
        break;
      case "debug":
	$replace = $this->debugmsg($content);
        break;
      case "result":
        $replace = $this->resultfield($content, $mpobj);
        break;
      case "status":
        $replace = $this->statusfield($content, $mpobj);
        break;
      case "input":
        $replace = $this->inputfield($content, $mpobj);
        break;
      case "select":
        $replace = $this->selectfield($content, $mpobj);
        break;
      case "button":
        $replace = $this->buttontype($content, $mpobj);
        break;
      default:
        $replace = $matches[0];
    }
    $done = $done.$temp[0].$replace;
    $i = $i + 1;
  }
  //if( $i>0 ) $text = '<form id="dummy-form" method="post">'.$done.$temp[1].'</form>';
  if( $i>0 ) $text = '<form id="ns-matpress-'.$pageID.'-__form" method="post">'.$done.$temp[1].'</form>';
  return $i; 
}

function checkscript($text) {
  $include = get_option("ns_matpress_include");
  $custom = get_option("ns_matpress_content");
  $custom = preg_replace("/\.m\b/", "", $custom).",".$include;
  $list = array_filter(array_unique(array_map("trim",explode(",",$custom))));
  if( count($list) > 0 ) {
    $include = implode(",", $list);
    $pattern = implode( "\b)|(\b" , explode( ",", $include ) );
    $pattern = "/(\b".$pattern."\b)/";
    $text = preg_replace($pattern, "", " ".$text." ");
  }
  $pattern = '/(\$[A-Za-z][A-Za-z0-9]*\b)|(\b[A-Z][A-Za-z0-9]*\b)/';
  $text = preg_replace($pattern, "", $text);
  if( preg_match('/[a-z][a-z]+/', $text, $matches) )
    $errmsg = "'".$matches[0]."' is not a valid function or variable name.";
  return $errmsg;
}

function checktype($value, $validations) {
  $errmsg = "";
  foreach(explode("|", $validations) as $validation) {
    $val = explode(":", $validation, 2);
    switch ($val[0]) {
      case "any":
        break;
      case "int":
        if( filter_var($value, FILTER_VALIDATE_INT) == FALSE ) $errmsg .= " Integer only.";
        break;
      case "lte":
        if( $value > $val[1] ) $errmsg .= " Input <= ".$val[1]." only.";
        break;
      case "gte":
        if( $value < $val[1] ) $errmsg .= " Input => ".$val[1]." only.";
        break;
      case "lt":
        if( $value >= $val[1] ) $errmsg .= " Input < ".$val[1]." only.";
        break;
      case "gt":
        if( $value <= $val[1] ) $errmsg .= " Input > ".$val[1]." only.";
        break;
      case "opt":
        $tmp = "Only choose from listed options."; 
        foreach(explode(",", $val[1]) as $item) {
          $opt = explode("=", $item, 2);
          if($opt[0] == $value) $tmp = ''; 
        }
        $errmsg .= $tmp;
        break;
      case "num":
      default:
        if( !is_numeric($value) ) $errmsg .= " Numeric only.";
        break;     
    }
  }
  return $errmsg;
}

function inputfield($content, $mpobj) {
  global $post;
  $pageID = $post->ID; 
  $tmp = explode("|", $content, 2);
  $tmp = explode(":", $tmp[0], 2);
  $var = $tmp[0];
  $val = $tmp[1];
  if( isset($_POST[$var]) ) $val = $_POST[$var]; 
  if( isset($mpobj[errors][$var]) ) $err = $mpobj[errors][$var];
  $txt = '<input type="text" name="'.$var.'" id="'.$var.'" value="'.$val.'" autocomplete="off"/>'
       . '<span id="ns-matpress-'.$pageID.'-_'.$var.'" class="nsmpoutput" >'.$err.'</span>';
  return $txt;
}

function selectfield($content, $mpobj) {
  $tmp = explode("|", $content, 2);
  $var = explode(":", $tmp[0], 2);
  $val = $var[1];
  $var = $var[0];
  $tmp = explode(":", $tmp[1], 2);
  if( isset($_POST[$var]) ) $val = trim($_POST[$var]); 
  if( isset($mpobj[errors][$var]) ) $err = '<span class="error">'.'</span>'.$mpobj[errors][$var];
  foreach(explode(",", $tmp[1]) as $item) {
    $opt = explode("=", $item, 2);
    if( count($opt) == 1 ) $opt[1] = $opt[0];
    if( $val == $opt[0] || ($i++ == 0  && $val == '') )   
      $txt .= "<option value='$opt[0]' selected>$opt[1]</option>";
    else
      $txt .= "<option value='$opt[0]'>$opt[1]</option>";
    '<input type="text" name="'.$var.'" id="'.$var.'" value="'.$val.'" />';
  } 
  return "<select name='$var'>".$txt."</select>".$err;
}

function getsimple(&$text, &$mpobj) {
  $pattern = '/\$([A-Za-z][A-Za-z0-9]*)/';
  $i = 0;
  while( preg_match($pattern, $text, $matches) > 0 && $i < 500) { //
    $i += 1;
    $var = trim($matches[1]); 
    $mpobj[inputs][$var] = "num";
    $text = preg_replace($pattern, $var, $text, 1);
    $temp .= $var.": [mat:input[$var|num]mat]<br/>"; 
  } 
  return $temp;
}

  // Create custom plugin settings menu
	
  public function add_plugin_page(){
    // This page will be under "Settings"
    add_options_page('Settings Admin', 'MatPress', 'manage_options', 
	'matpress-setting-admin', array($this, 'create_admin_page')
    );
  }

  public function create_admin_page(){
    ?>
    <div class="wrap">
	<?php screen_icon(); ?>
	<h2>Settings</h2>			
	<form method="post" action="options.php">
	  <?php
          // This prints out all hidden setting fields
	  settings_fields('matpress_option_group');	
          do_settings_sections('matpress-setting-admin');
	  ?>
	  <?php submit_button(); ?>
	</form>
    </div>
    <?php
  }
	
  public function page_init() {		
    register_setting('matpress_option_group', 'array_key', array($this, 'check_ID'));		
    add_settings_section(
	'setting_section_id',
	'Setting',
	array($this, 'print_section_info'),
	'matpress-setting-admin'
    );	
    add_settings_field(
	'MatPress_cmd_id', 
	'Environment', 
	array($this, 'create_an_id_field'), 
	'matpress-setting-admin',
	'setting_section_id'			
    );		
  }
	
  public function check_ID($input) {
    $cmderr = $this->checkoptions(
	$input['MatPress_cmd_id'], $input['MatPress_lib_id'], $input['MatPress_mat_id']);  
    if( trim($cmderr) != '' ) { // check if options are valid
	$message = __( "Input error: ".$cmderr, 'my-text-domain' );
        add_settings_error('myUniqueIdentifyer', esc_attr( 'settings_updated' ), $message, "error");
        return $cmderr;  
    }
    $mid = $input['MatPress_cmd_id'];			
    if(get_option('ns_matpress_command') === FALSE){
	add_option('ns_matpress_command', $mid);
    } else {
	update_option('ns_matpress_command', $mid);
    }
    $mid = $input['MatPress_lib_id'];			
    if(get_option('ns_matpress_library') === FALSE){
	add_option('ns_matpress_library', $mid);
    } else {
	update_option('ns_matpress_library', $mid);
    }
    $mid = $input['MatPress_mat_id'];			
    if(get_option('ns_matpress_content') === FALSE){
	add_option('ns_matpress_content', $mid);
    } else {
	update_option('ns_matpress_content', $mid);
    }	
    $mid = $input['MatPress_fun_id'];			
    if(get_option('ns_matpress_include') === FALSE){
	add_option('ns_matpress_include', $mid);
    } else {
	update_option('ns_matpress_include', $mid);
    }	
    $this->unlink_uploaded();
    $cmderr = $this->relink_uploaded();
    if( strlen($cmderr) > 0 ) {
      $message = __( "Warning: ".$cmderr, 'my-text-domain' );
      add_settings_error('myUniqueIdentifyer', esc_attr( 'settings_updated' ), $message, "error");
    }
    return $mid;
  }
	
  public function print_section_info(){
    print 'Use this to make MatPress find your matlab/octave environment. Be careful when altering these.';
    $errmsg = $this->checkoptions(
	get_option('ns_matpress_command'), 
	get_option('ns_matpress_library'),
 	get_option('ns_matpress_content')
    );
    if( trim($errmsg) != '') print "<br/><br/><b>Warning!<br/><br/>Setting error: ".$errmsg."</b>";
  }
	
  public function create_an_id_field(){
    ?>
    Execute command: <br/><input type="text" id="input_whatever_unique_id_I_want1" 
    	name="array_key[MatPress_cmd_id]" size="50" 
	value="<?=get_option('ns_matpress_command');?>" /> <br/> <br/>
    Binary location: <br/><input type="text" id="input_whatever_unique_id_I_want2" 
        name="array_key[MatPress_lib_id]" size="50" 
	value="<?=get_option('ns_matpress_library');?>" /> <br/> <br/>
    Custom functions: <br/><input type="text" id="input_whatever_unique_id_I_want3" 
        name="array_key[MatPress_mat_id]" size="50" 
	value="<?=get_option('ns_matpress_content');?>" /> <br/> <br/>
    Native functions: <br/><input type="text" id="input_whatever_unique_id_I_want4" 
        name="array_key[MatPress_fun_id]" size="50" 
	value="<?=get_option('ns_matpress_include');?>" />
    <?php
  }

  //

  

}

$ns_matpress = new ns_matpress();