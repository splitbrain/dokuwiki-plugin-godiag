<?php
/**
 * file access method.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',dirname(__FILE__).'/../../../');
define('NOSESSION',true);
define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');
require_once(DOKU_INC.'inc/common.php');
require_once(DOKU_INC.'inc/pageutils.php');

// what should be served?
if($_GET['t'] == 'png'){
    $file = getCacheName($_GET['f'],'.godiag.png');
    $mime = 'image/png';
}else{
    $file = getCacheName($_GET['f'],'.godiag.sgf');
    $mime = 'application/sgf';
}

// does it exist?
$fmtime = @filemtime($file);
if(!$fmtime){
    header('HTTP/1.0 404 Not Found');
    echo 'Not found';
    exit;
}

// set headers with a 1 hour expiry
header('Content-Type: '.$mime);
if($_GET['t'] != 'png') header('Content-Disposition: attachment; filename="'.basename($file).'";');
header('Expires: '.gmdate("D, d M Y H:i:s", time()+3600).' GMT');
header('Cache-Control: public, proxy-revalidate, no-transform, max-age=3600');
header('Pragma: public');

//send important headers first, script stops here if '304 Not Modified' response
http_conditionalRequest($fmtime);

// put data
readfile($file);
