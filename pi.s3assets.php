<?php
/*
=====================================================
 s3assets
 by: Josh Jensen
 http://www.joshjensen.com/
 josh@trakdesign.com
 Copyright (c) 2010 Josh Jensen
=============================================================
	THIS IS COPYRIGHTED SOFTWARE
-------------------------------------------------------------
   YOU MAY NOT:
   Reproduce, distribute, or transfer the Software 
   (this plugin), or portions thereof, to any third party. 
   Sell, rent, lease, assign, or sublet the Software or 
   portions thereof. Grant rights to any other person. Use 
   the Software in violation of any U.S. or international 
   law or regulation.
   YOU MAY:
   Alter, modify, or extend the Software for your own use, 
   but you may not resell, redistribute or transfer the 
   modified or derivative version without prior written
   consent from Joshua Jensen. Components from 
   the Software may not be extracted and used in other 
   programs without prior written consent from Joshua Jensen.
-------------------------------------------------------------
	This software is based upon and derived from
	EllisLab ExpressionEngine software protected under
	copyright dated 2004 - 2010. Please see
	www.expressionengine.com/docs/license.html 
=============================================================
	File:			pi.s3assets.php 
-------------------------------------------------------------
	Compatibility:	ExpressionEngine 1.6+ 
-------------------------------------------------------------
	Version:	0.3 
-------------------------------------------------------------
	Purpose:		Asset Management
=============================================================
*/


$plugin_info = array(
   'pi_name' => 's3assets',
	'pi_version' => '0.3',
	'pi_author' => 'Josh Jensen',
	'pi_author_url' => 'http://www.joshjensen.com',
	'pi_description' => 's3assets - Helps manage asset caching and storage on S3',
	'pi_usage' => s3assets::usage()
);

class s3assets {
   var $return_data = "";
   
   function s3assets() {
      global $TMPL, $FNS, $PREFS, $REGX;
      //*******
      // Set Cache Base Location & Default File Locations
      //*******
            
      $cache_base = PATH_CACHE."s3assets_cache";
      
      if (array_key_exists('DOCUMENT_ROOT',$_ENV)) {
		   $pathto_src = $_ENV['DOCUMENT_ROOT']."/";
		} else {
			$pathto_src = $_SERVER['DOCUMENT_ROOT']."/";
		}
		$pathto_src = str_replace("\\", "/", $pathto_src);
		$pathto_src = $FNS->remove_double_slashes($pathto_src);      
      
      $src = ($TMPL->fetch_param('src')) ? $TMPL->fetch_param('src') : $TMPL->tagdata; 
      $src = str_replace(SLASH, "/", $src);
		
		$full_src = $FNS->remove_double_slashes($pathto_src.$src);
		$cache_src = $FNS->remove_double_slashes($cache_base.$src);
		
		//*******
      // Fetch Inital Tag Params
      //*******
      $url_only = $TMPL->fetch_param('url_only');
      $s3bucket = $TMPL->fetch_param('s3bucket');
      $force = $TMPL->fetch_param('force');
      $cname = $TMPL->fetch_param('cname');
      $cnamemax = $TMPL->fetch_param('cnamemax');
      $s3assetsConfig = $PREFS->core_ini['s3assets'];
      $image_s3bucket = $TMPL->fetch_param('image_s3bucket');
		
		if ($TMPL->fetch_param('remote') == "yes") {$cache_src = $src;}
      
      //*******
      // Check to make sure the file is readable and exists.
      //*******
		if(!is_readable($full_src) && $force == ""){	
		   error_log('File '.$full_src.' does not exist');
		   $TMPL->log_item("s3assets.Error: ".$full_src." image is not readable or does not exist");
			return $TMPL->no_results();
		}
		
		//*******
      // Get the last modified time on the file
      //*******			
		$last_modified = @filemtime($full_src);
		$cache_last_modified = @filemtime($cache_src);
		
		
		//*******
      // Check Cache & Set Cache Flag if Needed
      //*******
		$cache_flag = FALSE;
		if (file_exists($cache_src) && $s3bucket != "") {
         if ($last_modified > $cache_last_modified) {
            $cache_flag = TRUE; // I have been modified
         }     
      } else {
         $cache_flag = TRUE; // I dont exist
      }
      
      //*******
      // FOR DEBUGGING
      //*******
      if ($TMPL->fetch_param('debug') == "yes" || $s3assetsConfig['debugS3'] == "y") {
         $cache_flag = TRUE;
         error_log('DEBUG MODE ON - Page: '.getenv('REQUEST_URI').' | Resource: '.$src);
      }
      
      //*******
      // If I am forcing an item I want to strip out stuff that will trip s3
      //*******
      if ($force != "") {$src = str_replace("?", "", $src);}
      
      //*******
      // If there is no s3bucket or we are working locally just send the link along with the correct information.
      //*******      
      if ($s3bucket != "" && $s3assetsConfig['environment'] == "production") {
         if ($cname == "" || $cnamemax == "") {
            $asset_url = 'http://'.$s3bucket.'.s3.amazonaws.com'.$src.'?'.$cache_last_modified;
         } else {
            // Setup CNAME for asset domain spanning.
            $rand_number = rand(0, $cnamemax);
            $cname = str_replace("$1", $rand_number, $cname);
            $asset_url = 'http://'.$cname.$src.'?'.$cache_last_modified;
         }
		} else {
		   $asset_url = $src.'?'.$last_modified;
		}
		
      //*******
      // Set params for use in functions
      //*******
      $params = array(
		   'src' => $src,
		   'pathto_src' => $pathto_src,
		   'full_src' => $full_src,
		   'cache_src' => $cache_src,
		   'last_modified' => $last_modified,
		   'asset_url' => $asset_url,
		   's3bucket' => $s3bucket,
		   'image_s3bucket' => $image_s3bucket,
		   'force' => $force
		);	

      //*******
      // Get Filetype and process accordingly 
      //*******
      if ($url_only != "yes") {         
         $content_type = strtolower(substr(strrchr($src, "."), 1));
         if ($force != "") {$content_type = $force;}
         switch ($content_type) {
            case "css":
               $content_type = "text/css";
               if ($cache_flag) {
                  if ($params['image_s3bucket']) {
                     $params['cssfile_content'] = $this->process_stylesheet($params);
                  } 
                  $this->cache_me($params, $content_type);
               } 
               $stylesheet = $this->stylesheet($params);
               $this->return_data = $stylesheet;
               break;
            case "js":
               $content_type = "text/javascript";
               if ($cache_flag) {$this->cache_me($params, $content_type);} 
               $javascript = $this->javascript($params);
               $this->return_data = $javascript;
               break;
            case "jpg" :
               $content_type = "image/jpeg";
               if ($cache_flag) {$this->cache_me($params, $content_type);} 
               $image = $this->image($params);
               $this->return_data = $image;
               break;
            case "png":
               $content_type = "image/png";
               if ($cache_flag) {$this->cache_me($params, $content_type);} 
               $image = $this->image($params);
               $this->return_data = $image;
               break;
            case "gif":
               $content_type = "image/gif";
               if ($cache_flag) {$this->cache_me($params, $content_type);} 
               $image = $this->image($params);
               $this->return_data = $image;
               break;
         } // End Switch
      } else {
         $this->return_data = $src.'?'.$last_modified;
      }
   }
   
   function cache_me($params, $content_type) {
      global $PREFS, $TMPL; 
         // Set URL constants
         
         if(is_readable($params['full_src']) || strstr($params['full_src'], 'http://') || strstr($params['full_src'], 'https://')){
            $original = file_get_contents($params['full_src']);
   		} else {
   		   $file_url = "http";
   		   if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {$file_url .= "s";}
   		   $file_url .= "://www.".$_SERVER['HTTP_HOST'].$params['src'];
         	file_get_contents($file_url);
   		}
         
         $filename = basename($params['src']);
         
         $cache_structure = pathinfo($params['cache_src']);
         $cache_dirname = $cache_structure['dirname'];
         $cache_basename = $cache_structure['basename'];
         $cache_filename = $cache_structure['filename'];
                           
         // Create Cache Dir if it does not exist
         if(!is_dir($cache_dirname)) {
   			if (!mkdir($cache_dirname,0777,true)) {
   			   error_log("I did not write the cache dir");
   			}
   		}
         
         if (!defined("FILE_PUT_CONTENTS_ATOMIC_TEMP")) {define("FILE_PUT_CONTENTS_ATOMIC_TEMP", $cache_dirname);}
         if (!defined("FILE_PUT_CONTENTS_ATOMIC_MODE")) {define("FILE_PUT_CONTENTS_ATOMIC_MODE", 0777);}
         if (!defined("FILE_PUT_CONTENTS_ATOMIC_OWN")) {define("FILE_PUT_CONTENTS_ATOMIC_OWN", 'deploy');}
      
         $temp = tempnam(FILE_PUT_CONTENTS_ATOMIC_TEMP, 'temp');
         if (!($f = @fopen($temp, 'wb'))) {
            $temp = FILE_PUT_CONTENTS_ATOMIC_TEMP . DIRECTORY_SEPARATOR . uniqid('temp');
               if (!($f = @fopen($temp, 'wb'))) {
                  trigger_error("file_put_contents_atomic() : error writing temporary file '$temp'", E_USER_WARNING);
                  return false;
               }
         }
         
         // Check to see if its a parsed CSS file, if so write the parsed data
         if (!empty($params['cssfile_content'])) {
            fwrite($f, $params['cssfile_content']);  
         } else {
            fwrite($f, $original);
         }
         fclose($f);
                  
         if (!@rename($temp, $params['cache_src'])) {
            @unlink($params['cache_src']);
            @rename($temp, $params['cache_src']);
         }
         //AWS access info - Make sure to add this to your config.php file
         $s3assetsConfig = $PREFS->core_ini['s3assets'];
         
         if (isset($s3assetsConfig['user'])) {
            if (!defined("FILE_PUT_CONTENTS_ATOMIC_OWN")) {define("FILE_PUT_CONTENTS_ATOMIC_OWN", $s3assetsConfig['user']);}
            chown($params['cache_src'], FILE_PUT_CONTENTS_ATOMIC_OWN);
         }
                  
         chmod($params['cache_src'], FILE_PUT_CONTENTS_ATOMIC_MODE);
         
         // Initiate S3 class and upload the file         
         if ($params['s3bucket'] != "") {
            if (!class_exists('S3'))require_once('pi.s3assets/S3.php');
                     
            $awsAccessKey = $s3assetsConfig['awsAccessKey'];
            $awsSecretKey = $s3assetsConfig['awsSecretKey'];
         
            if (!defined('awsAccessKey')) define('awsAccessKey', $awsAccessKey);
            if (!defined('awsSecretKey')) define('awsSecretKey', $awsSecretKey);
         
            $s3 = new S3(awsAccessKey, awsSecretKey, false);
            
            if (isset($params['s3assetname'])) {
               $src = preg_replace("/^\//","",$params['s3assetname']); 
            } else {
               $src = preg_replace("/^\//","",$params['src']); 
            }
            
            S3::putObject(
            S3::inputFile($params['cache_src']),
               $params['s3bucket'],
               $src,
               S3::ACL_PUBLIC_READ,
               array(),
               array(
                  "Content-Type" => $content_type,
                  "Cache-Control" => "max-age=315360000",
                  "Expires" => gmdate("D, d M Y H:i:s T", strtotime("+5 years"))
               )
            );
         
         }
         
   }
   
   function image($params) {
      global $TMPL;
        
      $img_alt = $TMPL->fetch_param('alt');
	   $img_rel = $TMPL->fetch_param('rel');
	   $img_class = $TMPL->fetch_param('class');
	   $img_id = $TMPL->fetch_param('id');
      $img_style = $TMPL->fetch_param('style');
	   $img_width = $TMPL->fetch_param('width');
	   $img_height = $TMPL->fetch_param('height');
      
      $image = '<img src="'.$params['asset_url'].'"';
		$image .= ($img_alt ? ' alt="'.$img_alt.'"' : ' alt=""');
		$image .= ($img_rel ? ' rel="'.$img_rel.'"' : '');
		$image .= ($img_class ? ' class="'.$img_class.'"' : '');
		$image .= ($img_id ? ' id="'.$img_id.'"' : '');
		$image .= ($img_style ? ' style="'.$img_style.'"' : '');
		$image .= ($img_width ? ' width="'.$img_width.'"' : '');
		$image .= ($img_height ? ' height="'.$img_height.'"' : '');
		$image .= " />";
      return $image;
   }
   
   function stylesheet($params) {
      global $TMPL;
      
      $css_media = $TMPL->fetch_param('media');
      
      $stylesheet = '<link rel="stylesheet" href="'.$params['asset_url'].'"';
      $stylesheet .= ($css_media ? " media='$css_media'" : ' media="screen"');
      $stylesheet .= ' type="text/css" />';
      return $stylesheet;
   }
   
   function javascript($params) {
      global $TMPL;
      
      $charset = $TMPL->fetch_param('charset');

      $javascript = '<script type="text/javascript" src="'.$params['asset_url'].'"';
      $javascript .= ($charset ? " charset='$charset'" : ' charset="utf-8"');
      $javascript .= '></script>';
      return $javascript;
   }
      
   function process_stylesheet($params) {
      global $TMPL, $FNS, $PREFS, $REGX;
         $cssfile_content = "";  
            
            if(is_readable($params['full_src'])){
               $lines = $this->read_file($params['full_src']);
      		} else {
      		   $cssfile_url = "http";
      		   if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {$cssfile_url .= "s";}
      		   $cssfile_url .= "://www.".$_SERVER['HTTP_HOST'].$params['src'];
               $lines = $this->read_file($cssfile_url);	
      		}
            
            foreach($lines as $line) {   
               $matches = $this->extract_css_urls($line);
               
               if ( !empty($matches['property']) ) {
                  $cache_base = PATH_CACHE."s3assets_cache";
            
                  $src = str_replace(SLASH, "/", $matches['property'][0]);
            
                  if (array_key_exists('DOCUMENT_ROOT',$_ENV)) {
                     $pathto_src = $_ENV['DOCUMENT_ROOT']."/";
                  } else {
                     $pathto_src = $_SERVER['DOCUMENT_ROOT']."/";
                  }
                  $pathto_src = str_replace("\\", "/", $pathto_src);
                  $pathto_src = $FNS->remove_double_slashes($pathto_src);
            
                  $full_src = $FNS->remove_double_slashes($pathto_src.$src);
                  $cache_src = $FNS->remove_double_slashes($cache_base.$src);
                  
                  $last_modified = @filemtime($full_src);
            		
            		$image_s3bucket = $TMPL->fetch_param('image_s3bucket');
                  
                  $content_type = strtolower(substr(strrchr($src, "."), 1));
                  if (isset($params['force']) &&  $params['force'] != "") {$content_type = $TMPL->fetch_param('force');}
                  switch ($content_type) {
                     case "css":
                        $content_type = "text/css";
                        break;
                     case "js":
                        $content_type = "text/javascript";
                        break;
                     case "jpg" :
                        $content_type = "image/jpeg";
                        break;
                     case "png":
                        $content_type = "image/png";
                        break;
                     case "gif":
                        $content_type = "image/gif";
                        break;
                  } // End Switch
                                 
                  $params = array(
                     'src' => $src,
                     'full_src' => $full_src,
                     'cache_src' => $cache_src,
                     'last_modified' => $last_modified,
                     's3bucket' => $image_s3bucket,
                  );
                  if (strstr($src, 'http')) {
                     $cache_structure = parse_url($src);
                     $cache_dirname = $cache_structure['path'];                     
                     $params['full_src'] = $src;
                     $params['cache_src'] = $cache_base.$cache_dirname;
                     $params['s3assetname'] = $cache_dirname;
                   }
                  $this->cache_me($params, $content_type);
                  
                  $search = '/url\(\s*[\'"]?(([^\\\\\'", \(\)]*(\\\\.)?)+)/';
                  
                  $cname = $TMPL->fetch_param('cname');
                  $cnamemax = $TMPL->fetch_param('cnamemax');
                  
                  if (isset($cache_dirname)) {
                     $src = $cache_dirname;
                     $last_modified = $FNS->remove_double_slashes($cache_base.$cache_dirname);
                     $last_modified = @filemtime($last_modified);
                  }
                  
                  if ($cname == "" || $cnamemax == "") {
                     $replace = 'url(http://'.$image_s3bucket.'.s3.amazonaws.com'.$src.'?'.$last_modified;
                  } else {
                     // Setup CNAME for asset domain spanning.
                     $rand_number = rand(0, $cnamemax);
                     $cname = str_replace("$1", $rand_number, $cname);
                     $replace = 'url(http://'.$cname.$src.'?'.$last_modified;                     
                  }
                  
                  $newLine = preg_replace($search, $replace, $line);                  
               } else {
                  $newLine = $line;
               }         
            $cssfile_content .= $newLine;
            }   
                    
         return $cssfile_content;
   }
   
   function read_file($filename) {
      $fd = fopen($filename, "r");
      while (!feof ($fd))
      {
         $buffer = fgets($fd, 4096);
         $lines[] = $buffer;
      }
      fclose ($fd);
      return $lines;
   }
   
   
   function extract_css_urls($text)
   {
       $urls = array( );

       $url_pattern     = '(([^\\\\\'", \(\)]*(\\\\.)?)+)';
       $urlfunc_pattern = 'url\(\s*[\'"]?' . $url_pattern . '[\'"]?\s*\)';
       $pattern         = '/(' .
            '(@import\s*[\'"]' . $url_pattern     . '[\'"])' .
           '|(@import\s*'      . $urlfunc_pattern . ')'      .
           '|('                . $urlfunc_pattern . ')'      .  ')/iu';
       if ( !preg_match_all( $pattern, $text, $matches ) )
           return $urls;

       foreach ( $matches[3] as $match )
           if ( !empty($match) )
               $urls['import'][] = 
                   preg_replace( '/\\\\(.)/u', '\\1', $match );

       foreach ( $matches[7] as $match )
           if ( !empty($match) )
               $urls['import'][] = 
                   preg_replace( '/\\\\(.)/u', '\\1', $match );

       foreach ( $matches[11] as $match )
           if ( !empty($match) )
               $urls['property'][] = 
                   preg_replace( '/\\\\(.)/u', '\\1', $match );

       return $urls;
   }
    
// -----------------------------------------------
//  Plugin Usage
// This function describes how the plugin is used.
// -----------------------------------------------

function usage() {
   ob_start(); 
?>

The S3assets plugin is meant to help with the static assets we use in the 
front-end development of a site. It also it meant to help with maintaining 
a fresh set of S3 hosted assets with far future headers that will expire when
the file is changed.

To use the Amazon s3 service be sure to setup an s3 account. Once you do, place
these lines in your config.php file. Make sure you replace the text with your keys.

// s3assets
$conf['s3assets'] = array(
  "environment" => "-- development, staging, or production --",
  "awsAccessKey" => "-- Your awsAccessKey Here --",
  "awsSecretKey" => "-- Your awsSecretKey Here --"
);

========================================
Global Parameters
========================================
src=""      This is the relative path to the file that you are trying to access.
            Example: src="_css/global.css"
         
s3bucket=""   This is the name of the s3 s3bucket you would like to have your
            assets placed in and referenced from. This is optional.
            Example: s3bucket_name="asset1.mysite.com"

force=""    If you are using a minify plugin this can be very useful
            Example force="js" -- this will force a javascript file even when you
            are not using a file extension. Just remember this has no way of knowing
            when you updated the file, so you have to delete the cache manually.
            
cname=""    If you have cloudfront and your DNS setup for multiple cnames that reference
            the same bucket you can use this feature for overcoming the browsers open 
            connections limitation. This feature will spread the connections over multiple 
            domains. Simply use "$1" where the number would go. To set this up simeply use
            assets$1.yourdomain.com - then set the cnamemax to the last number of cnames you
            have setup. Like assets1.yourdomain.com, assets2.yourdomain.com, assets3.yourdomain.com.
            The plugin will replace $1 with the number of the server.

cnamemax="" (Required to use cname) As explained above this is the highest number of your asset servers.
            Example: assets1.yourdomain.com, assets2.yourdomain.com, assets3.yourdomain.com.
            cnamemax="3"
            
Example:            
{exp:s3assets 
   s3bucket="your.bucket.com" 
   src="/foo/bar/image.jpg"
   cname="assets$1.yourdomain.com"
   cnamemax="5"
}

========================================
Images
========================================
Example: {exp:s3assets s3bucket="your.bucket.com" src="/_images/smallimg.jpg"}
Renders: <img src="http://bucket.s3bucket.s3.amazonaws.com/_images/smallimg.jpg?1255500429" alt="" />

The image tag supports all of the html options for an image tag.
alt="", rel="", class="", id="", style="", width="", height=""

Example: {exp:s3assets s3bucket="your.bucket.com" src="/_images/smallimg.jpg" alt="{title}" class="example"}
Renders: <img src="http://bucket.s3bucket.s3.amazonaws.com/_images/smallimg.jpg?1255500429" alt="This is my title" class="example" />

========================================
CSS
========================================
Example: {exp:s3assets s3bucket="your.bucket.com" src="/_css/global.css"}
Renders: <link rel="stylesheet" href="http://bucket.s3bucket.s3.amazonaws.com/_css/_global.css?1255500072" media="screen" type="text/css" />

The CSS file extension supports changing the media type by using
media="". If none is set it will default to screen.

Alpha:
This is an alpha feature but if you load a css file with an image s3bucket parameter
the plugin will replace the image urls in your CSS file and upload those images to 
your s3 server.

image_s3bucket="" This will parse the css file and upload all of the images to this bucket.
                  It will then replace all of the url() references with the new S3 location.
                  This currently only works for relative paths to the web root like url(/images/image.jpg)

cname/cnamemax    These work the same as above.

Example: {exp:s3assets 
            s3bucket="your.bucket.com" 
            image_s3bucket="assets.yourdomain.com" 
            src="/_css/_global.css"
         }
With
Cname:   {exp:s3assets 
            s3bucket="your.bucket.com"
            image_s3bucket="assets.yourdomain.com" 
            src="/_css/_global.css"
            cname="assets$1.yourdomain.com"
            cnamemax="5"
         }

========================================
Javascript
========================================
Example: {exp:s3assets s3bucket="your.bucket.com" src="/_js/_global.js"}
Renders: <script type="text/javascript" src="http://bucket.s3bucket.s3.amazonaws.com/_js/_global.js?1255143438" charset="utf-8"></script>

The JS file extension supports changing your character set using
charset="". If left blank it will default to utf-8.


<?php  
   $buffer = ob_get_contents();  
   ob_end_clean();   
   return $buffer;  
   }  
}  

/* End of file pi.s3assets.php */  
/* Location: ./system/plugins/pi.s3assets.php */
