<?php
/**
 * Go diagrams for Dokuwiki
 *
 * This was ported from the GoDiag MediWiki extension which itself uses code
 * and input syntax from from Sensei's Library
 *
 * @author     Andreas Gohr <andi@splitbrain.org>
 *
 * @link       http://meta.wikimedia.org/wiki/Go_diagrams
 * @author     Stanislav Traykov
 *
 * @link       http://senseis.xmp.net/files/sltxt2png.php.txt published
 * @author     Arno Hollosi <ahollosi@xmp.net>
 * @author     Morten Pahle <morten@pahle.org.uk>
 */

if(!defined('DOKU_INC')) die();
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * The godiag plugin class
 */
class syntax_plugin_godiag extends DokuWiki_Syntax_Plugin {

    var $dgm;       // holds diagram data
    var $seqno = 1; // if the same diagram is included more than once on a page,
                    // we need this to construct a unique image map id

    /**
     * Map syntax keywords to internal draw functions
     */
    var $functab = array(
            ',' => 'draw_hoshi',
            'O' => 'draw_white',
            'X' => 'draw_black',
            'C' => 'draw_circle',
            'S' => 'draw_square',
            'B' => 'draw_black_circle',
            'W' => 'draw_white_circle',
            '#' => 'draw_black_square',
            '@' => 'draw_white_square',
            '_' => 'draw_wipe');

    /**
     * Style settings for the generated diagram
     */
    var $style = array(
            'board_max'         => 40,  // max size of go board
            'sgf_comment'       => 'GoDiag Plugin for DokuWiki',
            'sgf_link_txt'      => 'SGF',
            'ttfont'            => 'Vera.ttf',            // true type font
            'ttfont_sz'         => 10,  // font size in px (roughly half of line_sp)
            'line_sp'           => 22,  // spacing between two lines
            'edge_sp'           => 14,  // spacing on edge of board
            'line_begin'        => 4,   // line begin (if not edge)
            'coord_sp'          => 20,  // spacing for coordinates (2 * font_sz or more)
            'stone_radius'      => 11,  // (line_sp / 2 works nice)
            'mark_radius'       => 5,   // radius of circle mark (about half of stone radius)
            'mark_sqheight'     => 8,   // height of square mark (somewhat less than stone radius)
            'link_sqheight'     => 21,  // height of link highlight (line_sp - 1)
            'hoshi_radius'      => 3,                       // star point radius
            'goban_acolor'      => array (242, 180, 101),   // background color in RGB
            'black_acolor'      => array(0, 0, 0),
            'white_acolor'      => array(255, 255, 255),
            'white_rim_acolor'  => array(70, 70, 70),
            'mark_acolor'       => array(244, 0, 0),
            'link_acolor_alpha' => array(10, 50, 255, 96),  // R G B alpha (=transparency)
            'line_acolor'       => array(0, 0, 0),
            'string_acolor'     => array(0, 0, 0));

    /**
     * Regular expression to parse hints for creating SGF files
     */
    var $hintre = '/(\d|10) (?:at|on) (\d)/';

    /**
     * Constructor. Initializes the diagram styles and localization
     */
    function syntax_plugin_godiag() {
        //set correct path to font file
        $this->style['ttfont'] = dirname(__FILE__).'/Vera.ttf';
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What HTML type are we?
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 160;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<go>.*?</go>',$mode,'plugin_godiag');
    }


    /**
     * Create output
     */
    function render($mode, Doku_Renderer $renderer, $data) {
        if($mode != 'xhtml') return false;

        // check for errors first
        if($data['error']){
            $renderer->doc .= '<div class="error">Go Diagram plugin error: '.$data['error'].'</div>';
            return;
        }


        // create proper image map and attribute for IMG tag
        $map_id = 'godiag__' . $this->seqno++ . $md5hash_png;
        if($data['imap_html']) {
            $data['imap_html'] = "<map id=\"$map_id\" name=\"$map_id\">" . $data['imap_html'] . '</map>';
            $godiag_imap_imgs = "usemap=\"#$map_id\"";
        } else {
            $godiag_imap_imgs = '';
        }

        // now we have the HTML to be returned if everything went OK FIXME
        $sgf_href = DOKU_BASE.'lib/plugins/godiag/fetch.php?f='.$data['md5hash_sgf'].'&amp;t=sgf';
        $png_href = DOKU_BASE.'lib/plugins/godiag/fetch.php?f='.$data['md5hash_png'].'&amp;t=png';

        $renderer->doc .= '<div class="godiag-' . $data['divclass'] . '">';
        $renderer->doc .= $data['imap_html'];
        $renderer->doc .= '<div class="godiagi" style="width:'.$data['width'].'px;">';
        $renderer->doc .= '<img class="godiagimg" src="'.$png_href.'" alt="go diagram" '.$godiag_imap_imgs.'/>';
        $renderer->doc .= '<div class="godiagheading">';
        $renderer->doc .= hsc($data['heading']).' ';
        $renderer->doc .= '<a href="'.$sgf_href.'" title="'.$this->getLang('sgfdownload').'">[SGF]</a>';
        $renderer->doc .= '</div></div></div>';

        if($data['break']) {
            $return_str .= '<br class="godiag-' . $data['divclass'] . '"/>';
        }

        $renderer->doc .= $return_str;
        return true;
    }


    /**
     * Handle the match.
     *
     * Most work is done here, like parsing the syntax and creating the image and SGF file
     */
    function handle($match, $state, $pos, Doku_Handler $handler){
        $sourceandlinks = trim(substr($match,4,-5));


        // diagram specific things
        $this->dgm = array (
                'dia' => array(),
                'edge_top' => false,
                'edge_bottom' => false,
                'edge_left' => false,
                'edge_right' => false,
                'black_first' => true,
                'coord_markers' => false,
                'offset_x' => 0,
                'offset_y' => 0,
                'board_size' => 19,
                'gridh' => 0,
                'gridv' => 0,
                'imap_html' => '',
                'imappings' => array(),
                'divclass' => 'right', // enclose HTML in div class="godiag-$whatever"
                'break' => false);      // append br style="clear: left|right" to HTML

        // this will be concatenated to so we can compute a hash
        $str_for_hash='';

        // strip empty space and final newline
        $sourceandlinks=preg_replace('/(^|\n)\s*/',"$1", $sourceandlinks);
        $sourceandlinks=preg_replace("/\s*$/",'', $sourceandlinks);

        // separate source from links part
        $source_parts=preg_split("/\n[\$\s]*(?=\[)/", $sourceandlinks, 2);

        // links: store mappings for image map
        if(array_key_exists(1, $source_parts)){
            foreach (explode("\n", $source_parts[1]) as $link_line) {
                if(preg_match('/\[\s*([^\s])\s*\|\s*([^\]]+?)\s*\]/', $link_line, $matches)) {
                    $this->dgm['imappings'][$matches[1]]=$matches[2];
                }
            }
        }
        ksort($this->dgm['imappings']);
        foreach($this->dgm['imappings'] as $symb => $href) {
            $str_for_hash .= $symb . '!' . $href . '!';
        }

        // source
        $source_lines=explode("\n", $source_parts[0]);

        // header
        preg_match('/(\$\$[^\s]*)?\s*(.*)/', $source_lines[0], $matches);
        $hdr=$matches[1];
        $heading=$matches[2];
        $hdr=explode('#', $hdr);
        $h_ops = $hdr[0];

        if(strpos($h_ops, 'W')) {
            $this->dgm['black_first'] = false;
        }
        if(strpos($h_ops, 'b')) {
            $this->dgm['break'] = 'true';
        }
        if(strpos($h_ops, 'c')) {
            $this->dgm['coord_markers'] = true;
        }
        if(strpos($h_ops, 'l')) {
            $this->dgm['divclass'] = 'left';
        }
        if(strpos($h_ops, 'r')) {
            $this->dgm['divclass'] = 'right';
        }
        if(preg_match('/(\d+)/', $h_ops, $matches)) {
            $this->dgm['board_size'] = $matches[1];
        }
        $this->dgm['title'] = $heading;
        $heading = htmlspecialchars($heading);
        $last=count($source_lines)-1;
        if(preg_match('/^(\$|\s)*[-+]+\s*$/', $source_lines[$last])) {
            $this->dgm['edge_bottom']=true;
            unset($source_lines[$last]);
        }
        unset($source_lines[0]);
        if(preg_match('/^(\$|\s)*[-+]+\s*$/', $source_lines[1])) {
            $this->dgm['edge_top']=true;
            unset($source_lines[1]);
        }

        // get the diagram into $this->dgm['dia'][y][x], figure out dimensions and edges,
        // and generate html for image map
        $row=0;
        foreach ($source_lines as $source_line) {
            if(preg_match('/^(\s|\$)*\|/', $source_line)) { $this->dgm['edge_left']=true; }
            if(preg_match('/\|\s*$/', $source_line)) { $this->dgm['edge_right']=true; }
            $plainstr=str_replace(array('$', ' ', '|'), '', $source_line);
            $str_for_hash .= '$' . $plainstr;
            $as_array=preg_split('//', $plainstr, -1, PREG_SPLIT_NO_EMPTY);
            $len=count($as_array);
            foreach($as_array as $bx => $symb) {
                if(array_key_exists($symb, $this->dgm['imappings'])) {
                    $this->dgm['imap_html'] .= $this->imap_area($bx, $row, $this->dgm['imappings'][$symb]);
                }
            }
            if($len>$this->dgm['gridh']) { $this->dgm['gridh']=$len; }
            $this->dgm['dia'][$row++]=$as_array;
        }
        $this->dgm['gridv']=$row;

        // calc dimensions
        $dimh=($this->dgm['gridh']-1)*$this->style['line_sp']+$this->style['edge_sp']*2+1;
        $dimv=($this->dgm['gridv']-1)*$this->style['line_sp']+$this->style['edge_sp']*2+1;
        if($this->dgm['coord_markers']) {
            $dimh += $this->style['coord_sp'];
            $dimv += $this->style['coord_sp'];
        }
        if(($offs_sz_arr=$this->calc_offsets_and_size())===false) {
            return $this->error_box('non-square board');
        }
        list($this->dgm['board_size'], //this will be overwritten if it conflicts with other input
                $this->dgm['offset_x'],
                $this->dgm['offset_y'])   =   $offs_sz_arr;

        if($this->dgm['board_size'] > $this->style['board_max'])
            return $this->error_box('board too large (max is ' . $this->style['board_max'] . ')');

        //determine implicit edges
        if($this->dgm['gridv'] >= $this->dgm['board_size'] - 1) {
            $this->dgm['edge_top'] = true;
            if($this->dgm['gridv'] == $this->dgm['board_size'])
            $this->dgm['edge_bottom']=true;
        }
        if($this->dgm['gridh'] >= $this->dgm['board_size'] - 1) {
            $this->dgm['edge_left']=true;
            if($this->dgm['gridh'] == $this->dgm['board_size'])
                $this->dgm['edge_right']=true;
        }

        // compute hashes (yes this duplicates a bit of what getCacheName() would do anyway,
        // but keeping it here makes porting easier)
        $str_for_hash .=  '!' . $this->dgm['black_first']
        . '!' . $this->dgm['edge_top']
        . '!' . $this->dgm['edge_bottom']
        . '!' . $this->dgm['edge_left']
        . '!' . $this->dgm['edge_right'];

        $str_for_hash_sgf = $str_for_hash
            . '!' . $this->dgm['board_size']
            . '!' . $this->dgm['title'];

        $str_for_hash_img = $str_for_hash
            . '!' . ($this->dgm['coord_markers'] ? $this->dgm['board_size'] : false);

        $md5hash_png=md5($str_for_hash_img . '!' . serialize($this->style));
        $md5hash_sgf=md5($str_for_hash_sgf);

        // get filenames
        $filename_png = getCacheName($md5hash_png,'.godiag.png');
        $filename_sgf = getCacheName($md5hash_sgf,'.godiag.sgf');

        // if we don't have the PNG for this diagram, create it
        if(@filemtime($filename_png) < filemtime(__FILE__)) {
            $draw_result = $this->save_diagram($dimh, $dimv, $filename_png);
            if($draw_result!==0) return $draw_result; // contains error message
        }

        // if we don't have the SGF for this diagram, create it
        if(@filemtime($filename_sgf) < filemtime(__FILE__)) {
            if(!io_saveFile($filename_sgf,$this->SGF())){
                return $this->error_box("Cannot create SGF file.");
            }
        }

        // now pass only the interesting data to the renderer
        $data = array(
            'md5hash_png' => $md5hash_png,
            'md5hash_sgf' => $md5hash_sgf,
            'imap_html'   => $this->dgm['imap_html'],
            'divclass'    => $this->dgm['divclass'],
            'break'       => $this->dgm['break'],
            'heading'     => $heading,
            'width'       => $dimh,
            'height'      => $dimv,
        );

        return $data;
    } // end of get_html()

    /**
     * creates an anti-aliased circle
     *
     * @author <klaas at kosmokrator dot com>
     * @link http://www.php.net/manual/en/function.imageantialias.php#61932
     */
    function circ( &$img, $cx, $cy, $cr, $color) {
        $ir = $cr;
        $ix = 0;
        $iy = $ir;
        $ig = 2 * $ir - 3;
        $idgr = -6;
        $idgd = 4 * $ir - 10;
        $fill = imageColorExactAlpha( $img, $color[0], $color[1], $color[2], 0);
        imageLine( $img, $cx + $cr - 1, $cy, $cx, $cy, $fill );
        imageLine( $img, $cx - $cr + 1, $cy, $cx - 1, $cy, $fill );
        imageLine( $img, $cx, $cy + $cr - 1, $cx, $cy + 1, $fill );
        imageLine( $img, $cx, $cy - $cr + 1, $cx, $cy - 1, $fill );
        $draw = imageColorExactAlpha( $img, $color[0], $color[1], $color[2], 42);
        imageSetPixel( $img, $cx + $cr, $cy, $draw );
        imageSetPixel( $img, $cx - $cr, $cy, $draw );
        imageSetPixel( $img, $cx, $cy + $cr, $draw );
        imageSetPixel( $img, $cx, $cy - $cr, $draw );
        while ( $ix <= $iy - 2 ) {
            if ( $ig < 0 ) {
                $ig += $idgd;
                $idgd -= 8;
                $iy--;
            } else {
                $ig += $idgr;
                $idgd -= 4;
            }
            $idgr -= 4;
            $ix++;
            imageLine( $img, $cx + $ix, $cy + $iy - 1, $cx + $ix, $cy + $ix, $fill );
            imageLine( $img, $cx + $ix, $cy - $iy + 1, $cx + $ix, $cy - $ix, $fill );
            imageLine( $img, $cx - $ix, $cy + $iy - 1, $cx - $ix, $cy + $ix, $fill );
            imageLine( $img, $cx - $ix, $cy - $iy + 1, $cx - $ix, $cy - $ix, $fill );
            imageLine( $img, $cx + $iy - 1, $cy + $ix, $cx + $ix, $cy + $ix, $fill );
            imageLine( $img, $cx + $iy - 1, $cy - $ix, $cx + $ix, $cy - $ix, $fill );
            imageLine( $img, $cx - $iy + 1, $cy + $ix, $cx - $ix, $cy + $ix, $fill );
            imageLine( $img, $cx - $iy + 1, $cy - $ix, $cx - $ix, $cy - $ix, $fill );
            $filled = 0;
            for ( $xx = $ix - 0.45; $xx < $ix + 0.5; $xx += 0.2 ) {
                for ( $yy = $iy - 0.45; $yy < $iy + 0.5; $yy += 0.2 ) {
                    if ( sqrt( pow( $xx, 2 ) + pow( $yy, 2 ) ) < $cr ) $filled += 4;
                }
            }
            $draw = imageColorExactAlpha( $img, $color[0], $color[1], $color[2], ( 100 - $filled ) );
            imageSetPixel( $img, $cx + $ix, $cy + $iy, $draw );
            imageSetPixel( $img, $cx + $ix, $cy - $iy, $draw );
            imageSetPixel( $img, $cx - $ix, $cy + $iy, $draw );
            imageSetPixel( $img, $cx - $ix, $cy - $iy, $draw );
            imageSetPixel( $img, $cx + $iy, $cy + $ix, $draw );
            imageSetPixel( $img, $cx + $iy, $cy - $ix, $draw );
            imageSetPixel( $img, $cx - $iy, $cy + $ix, $draw );
            imageSetPixel( $img, $cx - $iy, $cy - $ix, $draw );
        }
    }

    function draw_hoshi($im, $bx, $by) {
        $coords=$this->get_coords($bx, $by);
        $this->circ($im, $coords[0], $coords[1], $this->style['hoshi_radius'], $this->style['line_acolor']);
    }

    function draw_white($im, $bx, $by) {
        $coords=$this->get_coords($bx, $by);
        $this->circ($im, $coords[0], $coords[1], $this->style['stone_radius'], $this->style['white_rim_acolor']);
        $this->circ($im, $coords[0], $coords[1], $this->style['stone_radius']-1, $this->style['white_acolor']);
    }

    function draw_black($im, $bx, $by) {
        $coords=$this->get_coords($bx, $by);
        $this->circ($im, $coords[0], $coords[1], $this->style['stone_radius'], $this->style['black_acolor']);
    }

    function draw_white_circle($im, $bx, $by) {
        $coords=$this->get_coords($bx, $by);
        $this->draw_white($im, $bx, $by);
        $this->circ($im, $coords[0], $coords[1], $this->style['mark_radius'], $this->style['mark_acolor']);
        $this->circ($im, $coords[0], $coords[1], $this->style['mark_radius']-2, $this->style['white_acolor']);
    }

    function draw_black_circle($im, $bx, $by) {
        $coords=$this->get_coords($bx, $by);
        $this->draw_black($im, $bx, $by);
        $this->circ($im, $coords[0], $coords[1], $this->style['mark_radius'], $this->style['mark_acolor']);
        $this->circ($im, $coords[0], $coords[1], $this->style['mark_radius']-2, $this->style['black_acolor']);
    }

    function draw_circle($im, $bx, $by) {
        $coords=$this->get_coords($bx, $by);
        list($x, $y) = $coords=$this->get_coords($bx, $by);
        $r=$this->style['mark_radius'];
        $sim = $this->style['circle_mark_img']['im'];
        $dim = $this->style['circle_mark_img']['dim'];
        imagecopymerge($im, $sim, $x-$r-1, $y-$r-1, 0, 0, $dim, $dim, 100);
    }

    function draw_square($im, $bx, $by) {
        list($x, $y) = $coords=$this->get_coords($bx, $by);
        $x1=$x-($this->style['mark_sqheight']/2);
        $x2=$x+($this->style['mark_sqheight']/2);
        $y1=$y-($this->style['mark_sqheight']/2);
        $y2=$y+($this->style['mark_sqheight']/2);
        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $this->dgm['mark_color']);
    }
    function draw_link($im, $bx, $by) {
        list($x, $y) = $coords=$this->get_coords($bx, $by);
        $x1=$x-($this->style['link_sqheight']/2);
        $x2=$x+($this->style['link_sqheight']/2);
        $y1=$y-($this->style['link_sqheight']/2);
        $y2=$y+($this->style['link_sqheight']/2);
        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $this->dgm['link_color']);
    }
    function draw_white_square($im, $bx, $by) {
        $this->draw_white($im, $bx, $by);
        $this->draw_square($im, $bx, $by);
    }
    function draw_black_square($im, $bx, $by) {
        $this->draw_black($im, $bx, $by);
        $this->draw_square($im, $bx, $by);
    }
    function draw_num($im, $bx, $by) {
        $str=$this->dgm['dia'][$by][$bx];
        $str=($str=='0') ? '10' : $str;
        $blacks_turn = intval($str) % 2 == ($this->dgm['black_first'] ? 1 : 0);
        if($blacks_turn)
            $this->draw_black($im, $bx, $by);
        else
            $this->draw_white($im, $bx, $by);
        list($x, $y) = $coords=$this->get_coords($bx, $by);
        $col=$blacks_turn ? $this->dgm['white_color'] : $this->dgm['black_color'];
        $box = imagettfbbox($this->style['ttfont_sz'], 0, $this->style['ttfont'], $str);
        $basex=$x - intval(abs($box[2]-$box[0])+1)/2 - $box[0];
        imagettftext($im, $this->style['ttfont_sz'], 0, $basex, $y+$this->style['majusc_voffs'], $col, $this->style['ttfont'], $str);
    }

    function draw_let($im, $bx, $by) {
        list($x, $y) = $coords=$this->get_coords($bx, $by);
        $str = $this->dgm['dia'][$by][$bx];
        $box = imagettfbbox($this->style['ttfont_sz'], 0, $this->style['ttfont'], $str);
        $basex = $x - intval(abs($box[2]-$box[0])+1)/2 - $box[0];
        $r = $this->style['stone_radius']-3;
        imagefilledrectangle($im, $x-$r, $y-$r, $x+$r, $y+$r, $this->dgm['goban_color']);
        imagettftext($im, $this->style['ttfont_sz'], 0, $basex, $y+$this->style['minusc_voffs'], $this->dgm['string_color'], $this->style['ttfont'], $str);
    }

    function draw_coord($im, $bx, $by) {
        list($x, $y) = $coords=$this->get_coords($bx, $by);
        if($bx==-1)
            $str = $this->dgm['board_size'] - $this->dgm['offset_y'] - $by;
        else {
            $bx2 = $this->dgm['offset_x'] + $bx;
            $str=chr(65+$bx2+($bx2>7? 1 : 0));
        }
        $box = imagettfbbox($this->style['ttfont_sz'], 0, $this->style['ttfont'], $str);
        if($bx==-1)
            $basex=$x - $this->style['stone_radius']+($str<10?$this->style['sm_offs']:0);
        else {
            $basex=$x - intval(abs($box[2]-$box[0])+1)/2 - $box[0];
        }
        imagettftext($im, $this->style['ttfont_sz'], 0, $basex, $y+$this->style['majusc_voffs'], $this->dgm['string_color'], $this->style['ttfont'], $str);
    }

    function draw_wipe($im, $bx, $by) {
        list($x, $y) = $coords=$this->get_coords($bx, $by);
        imagefilledrectangle($im, $x-$this->style['line_sp']/2+1, $y-$this->style['line_sp']/2+1, $x+$this->style['line_sp']/2, $y+$this->style['line_sp']/2, $this->dgm['goban_color']);
    }

    /**
     * draw and save diagram
     */
    function save_diagram($dimh, $dimv, $filename_png) {
        $im = imagecreatetruecolor($dimh, $dimv);
        if(!$im)
            return($this->error_box("Cannot initialize GD image stream."));

        //some things we only want to do once
        if(!array_key_exists('sm_offs', $this->style)) {
            // calc <10 number horiz offset for coords
            $box = imagettfbbox($this->style['ttfont_sz'], 0, $this->style['ttfont'], '1');
            $box2 = imagettfbbox($this->style['ttfont_sz'], 0, $this->style['ttfont'], '11');
            $this->style['sm_offs'] = ($box2[2]-$box[1]) - ($box[2]-$box[0]) - 1;
            // vertical offsets for majuscules and minuscules
            $box = imagettfbbox($this->style['ttfont_sz'], 0, $this->style['ttfont'], 'a');
            $this->style['minusc_voffs'] = (-$box[5])/2+1;
            $box = imagettfbbox($this->style['ttfont_sz'], 0, $this->style['ttfont'], 'A');
            $this->style['majusc_voffs'] = (-$box[5])/2;
        }
        if(!array_key_exists('circle_mark_img', $this->style)) {
            $r=$this->style['mark_radius'];
            $dim=$r*2+3;
            $xim = imagecreatetruecolor($dim, $dim);
            $gc = $this->style['goban_acolor'];
            $transp = imagecolorallocate($xim, $gc[0], $gc[1], $gc[2]);
            imagecolortransparent($xim, $transp);
            imagefill($xim, 0, 0, $transp);
            $this->circ($xim, $r+1, $r+1, $r, $this->style['mark_acolor']);
            $this->circ($xim, $r+1, $r+1, $r-2, $this->style['goban_acolor']);
            $this->style['circle_mark_img']['im'] = $xim;
            $this->style['circle_mark_img']['dim'] = $dim;
        }

        $this->dgm['line_color'] = $this->acolor2color($im, $this->style['line_acolor']);
        $this->dgm['mark_color'] = $this->acolor2color($im, $this->style['mark_acolor']);
        $this->dgm['link_color'] = $this->acolor2color($im, $this->style['link_acolor_alpha'], true);
        $this->dgm['goban_color'] = $this->acolor2color($im, $this->style['goban_acolor']);
        $this->dgm['black_color'] = $this->acolor2color($im, $this->style['black_acolor']);
        $this->dgm['white_color'] = $this->acolor2color($im, $this->style['white_acolor']);
        $this->dgm['string_color'] = $this->acolor2color($im, $this->style['string_acolor']);

        imagefill($im, 0, 0, $this->dgm['goban_color']);

        // draw lines
        $beginv=$this->dgm['edge_top'] ? $this->style['edge_sp'] : $this->style['line_begin'];
        $beginh=$this->dgm['edge_left'] ? $this->style['edge_sp'] : $this->style['line_begin'];
        $endv=$dimv - ($this->dgm['edge_bottom'] ? $this->style['edge_sp'] : $this->style['line_begin']) - 1;
        $endh=$dimh - ($this->dgm['edge_right'] ? $this->style['edge_sp'] : $this->style['line_begin']) - 1;
        if($this->dgm['coord_markers']) {
            $beginv += $this->style['coord_sp'];
            $beginh += $this->style['coord_sp'];
        }

        // draw horizontal lines
        for($i=0; $i<$this->dgm['gridv']; $i+=1) {
            $coords=$this->get_coords($i, $i);
            imageline($im, $beginh, $coords[0], $endh, $coords[0], $this->dgm['line_color']);
        }
        // draw vertical lines
        for($i=0; $i<$this->dgm['gridh']; $i+=1) {
            $coords=$this->get_coords($i, $i);
            imageline($im, $coords[0], $beginv, $coords[0], $endv, $this->dgm['line_color']);
        }

        // draw coordinates (if requested)
        if($this->dgm['coord_markers']) {
            $this->draw_coord($im, -1, 2);
            for($i=0; $i<$this->dgm['gridv']; $i++)
                $this->draw_coord($im, -1, $i);
            for($i=0; $i<$this->dgm['gridh']; $i++)
                $this->draw_coord($im, $i, -1);
        }

        // draw rest
        foreach($this->dgm['dia'] as $by => $row) {
            foreach($row as $bx => $symb) {
                if($symb>='0' and $symb<='9')
                    $this->draw_num($im, $bx, $by);
                else if($symb>='a' and $symb<='z')
                    $this->draw_let($im, $bx, $by);
                else if($symb!='.') {
                    if(!array_key_exists($symb, $this->functab)) {
                        imagedestroy($im);
                        $oopshtml=htmlspecialchars("unknown symbol \"$symb\"");
                        return $this->error_box($oopshtml);
                    }
                    call_user_func(array($this, $this->functab[$symb]), $im, $bx, $by);
                }
                // draw link, if any
                if(array_key_exists($symb, $this->dgm['imappings'])) {
                    $this->draw_link($im, $bx, $by);
                }

            }
        }

        // save
        if(!imagepng($im, $filename_png)) {
            imagedestroy($im);
            $hfilename=htmlspecialchars($filename_png);
            return $this->error_box("Cannot output diagram to file.");
        }
        imagedestroy($im);
        return 0;
    } // end of $this->save_diagram()

    // other funcs
    function get_coords($bx, $by) {
        $additional_sp=0;
        if($this->dgm['coord_markers'])
            $additional_sp+=$this->style['coord_sp'];
        return array($bx*$this->style['line_sp']+$this->style['edge_sp']+$additional_sp,
                $by*$this->style['line_sp']+$this->style['edge_sp']+$additional_sp);
    }

    /**
     * Calculate an area tag for a image map. Handles external and internal links
     */
    function imap_area($bx, $by, $href) {
        global $ID;

        list($x, $y) = $coords=$this->get_coords($bx, $by);
        $x1=$x-$this->style['line_sp']/2;
        $y1=$y-$this->style['line_sp']/2;
        $x2=$x+$this->style['line_sp']/2;
        $y2=$y+$this->style['line_sp']/2;
        // external or internal link?
        if(strpos($href, '://')){
            $href  = hsc($href);
            $title = $href;
        }else{
            $ns = getNS($ID);
            resolve_pageid($ns,$href,$exists);
            $title = $href;
            $href  = wl($href);
        }

        return "<area href=\"$href\" title=\"$title\" alt=\"$title\" coords=\"$x1,$y1,$x2,$y2\"/>";
    }

    function acolor2color($im, $acolor, $alpha = false) {
        if($alpha)
            return imagecolorexactalpha($im, $acolor[0], $acolor[1], $acolor[2], $acolor[3]);
        else
            return imagecolorallocate($im, $acolor[0], $acolor[1], $acolor[2]);
    }

    /**
     * Used for error reporting. Passes the string inside an array for streamlined
     * handler/renderer error transfer
     */
    function error_box($str) {
        return array('error' => $str);
    }

    /**
     * calculates board size and offsets for SGF and coordinate drawing
     * returns an array (board_size, offset_x, offset_y) or false if there's a conflict
     */
    function calc_offsets_and_size() {
        $sizex = $this->dgm['gridh'];
        $sizey = $this->dgm['gridv'];
        $heightdefined = $this->dgm['edge_top'] && $this->dgm['edge_bottom'];
        $widthdefined = $this->dgm['edge_left'] && $this->dgm['edge_right'];
        $offset_x = 0;
        $offset_y = 0;
        if ($heightdefined) {
            if ($widthdefined && $sizex != $sizey)
                return false;
            if ($sizex > $sizey)
                return false;
            $size = $sizey;
            if ($this->dgm['edge_right']) $offset_x = $size-$sizex;
            elseif (!$this->dgm['edge_left'])     $offset_x = ($size-$sizex)/2;
        }
        elseif ($widthdefined)
        {
            if ($sizey > $sizex)
                return false;
            $size = $sizex;
            if ($this->dgm['edge_bottom'])        $offset_y = $size-$sizey;
            elseif (!$this->dgm['edge_top'])      $offset_y = ($size-$sizey)/2;
        }
        else
        {
            $size = max($sizex, $sizey, $this->dgm['board_size']);

            if ($this->dgm['edge_right']) $offset_x = $size-$sizex;
            elseif (!$this->dgm['edge_left'])     $offset_x = ($size-$sizex)/2;

            if ($this->dgm['edge_bottom'])        $offset_y = $size-$sizey;
            elseif (!$this->dgm['edge_top'])      $offset_y = ($size-$sizey)/2;
        }
        return(array($size, intval($offset_x), intval($offset_y)));
    }

    /**
     * Create an SGF file for the current diagram
     */
    function SGF() {
        $rows = $this->dgm['dia'];
        $title = str_replace(']', '\]', $this->dgm['title']);
        $comment = str_replace(']', '\]', $this->style['sgf_comment']);
        $sizex = $this->dgm['gridh'];
        $sizey = $this->dgm['gridv'];
        $size = $this->dgm['board_size'];
        $offset_x = $this->dgm['offset_x'];
        $offset_y = $this->dgm['offset_y'];
        // SGF Root node string
        $firstcolor = $this->dgm['black_first'] ? 'B' : 'W';
        $SGFString = "(;GM[1]FF[4]SZ[$size]\n\n" .
            "GN[$title]\n" .
            "AP[GoDiag/DokuWiki]\n" .
            "DT[".date("Y-m-d")."]\n" .
            "PL[$firstcolor]\n" .
            "C[$comment]\n";

        $AB = array();
        $AW = array();
        $CR = array();
        $SQ = array();
        $LB = array();

        if (!$this->dgm['black_first']) {
            $oddplayer = 'W';
            $evenplayer = 'B';
        } else {
            $oddplayer = 'B';
            $evenplayer = 'W';
        }

        // output stones, numbers etc. for each row
        for ($ypos=0; $ypos<$sizey; $ypos++) {
            for ($xpos=0; $xpos<$sizex; $xpos++) {
                if(array_key_exists($ypos, $rows) && array_key_exists($xpos, $rows[$ypos]))
                    $curchar = $rows[$ypos][$xpos];
                else
                    continue;
                $position = chr(97+$xpos+$offset_x) .
                    chr(97+$ypos+$offset_y);

                if ($curchar == 'X' || $curchar == 'B' || $curchar == '#')
                    $AB[] = $position;    // add black stone

                if ($curchar == 'O' || $curchar == 'W' || $curchar == '@')
                    $AW[] = $position;    // add white stone

                if ($curchar == 'B' || $curchar == 'W' || $curchar == 'C')
                    $CR[] = $position;    // add circle markup

                if ($curchar == '#' || $curchar == '@' || $curchar == 'S')
                    $SQ[] = $position;    // add circle markup

                // other markup
                if ($curchar % 2 == 1)     // odd numbers (moves)
                {
                    $Moves[$curchar][1] = $position;
                    $Moves[$curchar][2] = $oddplayer;
                }
                elseif ($curchar*2 > 0 || $curchar == '0')  // even num (moves)
                {
                    if ($curchar == '0')
                        $curchar = '10';
                    $Moves[$curchar][1] = $position;
                    $Moves[$curchar][2] = $evenplayer;
                }
                elseif (($curchar >= 'a') && ($curchar <= 'z')) // letter markup
                    $LB[] = "$position:$curchar";
            } // for xpos loop
        }// for ypos loop

        // parse title for hint of more moves
        if ($cnt = preg_match_all($this->hintre, $title, $match)) {
            for ($i=0; $i < $cnt; $i++)
            {
                if (!isset($Moves[$match[1][$i]])  // only if not set on board
                        &&   isset($Moves[$match[2][$i]])) // referred move must be set
                {
                    $mvnum = $match[1][$i];
                    $Moves[$mvnum][1] = $Moves[$match[2][$i]][1];
                    $Moves[$mvnum][2] = $mvnum % 2 ? $oddplayer : $evenplayer;
                }
            }
        }

        // build SGF string
        if (count($AB)) $SGFString .= 'AB[' . join('][', $AB) . "]\n";
        if (count($AW)) $SGFString .= 'AW[' . join('][', $AW) . "]\n";
        $Markup = '';
        if (count($CR)) $Markup = 'CR[' . join('][', $CR) . "]\n";
        if (count($SQ)) $Markup .= 'SQ[' . join('][', $SQ) . "]\n";
        if (count($LB)) $Markup .= 'LB[' . join('][', $LB) . "]\n";
        $SGFString .= "$Markup\n";

        for ($mv=1; $mv <= 10; $mv++)
        {
            if (isset($Moves[$mv])) {
                $SGFString .= ';' . $Moves[$mv][2] . '[' . $Moves[$mv][1] . ']';
                $SGFString .= 'C['. $Moves[$mv][2] . $mv . "]\n";
                $SGFString .= $Markup;
            }
        }

        $SGFString .= ")\n";

        return $SGFString;
    } // end of $this->SGF()


} // end of class

