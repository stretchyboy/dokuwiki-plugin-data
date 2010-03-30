<?php
/**
 * Options for the data plugin
 *
 * @author Martyn Eggleton <martyn@access-space.org>
 */
 
$meta['edit_content_only']             = array('onoff');

$meta['database_helper']    = array('multichoice', '_choices' => array('sqlite','sqlitepdo'));
