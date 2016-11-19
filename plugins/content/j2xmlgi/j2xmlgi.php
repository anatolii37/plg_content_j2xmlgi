<?php
/**
 * @version		3.6.26 plugins/content/j2xmlgi/j2xmlgi.php
 * 
 * @package		J2XML Grab Images
 * @subpackage	PLG_CONTENT_J2XMLGI
 * @since		3.2
 *
 * @author		Helios Ciancio <info@eshiol.it>
 * @link		http://www.eshiol.it
 * @copyright	Copyright (C) 2013, 2016 Helios Ciancio. All Rights Reserved
 * @license		http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * J2XML is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License 
 * or other free or open source software licenses.
 */
 
// no direct access
defined('_JEXEC') or die('Restricted access.');

jimport('joomla.plugin.plugin');
jimport('joomla.filesystem.folder');

class plgContentJ2xmlgi extends JPlugin
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 */
	protected $autoloadLanguage = true;
	
	private $_images_folder='images/j2xml/';
	private $_images_path;
	
	/**
	 * Constructor
	 *
	 * @param  object  $subject  The object to observe
	 * @param  array   $config   An array that holds the plugin configuration
	 */
	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		if ($this->params->get('debug') || defined('JDEBUG') && JDEBUG)
		{
			JLog::addLogger(array('text_file' => $this->params->get('log', 'eshiol.log.php'), 'extension' => 'plg_content_j2xmlgi_file'), JLog::ALL, array('plg_content_j2xmlgi'));
		}
		if (PHP_SAPI == 'cli')
		{
			JLog::addLogger(array('logger' => 'echo', 'extension' => 'plg_content_j2xmlgi'), JLOG::ALL & ~JLOG::DEBUG, array('plg_content_j2xmlgi'));
		}
		else
		{
			JLog::addLogger(array('logger' => (null !== $this->params->get('logger')) ?$this->params->get('logger') : 'messagequeue', 'extension' => 'plg_content_j2xmlgi'), JLOG::ALL & ~JLOG::DEBUG, array('plg_content_j2xmlgi'));
			if ($this->params->get('phpconsole') && class_exists('JLogLoggerPhpconsole'))
			{
				JLog::addLogger(['logger' => 'phpconsole', 'extension' => 'plg_content_j2xmlgi_phpconsole'],  JLOG::DEBUG, array('plg_content_j2xmlgi'));
			}
		}
		JLog::add(__METHOD__, JLOG::DEBUG, 'plg_content_j2xmlgi');
	
		$this->_images_folder = 'images/'.$this->params->get('folder', 'j2xml').'/';
		$this->_images_path=JPATH_ROOT.'/'.$this->_images_folder;
	}
	
	/**
	 * Before save content method
	 * Article is passed by value.
	 * Method is called right before the content is saved
	 *
	 * @param	string		The context of the content passed to the plugin (added in 1.6)
	 * @param	object		A JTableContent object
	 * @param	bool		If the content is just about to be created
	 * @since   3.2
	 */
	public function onContentBeforeSave($context, $article, $isNew)
	{
		JLog::add(new JLogEntry(__METHOD__, JLOG::DEBUG, 'plg_content_j2xmlgi'));
		
		if (($action = $this->params->get('action', 1)) == 0)
		{
			return true;
		}
		JLog::add(new JLogEntry('processing introtext', JLOG::DEBUG, 'plg_content_j2xmlgi'));
		$this->externalImages($article->introtext, $action == 1);
		JLog::add(new JLogEntry('processing fulltext', JLOG::DEBUG, 'plg_content_j2xmlgi'));
		$this->externalImages($article->fulltext, $action == 1);
		if (($images = json_decode($article->images)))
		{
			JLog::add(new JLogEntry('processing image_intro', JLOG::DEBUG, 'plg_content_j2xmlgi'));
			if (preg_match("/https?:\/\/(.+)/si", $images->image_intro, $data))
			{
				if ($action == 1)
				{
					$images->image_intro = $this->importImage($images->image_intro);
				}
				else
				{
					JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_IMAGE_REMOVED', $images->image_intro), JLOG::INFO, 'plg_content_j2xmlgi'));
					$images->image_intro = '';
				}
			}
			JLog::add(new JLogEntry('processing image_fulltext', JLOG::DEBUG, 'plg_content_j2xmlgi'));
			if (preg_match("/https?:\/\/(.+)/si", $images->image_fulltext, $data))
			{
				if ($action == 1)
				{
					$images->image_fulltext = $this->importImage($images->image_fulltext);
				}
				else
				{
					JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_IMAGE_REMOVED', $images->image_fulltext), JLOG::INFO, 'plg_content_j2xmlgi'));
					$images->image_fulltext = '';
				}
			}
			$article->images = json_encode($images);
		}
		
		return true;
	}

	private function externalImages(&$text, $import = true)
	{
		JLog::add(new JLogEntry(__METHOD__, JLOG::DEBUG, 'plg_content_j2xmlgi'));
		
		$src = '';
		preg_match_all("/<img .*?(?=src)src=[\"']([^\"]+)[\"'][^>]*>/si", $text, $matches);
		if (is_array($matches) && !empty($matches))
		{
			for ($i = 0; $i < count($matches[0]); $i++)
			{
				preg_match_all("/https?:\/\/(.+)/si", $matches[1][$i], $data);
				if (is_array($data) && !empty($data))
				{
					//for ($j = 0; $j < count($data[0]); $j++)
					if (count($data[0]))
					{
						if ($import)
						{
							$text = str_replace($data[0][0], $this->importImage($data[0][0]), $text);
						}
						else
						{
							$text = str_replace($matches[0][$i], '', $text);
							JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_IMAGE_REMOVED', $data[0][0]), JLOG::INFO, 'plg_content_j2xmlgi'));
						}
					}
				}
			}
		}
	}
	

	private static function getImage(&$text, &$src, &$title, &$alt)
	{
		JLog::add(new JLogEntry(__METHOD__, JLOG::DEBUG, 'plg_content_j2xmlgi'));
		
		preg_match('/<img(.*)>/i', $text, $matches);
		if (is_array($matches) && !empty($matches))
		{
			$text = preg_replace("/<img[^>]+\>/i","",$text,1);
			preg_match_all('/(src|alt|title)=("[^"]*")/i',$matches[0], $attr);
			for ($i = 0; $i < count($attr[0]); $i++)
				$$attr[1][$i] = substr($attr[2][$i], 1, -1);
		}
	}
	
	private function importImage($image)
	{
		JLog::add(new JLogEntry(__METHOD__.' image: '.$image, JLOG::DEBUG, 'plg_content_j2xmlgi'));

		if (($img = @file_get_contents($image)) === false)
		{
			JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_IMAGE_DOWNLOAD_FAILED', $image), JLOG::WARNING, 'plg_content_j2xmlgi'));
			return false;
		}

		$img_name = md5($img);
		$parse = parse_url($img);
		
		JLog::add(new JLogEntry(__METHOD__.' subfolder: '.$this->params->get('subfolder', 0), JLOG::DEBUG, 'plg_content_j2xmlgi'));
		if ($n = $this->params->get('subfolder'))
			$src = $image_path = $this->_images_folder.substr(md5($parse['host']), -$n).'/'.$img_name;
		else 
			$src = $image_path = $this->_images_folder.$img_name;
		$image_path = JPATH_ROOT.'/'.$image_path;
		
		JLog::add(new JLogEntry(__METHOD__.' finfo: '.class_exists('finfo'), JLOG::DEBUG, 'plg_content_j2xmlgi'));
		if (class_exists('finfo'))
		{
			$fi = new finfo(FILEINFO_MIME);
			$mime_type = $fi->buffer($img);
			$mime_type = substr($mime_type, 0, strpos($mime_type, ';'));
			JLog::add(new JLogEntry(__METHOD__.' mime: '.$mime_type, JLOG::DEBUG, 'plg_content_j2xmlgi'));
			
			if (($extension = $this->get_extension($mime_type)) !== false)
			{
				$src = preg_replace('/\\.[^.\\s]{3,4}$/', '', $src).$extension;
				$image_path = preg_replace('/\\.[^.\\s]{3,4}$/', '', $image_path).$extension;
			}
		}
		else
		{
			$extension = pathinfo($image, PATHINFO_EXTENSION);

			$src = preg_replace('/\\.[^.\\s]{3,4}$/', '', $src).'.'.$extension;
			$image_path = preg_replace('/\\.[^.\\s]{3,4}$/', '', $image_path).'.'.$extension;
		}
		JLog::add(new JLogEntry(__METHOD__.' extension: '.$extension, JLOG::DEBUG, 'plg_content_j2xmlgi'));
		JLog::add(new JLogEntry(__METHOD__.' img_name: '.$img_name, JLOG::DEBUG, 'plg_content_j2xmlgi'));
		JLog::add(new JLogEntry(__METHOD__.' src: '.$src, JLOG::DEBUG, 'plg_content_j2xmlgi'));
		JLog::add(new JLogEntry(__METHOD__.' image_path: '.$image_path, JLOG::DEBUG, 'plg_content_j2xmlgi'));
		
		if (file_exists($image_path))
		{		
			JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_IMAGE_IMPORTED', $image, $src), JLOG::INFO, 'plg_content_j2xmlgi'));
		}
		else
		{
			if (!JFolder::exists($this->_images_path)) 
			{
				if (JFolder::create($this->_images_path))
				{
					JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_FOLDER_WAS_SUCCESSFULLY_CREATED', $this->_images_path), JLOG::INFO, 'plg_content_j2xmlgi'));
				}
				else
				{
					JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_ERROR_CREATING_FOLDER', $this->_images_path),JLOG::WARNING,'plg_content_j2xmlgi'));
					return false;
				}
			}
			if (JFile::write($image_path, $img))
			{
				JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_IMAGE_SAVED', $image, $src), JLOG::INFO, 'plg_content_j2xmlgi'));
				JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_IMAGE_IMPORTED', $image, $src), JLOG::INFO, 'plg_content_j2xmlgi'));
			}
			else
			{
				JLog::add(new JLogEntry(JText::sprintf('PLG_CONTENT_J2XMLGI_MSG_IMAGE_NOT_IMPORTED', $image),JLOG::WARNING,'plg_content_j2xmlgi'));
			}
		}
		return $src;
	}
	
	
	/**
	 * Method to convert image type to file extension
	 *
	 * @param	string		The image type
	 * @return	string		The file extension
	 * @since   2.5
	 */
	function get_extension($imagetype)
	{
		JLog::add(new JLogEntry(__METHOD__.' imagetype: '.$imagetype, JLOG::DEBUG, 'plg_content_j2xmlgi'));

		switch($imagetype)
		{
			case 'image/bmp': return '.bmp';
			case 'image/cis-cod': return '.cod';
			case 'image/gif': return '.gif';
			case 'image/ief': return '.ief';
			case 'image/jpeg': return '.jpg';
			case 'image/pipeg': return '.jfif';
			case 'image/tiff': return '.tif';
			case 'image/x-cmu-raster': return '.ras';
			case 'image/x-cmx': return '.cmx';
			case 'image/x-icon': return '.ico';
			case 'image/x-portable-anymap': return '.pnm';
			case 'image/x-portable-bitmap': return '.pbm';
			case 'image/x-portable-graymap': return '.pgm';
			case 'image/x-portable-pixmap': return '.ppm';
			case 'image/x-rgb': return '.rgb';
			case 'image/x-xbitmap': return '.xbm';
			case 'image/x-xpixmap': return '.xpm';
			case 'image/x-xwindowdump': return '.xwd';
			case 'image/png': return '.png';
			case 'image/x-jps': return '.jps';
			case 'image/x-freehand': return '.fh';
			default: return false;
		}
	}
}	