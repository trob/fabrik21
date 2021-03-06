<?php
/**
 * Plugin element to render an image slideshow
 * @package fabrikar
 * @author Rob Clayburn
 * @copyright (C) Rob Clayburn
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

require_once(JPATH_SITE.DS.'components'.DS.'com_fabrik'.DS.'models'.DS.'element.php');

class FabrikModelFabrikSlideshow extends FabrikModelElement {

	var $_pluginName = 'slideshow';

	/**
	 * Constructor
	 */

	function __construct()
	{
		parent::__construct();
	}

	function setIsRecordedInDatabase()
	{
		$this->_recordInDatabase = false;
	}


	/**
	 * draws the form element
	 * @param array data
	 * @param int repeat group counter
	 * @return string returns element html
	 */

	function render($data, $repeatCounter = 0)
	{
		$params =& $this->getParams();
		$id 	= $this->getHTMLId($repeatCounter);
		if ($this->_editable) {
			return '<div id="'.$id.'"></div>';
		}
		//$value =  $this->getValue($data, $repeatCounter);
		$ret = "
			<div id=\"$id\" class=\"slideshow\">
				<div class=\"slideshow-images\">
					<a><img /></a>
					<div class=\"slideshow-loader\"></div>
				</div>
				<div class=\"slideshow-captions\"></div>
				<div class=\"slideshow-controller\"></div>
		";
		if ($params->get('slideshow_thumbnails', false)) {
			$ret .= "
				<div class=\"slideshow-thumbnails\"></div>
			";
		}
		$ret .= "
			</div>
		";
		return $ret;
	}

	function renderTableData($data, $oAllRowsData)
	{
		return $this->render($data);
	}

	/**
	 * draws the form element
	 * @param array data
	 * @param int repeat group counter
	 * @param array options
	 * @return string default value
	 */

	function getValue($data, $repeatCounter = 0, $opts = array())
	{
		if (!isset($this->defaults)) {
			$this->defaults = array();
		}
		$name = $this->getFullName(false, true, false);
		$valueKey = $repeatCounter . serialize($opts);
		if (!array_key_exists($valueKey, $this->defaults)) {
			$element =& $this->getElement();
			$params =& $this->getParams();
			$watch = $params->get('display_observe', '');
			if (!empty($watch)) {
				$watchid = $this->_getWatchId();
				$elementModel =& $this->_getObserverElement();
				if (get_class($elementModel) == 'FabrikModelFabrikDatabasejoin') {
					$join =& $elementModel->getJoin();
					$elDb =& $elementModel->getDb();
					$elKey = FabrikString::safeColName($this->_getWatchKey());
					$elText = FabrikString::safeColName($this->_getWatchText());
					$elVal = $elementModel->getValue($data, $repeatCounter);
					$elQuery = "SELECT $elText AS text FROM {$join->table_join} WHERE $elKey = ".$elDb->Quote($elVal);
					$elDb->setQuery($elQuery);
					$value = $elDb->loadResult();
				}
			}
			else {
				// 	$$$rob - if no search form data submitted for the search element then the default
				// selection was being applied instead
				if (array_key_exists('use_default', $opts) && $opts['use_default'] == false) {
					$value = '';
				} else {
					$value   = $this->getDefaultValue($data);
				}
			}
			if ($value === '') { //query string for joined data
				$value = JArrayHelper::getValue($data, $name);
			}
			$formModel =& $this->getForm();
			//stops this getting called from form validation code as it messes up repeated/join group validations
			if (array_key_exists('runplugins', $opts) && $opts['runplugins'] == 1) {
				$formModel->getPluginManager()->runPlugins('onGetElementDefault', $formModel, 'form', $this);
			}
			$this->defaults[$valueKey] = $value;
		}
		return $this->defaults[$valueKey];
	}

	/**
	 * get the db field description
	 *
	 * @return string
	 */

	function getFieldDescription()
	{
		$p = $this->getParams();
		if ($this->encryptMe()) {
			return 'BLOB';
		}
		return "TEXT";
	}

	function renderAdminSettings(&$lists)
	{
		$element =& $this->getElement();
		$pluginParams =& $this->getPluginParams();
		?>
<div id="page-<?php echo $this->_name;?>" class="elementSettings"
	style="display: none"><?php

	echo $pluginParams->render('params');
	?></div>
	<?php
	}

	function getImageJSData($repeatCounter = 0)
	{
		$tableModel 	=& $this->getTableModel();
		$fabrikDb 		=& $tableModel->getDb();
		$params =& $this->getParams();
		$slideshow_thumbnails = $params->get('slideshow_thumbnails', false);
		$slideshow_table = (int)$params->get('slideshow_table', '');
		$fabrikDb->setQuery("SELECT db_table_name FROM #__fabrik_tables WHERE id = $slideshow_table");
		$dbname 			= $fabrikDb->loadResult();
		$slideshow_fk = $params->get('slideshow_fk', '');
		$slideshow_file = $params->get('slideshow_file', '');
		$slideshow_caption = $params->get('slideshow_caption', '');
		$slideshow_fk = FabrikString::safeColName($slideshow_fk);
		$slideshow_field  = FabrikString::safeColName($slideshow_file);
		$db =& JFactory::getDBO();
		$rowid = JRequest::getInt('rowid');
		if ($rowid == '-1') {
			$usekey = JRequest::getInt('rowid');
			if (empty($usekey)) {
				$user = JFactory::getUser();
				$rowid = $user->get('id');
			}
			else {
				$orig_pk = FabrikString::safeColNameToArrayKey($tableModel->_table->db_primary_key);
				$rowid = $this->_form->_data[$orig_pk];
			}
		}
		$field_list = "$slideshow_field AS slideshow_file";
		if (!empty($slideshow_caption)) {
			$slideshow_caption  = FabrikString::safeColName($slideshow_caption);
			$field_list .= ", $slideshow_caption AS slideshow_caption";
		}
		$query = "SELECT $field_list FROM ".$db->nameQuote($dbname)." WHERE $slideshow_fk = ".$db->Quote($rowid);
		$db->setQuery($query);
		$a_pics = $db->loadObjectList();
		if (empty($a_pics)) {
			return '';
		}
		$js_opts = array();
		foreach ($a_pics as $key => $pic) {
			$pic_opts = array();
			$pic->slideshow_file = str_replace('\\', '/', $pic->slideshow_file);
			if (isset($pic->slideshow_caption)) {
				$pic_opts['caption'] = $pic->slideshow_caption;
			}
			if ($slideshow_thumbnails) {
				$mythumb = dirname($pic->slideshow_file) . '/thumbs/' . basename($pic->slideshow_file);
				$pic_opts['thumbnail'] = $mythumb;
			}
			$js_opts[$pic->slideshow_file] = $pic_opts;
		}
		return $js_opts;
	}

	/**
	 * return the javascript to create an instance of the class defined in formJavascriptClass
	 * @return string javascript to create instance. Instance name must be 'el'
	 */

	function elementJavascript($repeatCounter)
	{
		$params =& $this->getParams();
		$id = $this->getHTMLId($repeatCounter);
		$use_thumbs = $params->get('slideshow_thumbnails', 0);
		$use_captions = $params->get('slideshow_caption', '') == '' ? 'false' : 'true';
		$opts =& $this->getElementJSOptions($repeatCounter);
		$opts->slideshow_data = $slideshow_data = $this->getImageJSData($repeatCounter);
		$opts->id = $this->_id;
		$opts->html_id = $html_id = $this->getHTMLId($repeatCounter);
		$opts->liveSite = COM_FABRIK_LIVESITE;
		$opts->slideshow_type = $params->get('slideshow_type', 1);
		$opts->slideshow_width = (int)$params->get('slideshow_width', 400);
		$opts->slideshow_height = (int)$params->get('slideshow_height', 300);
		$opts->slideshow_delay = (int)$params->get('slideshow_delay', 5000);
		$opts->slideshow_duration = (int)$params->get('slideshow_duration', 2000);
		$opts->slideshow_zoom = (int)$params->get('slideshow_zoom', 50);
		$opts->slideshow_pan = (int)$params->get('slideshow_pan', 20);
		$opts->slideshow_thumbnails = $use_thumbs ? 'true' : 'false';
		$opts->slideshow_captions = $use_captions ? 'true' : 'false';
		$opts = json_encode($opts);
		return "
			new fbSlideshow('$id', $opts)
		";
	}

	/**
	 * load the javascript class that manages interaction with the form element
	 * should only be called once
	 * @return string javascript class file
	 */

	function formJavascriptClass()
	{
		$params =& $this->getParams();
		$document =& JFactory::getDocument();
		$params = $this->getParams();
		$slideshow_type = $params->get('slideshow_type', 1);
		FabrikHelperHTML::script('slideshow.js', 'components/com_fabrik/libs/slideshow2/js/', true);
		switch ($slideshow_type) {
			case 1:
				break;
			case 2:
				FabrikHelperHTML::script('slideshow.kenburns.js', 'components/com_fabrik/libs/slideshow2/js/', true);
				break;
			case 3:
				FabrikHelperHTML::script('slideshow.push.js', 'components/com_fabrik/libs/slideshow2/js/', true);
				break;
			case 4:
				FabrikHelperHTML::script('slideshow.fold.js', 'components/com_fabrik/libs/slideshow2/js/', true);
				break;
			default:
				break;
		}
		JHTML::stylesheet('slideshow.css', 'components/com_fabrik/libs/slideshow2/css/');
		FabrikHelperHTML::script('javascript.js', 'components/com_fabrik/plugins/element/fabrikslideshow/', false);
	}

}
?>