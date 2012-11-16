<?php

/**
 * Create a Joomla user from the forms data
 * @package Joomla
 * @subpackage Fabrik
 * @author Rob Clayburn
 * @copyright (C) Rob Clayburn
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

//require the abstract plugin class
require_once(COM_FABRIK_FRONTEND.DS.'models'.DS.'plugin-form.php');

class FabrikModelComment extends FabrikModelFormPlugin {

	/**
	 * Constructor
	 */
	var $_counter = null;

	/**@var string html comment form */
	var $commentform = null;

	var $commentsLocked = null;

	function __construct()
	{
		parent::__construct();
	}

	function getEndContent_result($c)
	{
		return $this->_data;
	}

	/**
	 * determine if you can add new comments
	 * @param object $params
	 * @param object $formModel
	 */

	function commentsLocked(&$params, &$formModel)
	{
		if (is_null($this->commentsLocked)) {
			$this->commentsLocked = false;
			$lock = $params->get('comment_lock_element');
			if ($lock !== '') {
				$lock = str_replace('.', '___', $lock).'_raw';
				$lockval = $formModel->_data[$lock];
				if ($lockval == 1) {
					$this->commentsLocked = true;
				}
			}

		}
		return $this->commentsLocked;
	}

	function getEndContent(&$params, &$formModel)
	{
		$this->commentsLocked($params, $formModel);
		$method = $params->get("comment_method", "disqus");
		switch ($method) {
			default:
			case 'disqus':
				$this->_disqus($params);
				break;
			case 'intensedebate':
				$this->_intensedebate($params);
				break;
			case 'jskit':
				$this->_jskit($params);
				break;
			case 'internal':
				$this->_internal($params, $formModel);
				break;
			case 'jcomment':
				$this->_jcomment($params, $formModel);
				break;
		}
		return true;
	}

	protected function loadDiggJsOpts()
	{
		FabrikHelperHTML::script('table-fabrikdigg.js', 'components/com_fabrik/plugins/element/fabrikdigg/');
		$opts = new stdClass();
		$digg =& $this->getDigg();
		$opts->livesite = COM_FABRIK_LIVESITE;
		$opts->row_id = JRequest::getInt('rowid');
		$opts->voteType = 'comment';
		$opts->imageover = COM_FABRIK_LIVESITE . 'components/com_fabrik/plugins/element/fabrikdigg/images/heart.png';
		$opts->imageout = COM_FABRIK_LIVESITE . 'components/com_fabrik/plugins/element/fabrikdigg/images/heart-off.png';
		$opts->formid = $this->formModel->_id;
		$opts->tableid = $this->formModel->getTableModel()->getTable()->id;
		$opts = json_encode($opts);
		return $opts;
	}

	/**
	 * prepare local comment system
	 *
	 * @param object $params
	 */

	function _internal(&$params, &$formModel)
	{
		$document =& JFactory::getDocument();
		$this->formModel =& $formModel;
		JHTML::stylesheet('comments.css', 'components/com_fabrik/plugins/form/comment/');
		FabrikHelperHTML::script('comments.js', 'components/com_fabrik/plugins/form/comment/');
		if (FabrikWorker::nativeMootools12()) {
			FabrikHelperHTML::script('inlineedit-mt124.js', 'components/com_fabrik/plugins/form/comment/');
		} else {
			FabrikHelperHTML::script('inlineedit.js', 'components/com_fabrik/plugins/form/comment/');
		}

		if ($this->doDigg()) {
			$digopts = $this->loadDiggJsOpts();
		}else{
			$digopts ="{}";
		}

		$db =& JFactory::getDBO();
		$user =& JFactory::getUser();
		$data = '<div id="fabrik-comments">';
		$rowid = JRequest::getVar('rowid');
		if (strstr($rowid, ':')) {
			// SLUG
			$rowid = array_shift(explode(':', $rowid));
		}
		$comments =& $this->getComments($formModel->_id, $rowid);

		$data .= "<h3><a href=\"#\" name=\"comments\">";
		if (empty($comments)) {
			$data .= JText::_('NO_COMMENTS');

		} else {
			if ($params->get('comment-show-count-in-title')) {
				$data .= count($comments) . " ";
			}
			$data .=  JText::_('COMMENTS');
		}
		$data .= "</a></h3>";

		$data .= $this->writeComments($params, $comments);

		$anonymous = $params->get('comment-internal-anonymous');
		if (!$this->commentsLocked) {
			if ($user->get('id') == 0 && $anonymous == 0) {
				$data .= "<h3>".JText::_('PLEASE_SIGN_IN_TO_LEAVE_A_COMMENT') . "</h3>";
			} else {
				$data .= "<h3>".JText::_('ADD_COMMENT') . "</h3>";
			}
			$data .= $this->getAddCommentForm($params, 0, true);
		}
		//form

		$data .= "</div>";

		$opts = new stdClass();
		$opts->liveSite = COM_FABRIK_LIVESITE;
		$opts->formid 	= $formModel->_id;
		$opts->rowid 		= JRequest::getVar('rowid');
		$opts->admin    = $user->get('gid') == 25 ? true : false;
		$opts 					= json_encode($opts);

		$lang								= new stdClass();
		$lang->prompt 			= JText::_('TYPE_A_COMMENT_HERE');
		$lang->entercomment = JText::_('PLEASE_ENTER_A_COMMENT_BEFORE_POSTING');
		$lang->entername 		= JText::_('PLEASE_ENTER_A_COMMENT_BEFORE_POSTING');
		$lang->enteremail 	= JText::_('ENTER_EMAL_BEFORE_POSTNG');
		$lang = json_encode($opts);

		$script = "window.addEvent('domready', function() {
		var comments = new FabrikComment('fabrik-comments', $opts, $lang);";

		if ($this->doDigg()) {
			$script .= "\n comments.digg = new FbDiggTable(".$this->formModel->_id.", $digopts);";
		}
		$script .= "\n});";
		FabrikHelperHTML::addScriptDeclaration($script);
		$this->_data = $data;
	}

	function getAddCommentForm($params, $reply_to = 0, $master = false)
	{
		$user =& JFactory::getUser();
		$anonymous = $params->get('comment-internal-anonymous');
		if ($user->get('id') == 0 && $anonymous == 0) {
			return;
		}
		$m = $master ? " id='master-comment-form' " : '';
		$data = "<form action=\"index.php\"$m class=\"replyform\">\n<p><textarea style=\"width:95%\" rows=\"6\" cols=\"3\">".JText::_('TYPE_A_COMMENT_HERE')."</textarea></p>\n";
		$data .= "<table class=\"adminForm\" style=\"width:350px\" summary=\"comments\">\n";
		if ($user->get('id') == 0) {
			$data .= "<tr>\n";
			$name = trim(JRequest::getVar('ide_people___voornaam', '', 'cookie') . ' '. JRequest::getVar('ide_people___achternaam', '', 'cookie'));
			$email = JRequest::getVar('ide_people___email', '', 'cookie');
			$data .= "<td>\n<label for=\"add-comment-name-$reply_to\">". JText::_('NAME')."</label>\n<br />\n<input class='inputbox' type='text' size='20' id=\"add-comment-name-$reply_to\" name='name' value='$name' /></td>\n";
			$data .= "<td>\n<label for=\"add-comment-email-$reply_to\">". JText::_('EMAIL')."</label>\n<br /><input class='inputbox' type='text' size='20' id=\"add-comment-email-$reply_to\" name='email' value='$email' /></td>\n";
			$data .= "</tr>\n";
		}

		if ($this->notificationPluginInstalled( $this->formModel)) {
			if ($params->get('comment-plugin-notify') == 1) {
				$data .= "<tr>\n";
				$data .= "<td>\n" . JText::_('NOTIFY_ME')."<label>\n<input type='radio' name='comment-plugin-notify[]' checked='checked' class='inputbox' value='1'>". JText::_('NO')."\n</label>\n</td>\n";
				$data .= "<td>\n<label><input type='radio' name='comment-plugin-notify[]' class='inputbox' value='0'>". JText::_('YES')."</label>\n</td>";
				$data .= "</tr>\n";
			}
		}
		$rating = $params->get('comment-internal-rating');

		if ($rating == 1 || $anonymous == 1) {
			$data .= "<tr>\n<td>\n";

			if ($rating) {
				$data .= "<label for=\"add-comment-rating-$reply_to\">".JText::_('RATING')."</label><br />";
				$data .= "<select id=\"add-comment-rating-$reply_to\" class=\"inputbox\" name=\"rating\">\n<option value=\"0\">". JText::_('NONE')."</option>\n";
				$data .= "<option value=\"1\">". JText::_('ONE')."</option>\n";
				$data .= "<option value=\"2\">". JText::_('TWO')."</option>\n";
				$data .= "<option value=\"3\">". JText::_('THREE')."</option>\n";
				$data .= "<option value=\"4\">". JText::_('FOUR')."</option>\n";
				$data .= "<option value=\"5\">". JText::_('FIVE')."</option>\n</select>\n";
			}

			$data .= "</td>\n<td>\n";
			if ($anonymous) {
				$data .= JText::_('Anonymous').'<br />';
				$data .= "<label for=\"add-comment-anonymous-no-$reply_to\">".JText::_('NO')."</label>
				<input type=\"radio\" id=\"add-comment-anonymous-no-$reply_to\" name=\"annonymous[]\" checked=\"checked\" class=\"inputbox\" value=\"0\" />\n";
				$data .= "<label for=\"add-comment-anonymous-yes-$reply_to\">".JText::_('YES')."</label>
				<input type=\"radio\" id=\"add-comment-anonymous-yes-$reply_to\" name=\"annonymous[]\" class=\"inputbox\" value=\"1\" />\n";
			}
			$data .= "</td>\n</tr>\n";
		}
		$data .= "<tr>\n<td colspan=\"2\">\n";
		$data .= "<input type=\"button\" class=\"button\" style=\"margin-left:0\" value=\"" . JText::_('POST_COMMENT') . "\" />\n";
		$data .= "<input type=\"hidden\" name=\"reply_to\" value=\"$reply_to\" />\n";
		$data .= "<input type=\"hidden\" name=\"renderOrder\" value=\"$this->renderOrder\" />\n";
		$data .= "</td>\n</tr>\n";
		$data .= "</table>\n</form>\n";
		return $data;
	}

	//TODO replace parentid with left/right markers
	// see http://dev.mysql.com/tech-resources/articles/hierarchical-data.html

	function getComments($formid, $rowid)
	{
		$rowid = (int)$rowid;
		$formid = (int)$formid;
		$db =& JFactory::getDBO();
		$db->setQuery("SELECT c.*, u.gid FROM #__fabrik_comments AS c LEFT JOIN #__users AS u ON c.user_id = u.id WHERE c.formid = $formid AND c.row_id = $rowid AND c.approved = 1 ORDER BY c.time_date ASC");
		$rows = $db->loadObjectList();

		$main = array();
		$replies = array();
		if (!is_array($rows)) {
			return array();
		}
		foreach ($rows as $row) {
			if ($row->reply_to == 0) {
				$main[$row->id] = $row;
			} else {
				if (!array_key_exists($row->reply_to, $replies)) {
					$replies[$row->reply_to] = array();
				}
				$replies[$row->reply_to][] = $row;
			}
		}

		$return = array();


		foreach ($main as $v) {
			$depth = 0;
			$v->depth = $depth;
			$return[$v->id] = $v;
			$this->_getReplies($v, $replies, $return, $depth);
		}
		return $return;

	}

	function _getReplies($v, $replies, &$return, $depth)
	{
		$depth ++;
		if (array_key_exists($v->id, $replies) && is_array($replies[$v->id])) {
			foreach ($replies[$v->id] as $row) {
				$row->depth = $depth;
				$return[$row->id] = $row;
				$this->_getReplies($row, $replies, $return, $depth);
			}
		}
	}

	function writeComments(&$params, &$comments )
	{
		$data = "<ul id=\"fabrik-comment-list\">\n";
		if (empty($comments)) {
			$data .= "<li class=\"empty-comment\">&nbsp;</li>";
		} else {
			foreach ($comments as $comment) {
				$depth = (int)$comment->depth * 20;
				$data .= "<li class=\"usergroup-" . $comment->gid . "\" id='comment_" . $comment->id . "' style='margin-left:" .$depth . "px'>\n";
				$data .= $this->writeComment($params, $comment);
				$data .= "\n</li>\n";
			}
		}
		$data .= "</ul>\n";
		return $data;
	}

	function writeComment(&$params, $comment )
	{
		$user =& JFactory::getUser();
		$name = (int)$comment->annonymous == 0 ? $comment->name : JText::_('ANONYMOUS_SHORT');
		$data = "<div class=\"metadata\">\n$name " . JText::_('WROTE_ON') . " <small>".JHTML::date($comment->time_date). "</small>\n";
		if ($params->get('comment-internal-rating') == 1) {
			$data .= "<div class=\"rating\">\n";
			$r = (int)$comment->rating;
			for ($i=0; $i<$r; $i++) {
				$data .= "<img src=\"".COM_FABRIK_LIVESITE."components/com_fabrik/plugins/form/comment/images/star_in.png\" alt=\"star\" />\n";
			}
			$data .= "\n</div>";
		}
		if ($this->doDigg()) {
			$digg =& $this->getDigg();
			$digg->_editable = true;
			$digg->commentDigg = true;
			$digg->commentId = $comment->id;
			if (JRequest::getVar('tableid') == '') {
				JRequest::setVar('tableid', $this->getTableId());
			}
			JRequest::setVar('commentId', $comment->id);
			$id = "digg_".$comment->id;
			$data .= "<div id=\"$id\"class=\"digg fabrik_row fabrik_row___".$this->formModel->_id."\">\n".$digg->render(array())."\n</div>\n";
		}
		$data .= "</div>\n";
		$data .= "<div class=\"comment\" id=\"comment-$comment->id\">
		<div class=\"comment-content\">$comment->comment</div>";
		$data .= "<div class=\"reply\">";
		if (!$this->commentsLocked) {
			$data .= "<a href=\"#\" class=\"replybutton\">".JText::_('REPLY')."</a>\n";
		}
		if ($user->get('gid') == 25) {
			$data .="<div class=\"admin\">\n<a href=\"#\" class=\"del-comment\">" . JText::_('DELETE') . "</a>\n</div>\n";
		}
		$data .= "</div>\n";


		$data .="</div>\n";
		if (!$this->commentsLocked) {
			$data .= $this->getAddCommentForm($params, $comment->id);
		}
		return $data;
	}

	protected function getTableId()
	{
		return $this->formModel->getTableModel()->getTable()->id;
	}

	/**
	 * get digg element
	 * @return object digg element
	 */

	protected function getDigg()
	{
		if (!isset($this->digg)) {
			$this->digg = $this->formModel->getPluginManager()->getPlugIn('fabrikdigg', 'element');
		}
		return $this->digg;
	}

	/**
	 * delete a comment called from ajax request
	 */


	function deleteComment()
	{
		$db =& JFactory::getDBO();
		$id = JRequest::getInt('comment_id');
		$db->setQuery("DELETE FROM #__fabrik_comments WHERE id = $id");
		$db->query();
		echo $id;
	}

	/**
	 * update a comment called from ajax request by admin
	 */

	function updateComment()
	{
		$db =& JFactory::getDBO();
		$id = JRequest::getInt('comment_id');
		$comment = $db->Quote(JRequest::getVar('comment'));
		$db->setQuery("UPDATE #__fabrik_comments SET comment = $comment	WHERE id = $id");
		$db->query();
	}

	private function setFormModel()
	{
		$formModel =& JModel::getInstance('form', 'FabrikModel');
		$formModel->setId(JRequest::getVar('formid'));
		$this->formModel =& $formModel;
		return $this->formModel;
	}
	/**
	 * add a comment called from ajax request
	 */

	function addComment()
	{

		$db =& JFactory::getDBO();
		$user =& JFactory::getUser();
		$row = new TableComment($db);
		$row->bind( JRequest::get('request'));
		$row->ipaddress = $_SERVER['REMOTE_ADDR'];
		$row->user_id 	= $user->get('id');
		$row->approved 	= 1;
		//@TODO this isnt set?
		$row->url 			= @$_SERVER["HTTP_REFERER"];
		$rowid = JRequest::getVar('rowid');
		$row->formid = JRequest::getVar('formid');
		$row->row_id = $rowid;
		if ($user->get('id') != 0) {
			$row->name = $user->get('name');
			$row->email = $user->get('email');
		}
		//load up the correct params for the plugin -
		//first load all form params
		$formModel = $this->setFormModel();
		$params =& $formModel->getParams();
		$tmp = array();
		$this->renderOrder = JRequest::getVar('renderOrder', 2);
		//then map that data (for correct render order) onto this plugins params
		$params =& $this->setParams($params, $tmp, $tmp);
		$res = $row->store();
		if ($res === false) {
			echo $row->getError();
			exit;
		}
		$obj = new stdClass();
		//do this to get the depth of the comment
		$comments = $this->getComments($row->formid, $row->row_id);
		$row = $comments[$row->id];
		$obj->content =$this->writeComment($params, $row);
		$obj->depth = (int)$row->depth;
		$obj->id = $row->id;

		$notificationPlugin = $this->notificationPluginInstalled($formModel);

		if ($notificationPlugin) {
			$this->addNotificationEvent($row, $formModel);
		}
		$comment_plugin_notify = JRequest::getVar('comment-plugin-notify');
		//do we notify everyone?
		if ($params->get('comment-internal-notify') == 1) {
			if ($notificationPlugin) {
				$this->saveNotificationToPlugin($row, $comments, $formModel);
			} else {
				$this->sentNotifications($row, $comments, $formModel);
			}
		}
		echo json_encode($obj);
	}

	function addNotificationEvent($row, $formModel)
	{
		$db =& JFactory::getDBO();
		$event = $db->Quote('COMMENT_ADDED');
		$user =& JFactory::getUser();
		$user_id = (int)$user->get('id');
		$ref = $db->Quote($formModel->getTableModel()->getTable()->id.'.'.$formModel->_id.'.'.JRequest::getVar('rowid'));
		$date = $db->Quote(JFactory::getDate()->toMySQL());
		$db->setQuery("INSERT INTO #__fabrik_notification_event (`event`, `user_id`, `reference`, `date_time`) VALUES ($event, $user_id, $ref, $date)");
		$db->query();
	}

	/**
	 * once we've ensured that the notification plugin is installed
	 * add the user
	 * @param object $row
	 * @param array comments objects
	 * @param object form model
	 */

	function saveNotificationToPlugin($row, $comments, $formModel)
	{
		$db =& JFactory::getDBO();
		$user =& JFactory::getUser();
		$user_id = (int)$user->get('id');
		$ref = $db->Quote($formModel->getTableModel()->getTable()->id.'.'.$formModel->_id.'.'.JRequest::getVar('rowid'));
		$db->setQuery("INSERT INTO #__fabrik_notification (`reason`, `user_id`, `reference`) VALUES ('commentor', $user_id, $ref)");
		$db->query();
	}

	/**
	 * test if the notification plugin is installed
	 * @param $formModel
	 * @return unknown_type
	 */

	function notificationPluginInstalled($formModel)
	{
		return $formModel->getPluginManager()->pluginExists('cron', 'cronnotification');
	}

	private function doDigg()
	{
		$params =& $this->getParams();
		return $params->get('comment-digg') && $this->formModel->getPluginManager()->pluginExists('element', 'fabrikdigg');
	}


	/**
	 * default send notifcations code
	 * @param object $row
	 * @param array comments objects
	 * @param object form model
	 */
	function sentNotifications($row, $comments, $formModel)
	{
		$db =& JFactory::getDBO();
		$user =& JFactory::getUser();
		$app =& JFactory::getApplication();
		$sentto = array();
		$title = JText::_('NEWCOMMENTADDEDTITLE');
		$message = JText::_('NEWCOMMENTADDED');
		$message .= "<br /><a href=\"{$row->url}\">" . JText::_('VIEWCOMMENT'). "</a>";

		foreach ($comments as $comment) {
			if ($comment->id == $row->id) {
				//dont sent notification to user who just posted
				continue;
			}
			if (!in_array($comment->email, $sentto)) {
				JUtility::sendMail($app->getCfg( 'mailfrom'), $app->getCfg( 'fromname'), $comment->email, $title, $message, true);
				$sentto[] = $comment->email;
			}
		}
		//notify original poster (hack for ideenbus
		$tableModel =& $formModel->getTableModel();
		$rowdata =& $tableModel->getRow($row->row_id);
		if (!in_array($rowdata->ide_idea___email_raw, $sentto)) {
			JUtility::sendMail($app->getCfg( 'mailfrom'), $app->getCfg( 'fromname'), $rowdata->ide_idea___email_raw, $title, $message, true);
			$sentto[] = $rowdata->ide_idea___email_raw;
		}

		//notify admins
		//get all super administrator
		$query = 'SELECT name, email, sendEmail' .
					' FROM #__users' .
					' WHERE LOWER(usertype) = "super administrator" AND sendEmail = "1"';
		$db->setQuery($query);
		$rows = $db->loadObjectList();

		foreach ($rows as $row)
		{
			JUtility::sendMail($mailfrom, $fromname, $row->email, $subject2, $message2);
			if (!in_array($row->email, $sentto)) {
				JUtility::sendMail($app->getCfg('mailfrom'), $app->getCfg('fromname'), $row->email, $title, $message, true);
				$sentto[] = $row->email;
			}
		}

	}

	public function getEmail()
	{
		$commentid = JRequest::getInt('commentid');
		$c =& JTable::getInstance('Comment', 'Table');
		$c->load($commentid);
		echo "<a href=\"mailto:$c->email\">$c->email</a>";
	}

	/**
	 * prepare jskit comment system - doesn't require a jskit acount
	 *
	 * @param unknown_type $params
	 */
	function _jskit(&$params)
	{
		$this->_data = '
 		<div class="js-kit-comments" permalink=""></div>
<script src="http://js-kit.com/comments.js"></script>';
	}

	/**
	 * prepate intense debate comment system
	 *
	 * @param unknown_type $params
	 */
	function _intensedebate(&$params)
	{
		FabrikHelperHTML::addScriptDeclaration("
var idcomments_acct = '".$params->get('comment-intesedebate-code')."';
var idcomments_post_id;
var idcomments_post_url;");
		$this->_data = '
<span id="IDCommentsPostTitle" style="display:none"></span>
<script type=\'text/javascript\' src=\'http://www.intensedebate.com/js/genericCommentWrapperV2.js\'></script>';
	}

	/**
	 * prepate diqus comment system
	 *
	 * @param object $params
	 */
	function _disqus($params)
	{
		FabrikHelperHTML::addScriptDeclaration(
 		"
(function() {
		var links = document.getElementsByTagName('a');
		var query = '?';
		for (var i = 0; i < links.length; i++) {
			if(links[i].href.indexOf('#disqus_thread') >= 0) {
				query += 'url' + i + '=' + encodeURIComponent(links[i].href) + '&';
			}
		}
		document.write('<script type=\"text/javascript\" src=\"http://disqus.com/forums/rotterdamvooruit/get_num_replies.js' + query + '\"></' + 'script>');
	})();
"
		);
		$this->_data = '<div id="disqus_thread"></div><script type="text/javascript" src="http://disqus.com/forums/'.$params->get('comment-disqus-subdomain').'/embed.js"></script><noscript><a href="http://rotterdamvooruit.disqus.com/?url=ref">View the discussion thread.</a></noscript><a href="http://disqus.com" class="dsq-brlink">blog comments powered by <span class="logo-disqus">Disqus</span></a>';;
	}

	/**
	 * prepare JComment system
	 *
	 * @param object $params
	 * @param object $formModel
	 */

	function _jcomment(&$params, $formModel)
	{
		$jcomments = JPATH_SITE.DS.'components'.DS.'com_jcomments'.DS.'jcomments.php';
		if (JFile::exists($jcomments)) {
			require_once($jcomments);
			$this->_data = '<div id="jcomments" style="clear: both;">
                    '.JComments::showComments(JRequest::getVar('rowid'), "com_fabrik_{$formModel->_id}").'
                    </div>';
		}
		else {
			JError::raiseNotice(500, JText::_('JComment is not installed on your system'));
		}
	}

}


/**
 * @package		Joomla
 * @subpackage	Fabrik
 */
class TableComment extends JTable
{

	/** @var int Primary key */
	var $id = null;

	/** @var int user id posting*/
	var $user_id  = null;

	/** @var string ip address of poster */
	var $ipaddress = null;

	/** @var int if its a reply - what's it's id  */
	var $reply_to = null;

	/** @var strign comment */
	var $comment = null;

	/** @var bol is the comment approved or not */
	var $approved = null;

	/** @var timestamp */
	var $time_date = null;

	var $name = null;

	var $email = null;

	var $formid = null;

	var $row_id = null;


	/** @var string url to page where comment is being made **/
	var $url = null;

	var $rating = null;

	var $annonymous = null;

	/*
	 *
	 */

	function __construct(&$_db)
	{
		parent::__construct('#__fabrik_comments', 'id', $_db);
	}

}
?>