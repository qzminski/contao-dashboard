<?php

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *
 * PHP version 5
 * @copyright  terminal42 gmbh 2009-2013
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Kamil Kuźmiński <kamil.kuzminski@terminal42.ch>
 * @license    LGPL
 */


class Dashboard extends Backend
{	

	/**
	 * Generate and output the backend dashboard
	 */
	public function addSystemMessages()
	{
		$this->Database = Database::getInstance();
			
		if (!$this->Database->tableExists('tl_dashboard'))
		{
			return null;
		}
			
		$this->Config = Config::getInstance();
		$this->User = BackendUser::getInstance();
		$this->String = String::getInstance();
		$this->Environment = Environment::getInstance();
		
		$validRows = $this->getRows();
		$strBuffer = '<div id="mod_dashboard">';
		
		foreach ($validRows as $i=>$row)
		{
			if ($GLOBALS['TL_CONFIG']['dashboardLimit'] > 0 && $i == $GLOBALS['TL_CONFIG']['dashboardLimit'])
			{
				$strBuffer .= '<div class="dashboard_toggler">' . $GLOBALS['TL_LANG']['MSC']['dashboardMore'] . '</div><div class="dashboard_accordion">';
			}
			
			$strHeadline = '';
			
			// Output headline
			if (strlen($row['headline']))
			{
				$objTemplate = new BackendTemplate('ce_headline');
				$objTemplate->hl = 'h2';
				$objTemplate->class = 'ce_headline';
				$objTemplate->headline = $row['headline'];
				$strHeadline = $objTemplate->parse();
				$strBuffer .= $strHeadline;
			}
		
			$objTemplate = new BackendTemplate('ce_text');
			$objTemplate->class = 'ce_text';
	
			$text = $this->String->encodeEmail($row['text']);
			$text = str_ireplace(array('<u>', '</u>'), array('<span style="text-decoration:underline;">', '</span>'), $text);
			$text = str_ireplace(array('</p>', '<br /><br />'), array("</p>\n\n", "<br /><br />\n\n"), $text);
	
			// Use an image instead of the title
			if ($row['addImage'] && strlen($row['singleSRC']) && is_file(TL_ROOT . '/' . $row['singleSRC']))
			{
				$this->addImageToTemplate($objTemplate, $row);
			}
			
			$cssID = deserialize($row['cssID']);
			
			if (strlen($cssID[0]))
			{
				$objTemplate->cssID = ' id="'.$cssID[0].'"';
			}
				
			if (strlen($cssID[1]))
			{
				$objTemplate->class .= ' '.$cssID[1];
			}
	
			$objTemplate->text = $text;
			$objTemplate->style = strlen($row['bgcolor']) ? 'background-color: #' . $row['bgcolor'] : '';
			$objTemplate->style .= $row['style'];
			
			if ($row['mandatory'] && !$_SESSION['BE_DATA']['tl_dashboard_mandatory'][$row['id']])
			{
				if ($_GET['dashaccept'] == $row['id'])
				{
					$this->Session = Session::getInstance();
					$arrSession = $this->Session->getData();
					$arrSession['tl_dashboard_mandatory'][$row['id']] = true;
					
					$this->Session->setData($arrSession);
					$this->redirect($this->Environment->script);
				}
				
				$this->loadLanguageFile('tl_dashboard');				
				$GLOBALS['TL_CSS'][] = 'system/modules/dashboard/assets/dashboard.min.css';
				
				return '<div id="mb_dashboard">' . $strHeadline . $this->replaceBackendTags($objTemplate->parse()) . "</div><script>
window.addEvent('domready', function() {
	Mediabox.open('#mb_dashboard', '" . $GLOBALS['TL_LANG']['MSC']['tl_dashboard']['accept'] . "');
	document.removeEvents();
	$('mbOverlay').removeEvents();
	$('mbCloseLink').removeEvents('click').set('href', '" . $this->Environment->script . "?dashaccept=" . $row['id'] . "');
});
</script>";
			}
			
			$strBuffer .= $this->replaceBackendTags($objTemplate->parse());
		}
		
		if ($GLOBALS['TL_CONFIG']['dashboardLimit'] > 0 && $i >= $GLOBALS['TL_CONFIG']['dashboardLimit'])
		{			
			$strBuffer .= '</div><script>
window.addEvent(\'domready\', function() {
  new Accordion($$(\'div.dashboard_toggler\'), $$(\'div.dashboard_accordion\'), {
    display: false,
    alwaysHide: true,
    opacity: false
  });
});</script>';
		}
		
		$strBuffer .= '<br /></div>';
		$GLOBALS['TL_CSS'][] = 'system/modules/dashboard/assets/dashboard.min.css';
		$GLOBALS['TL_JAVASCRIPT'][] = 'system/modules/dashboard/assets/dashboard.min.js';

		return $strBuffer;
	}
	
	
	/**
	 * Replace the backend tags for dashboard
	 * @param string
	 * @return string
	 */
	public function replaceBackendTags($strBuffer)
	{
		if ($GLOBALS['TL_CONFIG']['disableInsertTags'])
		{
			return $strBuffer;
		}

		$tags = array();
		preg_match_all('/{{[^}]+}}/i', $strBuffer, $tags);

		// Replace tags
		foreach ($tags[0] as $tag)
		{
			$elements = explode('::', trim(str_replace(array('{{', '}}'), array('', ''), $tag)));

			switch (strtolower($elements[0]))
			{
				// Front end user
				case 'user':
					if (!BE_USER_LOGGED_IN)
					{
						$strBuffer = str_replace($tag, '', $strBuffer);
						break;
					}

					$this->import('BackendUser', 'User');
					$strBuffer = str_replace($tag, $this->User->$elements[1], $strBuffer);
					break;
			}
		}

		return $this->replaceInsertTags($strBuffer);
	}
	
	
	/**
	 * Get the dahboard items and return them as array
	 * @return array
	 */
	private function getRows()
	{
		$validRows = array();
		$arrRow = $this->Database->execute("SELECT * FROM tl_dashboard WHERE published=1 OR start>0 OR stop>0 ORDER BY sorting")
					   			 ->fetchAllAssoc();
					   			 
		foreach ($arrRow as $row)
		{
			if (!$row['published'] || ($row['start'] > 0 && $row['start'] > time()) || ($row['stop'] > 0 && $row['stop'] < time()))
			{
				continue;
			}
			
			if ($row['restrictGroups'] || $row['restrictUsers'])
			{
				if ($row['restrictGroups'] && count(array_intersect($this->User->groups, deserialize($row['groups']))) > 0)
				{
					$validRows[] = $row;
					continue;
				}
				
				if ($row['restrictUsers'] && in_array($this->User->id, deserialize($row['users'])))
				{
					$validRows[] = $row;
					continue;
				}
			}
			else
			{
				$validRows[] = $row;
				continue;
			}
		}
		
		return $validRows;
	}
}
