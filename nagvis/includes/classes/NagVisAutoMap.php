<?php
/**
 * Class for NagVis Automap generating
 */
class NagVisAutoMap extends GlobalMap {
	var $MAINCFG;
	var $MAPCFG;
	var $LANG;
	var $BACKEND;
	
	var $backend_id;
	var $root;
	var $maxLayers;
	var $rootObject;
	var $width;
	var $height;
	var $renderMode;
	var $ignoreHosts;
	
	/**
	 * Automap constructor
	 *
	 * @param		MAINCFG		Object of NagVisMainCfg
	 * @param		LANG			Object of GlobalLanguage
	 * @param		BACKEND		Object of GlobalBackendMgmt
	 * @return	String 		Graphviz configuration
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function NagVisAutoMap(&$MAINCFG, &$LANG, &$BACKEND, $prop) {
		$this->MAINCFG = &$MAINCFG;
		$this->LANG = &$LANG;
		$this->BACKEND = &$BACKEND;
		
		// Create map configuration
		$this->MAPCFG = new NagVisMapCfg($this->MAINCFG, '__automap');
		$this->MAPCFG->readMapConfig();
		
		// Do the preflight checks
		$this->checkPreflight();
		
		if(isset($prop['backend']) && $prop['backend'] != '') {
			$this->backend_id = $prop['backend'];
		} else {
			$this->backend_id = $this->MAINCFG->getValue('defaults', 'backend');
		}
		
		/**
		 * This is the name of the root host, user can set this via URL. If no
		 * hostname is given NagVis tries to take configured host from main
		 * configuration or read the host which has no parent from backend
		 */
		if(isset($prop['root']) && $prop['root'] != '') {
			$this->root = $prop['root'];
		}else {
			$this->root = $this->getRootHostName();
		}
		
		/**
		 * This sets how much layers should be displayed. Default value is -1, 
		 * this means no limitation.
		 */
		if(isset($prop['maxLayers']) && $prop['maxLayers'] != '') {
			$this->maxLayers = $prop['maxLayers'];
		} else {
			$this->maxLayers = -1;
		}
		
		/**
		 * The renderMode can be set via URL, if no is given NagVis takes the "tree"
		 * mode
		 */
		if(isset($prop['renderMode']) && $prop['renderMode'] != '') {
			$this->renderMode = $prop['renderMode'];
		} else {
			$this->renderMode = 'undirected';
		}
		
		if(isset($prop['width']) && $prop['width'] != '') {
			$this->width = $prop['width'];
		} else {
			$this->width = 1024;
		}
		
		if(isset($prop['height']) && $prop['height'] != '') {
			$this->height = $prop['height'];
		} else {
			$this->height = 786;
		}
		
		if(isset($prop['ignoreHosts']) && $prop['ignoreHosts'] != '') {
			$this->ignoreHosts = explode(',', $prop['ignoreHosts']);
		} else {
			$this->ignoreHosts = Array();
		}
		
		// Get "root" host object
		$this->fetchHostObjectByName($this->root);
		
		// Get all object informations from backend
		$this->getObjectTree();
		
		parent::GlobalMap($this->MAINCFG, $this->MAPCFG);
		
		$this->MAPOBJ = new NagVisMapObj($this->MAINCFG, $this->BACKEND, $this->LANG, $this->MAPCFG);
		$this->MAPOBJ->objectTreeToMapObjects($this->rootObject);
		$this->MAPOBJ->fetchState();
	}
	
	/**
	 * Parses the graphviz config of the autmap
	 *
	 * @return	String 		Graphviz configuration
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function parseGraphvizConfig() {
		// FIXME
		$str  = 'graph automap { ';
		//, ranksep="0.1", nodesep="0.4", ratio=auto, bb="0,0,500,500"
		$str .= 'graph [ratio="fill", root="'.$this->rootObject->getType().'_'.$this->rootObject->getName().'", size="'.$this->pxToInch($this->width).','.$this->pxToInch($this->height).'"]; '."\n";
		
		// Default settings for automap nodes
		$str .= 'node [';
		// default margin is 0.11,0.055
		$str .= 'margin="0.11,0.0", ';
		// dot: Minimum space between two adjacent nodes in the same rank, in inches.
		//$str .= 'nodesep="0.15", ';
		$str .= 'ratio="auto", ';
		$str .= 'overlap=false, ';
		$str .= 'shape="none", ';
		$str .= 'fontcolor=black, fontname=Verdana, fontsize=10';
		$str .= '];'."\n ";
		
		// Create nodes for all hosts
		$str .= $this->rootObject->parseGraphviz();
		
		$str .= '} ';
		
		//DEBUG: echo $str;
		
		return $str;
	}
	
	/**
	 * Renders the map image, saves it to var/ directory and creates the map and
	 * ares for the links
	 *
	 * @return	Array		HTML Code
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function renderMap() {
		/**
		 * possible render modes are set by selecting the correct binary:
		 *  dot - filter for drawing directed graphs
     *  neato - filter for drawing undirected graphs
     *  twopi - filter for radial layouts of graphs
     *  circo - filter for circular layout of graphs
     *  fdp - filter for drawing undirected graphs
		 */
		switch($this->renderMode) {
			case 'directed':
				$binary = 'dot';
			break;
			case 'undirected':
				$binary = 'neato';
			break;
			case 'radial':
				$binary = 'twopi';
			break;
			case 'circular':
				$binary = 'circo';
			break;
			case 'undirected2':
				$binary = 'fdp';
			break;
			default:
				//FIXME: Error handling
			break;
		}
		
		// Parse map
		exec('echo \''.$this->parseGraphvizConfig().'\' | '.$this->MAINCFG->getValue('automap','graphvizpath').$binary.' -Tpng -o \''.$this->MAINCFG->getValue('paths', 'var').'automap.png\' -Tcmapx',$arrMapCode);
		
		return implode("\n", $arrMapCode);
	}
	
	/**
	 * Replaces some unwanted things from graphviz html code
	 *
	 * @param		String		Clear HTML code from graphviz binary
	 * @return	String		HTML code with fixed hover menus
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function fixMapCode($strMapCode) {
		/**
		 * Graphviz replaces "-" by "&#45;" so the "-" need to be replaced in the 
		 * hostnames before parsing the graphiz configuration. It gets replaced by 
		 * "__", undo it here
		 */
		$strMapCode = str_replace('__','-',$strMapCode);
		$strMapCode = str_replace('&#45;','-',$strMapCode);
		
		/**
		 * The hover menu can't be rendered in graphviz config. The informations
		 * which are needed here are rendered like this title="<host_name>".
		 *
		 * The best idea I have for this: Extract the hostname and replace
		 * title="<hostname>" with the hover menu code.
		 */
		
		foreach($this->MAPOBJ->getMapObjects() AS $OBJ) {
				$strMapCode = preg_replace('/title=\"'.$OBJ->getName().'\"/', $OBJ->getHoverMenu(), $strMapCode);
		}
		
		return $strMapCode;
	}
	
	/**
	 * Parses the Automap HTML code
	 *
	 * @return	Array		HTML Code
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function parseMap() {
		if (DEBUG&&DEBUGLEVEL&1) debug('Start method NagVisMap::parseMap()');
		$ret = Array();
		
		// Render the map image and save it, also generate link coords etc
		$mapObjects = $this->renderMap();
		
		// Fix the map code
		$mapObjects = $this->fixMapCode($mapObjects);
		
		// Create HTML code for background image
		$ret = array_merge($ret,$this->getBackground());
		
		// Parse the map with its areas
		$ret[] = $mapObjects;
		
		// Create hover areas for map objects
		//$ret[] = $this->getObjects();
		
		// Dynamicaly set favicon
		$ret[] = $this->getFavicon();
		
		// Change title (add map alias and map state)
		// FIXME: This doesn't work here
		$ret[] = '<script type="text/javascript" language="JavaScript">document.title=\''.$this->MAPCFG->getValue('global', 0, 'alias').' ('.$this->MAPOBJ->getSummaryState().') :: \'+document.title;</script>';
		
		if (DEBUG&&DEBUGLEVEL&1) debug('Start method NagVisMap::parseMap(): Array(...)');
		return $ret;
	}
	
	/**
	 * Gets the background of the map
	 *
	 * @return	Array	HTML Code
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getBackground() {
		if (DEBUG&&DEBUGLEVEL&1) debug('Start method NagVisAutoMap::getBackground()');
		
		$src = $this->MAINCFG->getValue('paths', 'htmlvar').'automap.png';
		
		if (DEBUG&&DEBUGLEVEL&1) debug('End method NagVisAutoMap::getBackground(): Array(...)');
		return $this->getBackgroundHtml($src,'','usemap="automap"');
	}
	
	# END Public Methods
	# #####################################################
	
	/**
	 * Do the preflight checks to ensure the automap can be drawn
	 */
	function checkPreflight() {
		// The GD-Libs are used by graphviz
		$this->checkGd(1);
		
		$this->checkVarFolderWriteable(1);
		
		// Check all possibly used binaries of graphviz
		$this->checkGraphviz('dot', 1);
		$this->checkGraphviz('neato', 1);
		$this->checkGraphviz('twopi', 1);
		$this->checkGraphviz('circo', 1);
		$this->checkGraphviz('fdp', 1);
	}
	
	/**
	 * Checks for writeable VarFolder
	 *
	 * @param		Boolean 	$printErr
	 * @return	Boolean		Is Successful?
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function checkVarFolderExists($printErr) {
		if (DEBUG&&DEBUGLEVEL&1) debug('Start method NagVisAutoMap::checkVarFolderExists('.$printErr.')');
		if(file_exists(substr($this->MAINCFG->getValue('paths', 'var'),0,-1))) {
			if (DEBUG&&DEBUGLEVEL&1) debug('End method NagVisAutoMap::checkVarFolderExists(): TRUE');
			return TRUE;
		} else {
			if($printErr == 1) {
				$FRONTEND = new GlobalPage($this->MAINCFG,Array('languageRoot'=>'global:global'));
				$FRONTEND->messageToUser('ERROR','varDirNotExists','PATH~'.$this->MAINCFG->getValue('paths', 'var'));
			}
			if (DEBUG&&DEBUGLEVEL&1) debug('End method NagVisAutoMap::checkVarFolderExists(): FALSE');
			return FALSE;
		}
	}
	
	/**
	 * Checks for writeable VarFolder
	 *
	 * @param		Boolean 	$printErr
	 * @return	Boolean		Is Successful?
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function checkVarFolderWriteable($printErr) {
		if (DEBUG&&DEBUGLEVEL&1) debug('Start method NagVisAutoMap::checkVarFolderWriteable('.$printErr.')');
		if($this->checkVarFolderExists($printErr) && @is_writable(substr($this->MAINCFG->getValue('paths', 'var'),0,-1))) {
			if (DEBUG&&DEBUGLEVEL&1) debug('End method NagVisAutoMap::checkVarFolderWriteable(): TRUE');
			return TRUE;
		} else {
			if($printErr == 1) {
				$FRONTEND = new GlobalPage($this->MAINCFG,Array('languageRoot'=>'global:global'));
				$FRONTEND->messageToUser('ERROR','varDirNotWriteable','PATH~'.$this->MAINCFG->getValue('paths', 'var'));
			}
			if (DEBUG&&DEBUGLEVEL&1) debug('End method NagVisAutoMap::checkVarFolderWriteable(): FALSE');
			return FALSE;
		}
	}
	
	/**
	 * Checks if the Graphviz binaries can be found on the system
	 *
	 * @param		String	Filename of the binary
	 * @param		Bool		Print error message?
	 * @return	String	HTML Code
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function checkGraphviz($binary, $printErr) {
		if (DEBUG&&DEBUGLEVEL&1) debug('Start method NagVisAutoMap::checkGraphviz('.$printErr.')');
		/* FIXME:
		 * Check if the carphviz binaries can be found in the PATH or in the 
		 * configured path
		 */
		// Check if dot can be found in path (If it is ther $returnCode is 0, if not it is 1)
		exec('which '.$binary, $arrReturn, $returnCode1);
		
		if(!$returnCode1) {
			$this->MAINCFG->setValue('automap','graphvizpath',str_replace($binary,'',$arrReturn[0]));
		}
		
		exec('which '.$this->MAINCFG->getValue('automap','graphvizpath').$binary, $arrReturn, $returnCode2);
		
		if(!$returnCode2) {
			$this->MAINCFG->setValue('automap','graphvizpath',str_replace($binary,'',$arrReturn[0]));
		}
		
		if($returnCode1 & $returnCode2) {
			if($printErr) {
				$FRONTEND = new GlobalPage($this->MAINCFG,Array('languageRoot'=>'nagvis:messages'));
				$FRONTEND->messageToUser('ERROR','graphvizBinaryNotFound','NAME~'.$binary.',PATHS~'.$_SERVER['PATH'].':'.$this->MAINCFG->getvalue('automap','graphvizpath'));
			}
			if (DEBUG&&DEBUGLEVEL&1) debug('End method NagVisAutoMap::checkGraphviz(): FALSE');
			return FALSE;
		} else {
			if (DEBUG&&DEBUGLEVEL&1) debug('End method NagVisAutoMap::checkGraphviz(): TRUE');
			return TRUE;
		}
	}
	
	/**
	 * Gets the favicon of the page representation the state of the map
	 *
	 * @return	String	HTML Code
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getFavicon() {
		if (DEBUG&&DEBUGLEVEL&1) debug('Start method NagVisMap::getFavicon()');
		if(file_exists('./images/internal/favicon_'.strtolower($this->MAPOBJ->getSummaryState()).'.png')) {
			$favicon = './images/internal/favicon_'.strtolower($this->MAPOBJ->getSummaryState()).'.png';
		} else {
			$favicon = './images/internal/favicon.png';
		}
		if (DEBUG&&DEBUGLEVEL&1) debug('End method NagVisMap::getFavicon()');
		return '<script type="text/javascript" language="JavaScript">favicon.change(\''.$favicon.'\'); </script>';
	}
	
	/**
	 * This methods converts pixels to inches
	 *
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function pxToInch($px) {
		return round($px/72, 4);
	}
	
	/**
	 * Get all child objects
	 *
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getObjectTree() {
		$this->rootObject->fetchChilds($this->maxLayers, $this->getObjectConfiguration(), $this->ignoreHosts);
	}
	
	/**
	 * Gets the configuration of the objects by the global configuration
	 *
	 * @return	Array		Object configuration
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getObjectConfiguration() {
		$objConf = Array();
		
		// Get object configuration from __automap configuration
		foreach($this->MAPCFG->validConfig['host'] AS $key => $values) {
			if($key != 'type' && $key != 'backend_id' && $key != 'host_name') {
				$objConf[$key] = $this->MAPCFG->getValue('global', 0, $key);
			}
		}
		
		return $objConf;
	}
	
	/**
	 * Get root host object by NagVis configuration or by backend.
	 *
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getRootHostName() {
		$defaultRoot = $this->MAINCFG->getValue('automap','default_root');
		if(isset($defaultRoot) && $defaultRoot != '') {
			return $defaultRoot;
		} else {
			if($this->BACKEND->checkBackendInitialized($this->backend_id, TRUE)) {
				$hostsWithoutParent = $this->BACKEND->BACKENDS[$this->backend_id]->getHostNamesWithNoParent();
				if(count($hostsWithoutParent) == 1) {
					return $hostsWithoutParent[0];
				} else {
					//FIXME: ERROR-Handling: Could not get root host for automap
				}
			}
		}
	}
	
	/**
	 * Creates a hos object by the host name
	 *
	 * @author 	Lars Michelsen <lars@vertical-visions.de>
	 */
	function fetchHostObjectByName($hostName) {
		$hostObject = new NagVisHost($this->MAINCFG, $this->BACKEND, $this->LANG, $this->backend_id, $hostName);
		$hostObject->fetchState();
		$hostObject->fetchIcon();
		$hostObject->setConfiguration($this->getObjectConfiguration());
		$this->rootObject = $hostObject;
	}
}
?>
