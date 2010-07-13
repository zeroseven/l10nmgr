<?php

/***************************************************************
*  Copyright notice
*
*  (c) 2009 Daniel Zielinski (d.zielinski@l10ntech.de)
*  All rights reserved
*
*  [...]
*
*/

if (!defined('TYPO3_cliMode'))  die('You cannot run this script directly!');

// Include basis cli class
require_once(PATH_t3lib.'class.t3lib_admin.php');
require_once(PATH_t3lib.'class.t3lib_cli.php');

require_once(PATH_typo3.'template.php');

// Load language support
require_once(t3lib_extMgm::extPath('lang', 'lang.php'));
$lang = t3lib_div::makeInstance('language');
$fileRef = 'EXT:l10nmgr/cli/locallang.xml';
$lang->includeLLFile($fileRef);

$extPath = t3lib_extMgm::extPath('l10nmgr');

require_once($extPath.'views/class.tx_l10nmgr_l10ncfgDetailView.php');
require_once($extPath.'views/class.tx_l10nmgr_l10nHTMLListView.php');
require_once($extPath.'views/excelXML/class.tx_l10nmgr_excelXMLView.php');
require_once($extPath.'views/CATXML/class.tx_l10nmgr_CATXMLView.php');
require_once($extPath.'views/class.tx_l10nmgr_abstractExportView.php');

require_once($extPath.'models/class.tx_l10nmgr_l10nConfiguration.php');
require_once($extPath.'models/class.tx_l10nmgr_l10nBaseService.php');
require_once($extPath.'models/class.tx_l10nmgr_translationData.php');
require_once($extPath.'models/class.tx_l10nmgr_translationDataFactory.php');
require_once($extPath.'models/class.tx_l10nmgr_l10nBaseService.php');

require_once(PATH_t3lib.'class.t3lib_parsehtml_proc.php');

class tx_cliexport_cli extends t3lib_cli {

	/**
	 * Constructor
	 */
    function tx_cliexport_cli () {

        // Running parent class constructor
        parent::t3lib_cli();

	// Adding options to help archive:
	$this->cli_options[] = array('--format', 'Format for export of tranlatable data', "The value of level can be:\n  CATXML = XML for translation tools (default)\n  EXCEL = Microsoft XML format \n");
	$this->cli_options[] = array('--config', 'Localization Manager configurations', "UIDs of the localization manager configurations to be used for export. Comma seperated values, no spaces.\nDefault is EXTCONF which means values are taken from extension configuration.\n");
	$this->cli_options[] = array('--target', 'Target languages', "UIDs for the target languages used during export. Comma seperated values, no spaces. Default is 0. In that case UIDs are taken from extension configuration.\n");
	$this->cli_options[] = array('--workspace', 'Workspace ID', "UID of the workspace used during export. Default = 0\n");
	$this->cli_options[] = array('--hidden', 'Do not export hidden contents', "The values can be: \n TRUE = Hidden content is skipped\n FALSE = Hidden content is exported. Default is FALSE.\n");
	$this->cli_options[] = array('--updated', 'Export only new/updated contents', "The values can be: \n TRUE = Only new/updated content is exported\n FALSE = All content is exported (default)\n");
	$this->cli_options[] = array('--help', 'Show help', "");
	$this->cli_options[] = array('-h', 'Same as --help', "");

        // Setting help texts:
        $this->cli_help['name'] = 'Localization Manager exporter';
        $this->cli_help['synopsis'] = '###OPTIONS###';
        $this->cli_help['description'] = 'Class with export functionality for l10nmgr';
        $this->cli_help['examples'] = '/.../cli_dispatch.phpsh l10nmgr_export --format=CATXML --config=l10ncfg --target=tlangs --workspace=wsid --hidden=TRUE --updated=FALSE';
        $this->cli_help['author'] = 'Daniel Zielinski - L10Ntech.de, (c) 2009';
    }

    /**
     * CLI engine
     *
     * @param    array        Command line arguments
     * @return    string
     */
    function cli_main($argv) {

	global $lang;

	// Performance measuring
	$time_start = microtime(true);

	// Load the configuration
	$this->loadExtConf();

	if (isset($this->cli_args['--help']) || isset($this->cli_args['-h'])){
		$this->cli_validateArgs();
		$this->cli_help();
		exit;
	}

        // get format (CATXML,EXCEL)
        //$format = (string)$this->cli_args['_DEFAULT'][1];
	$format = isset($this->cli_args['--format']) ? $this->cli_args['--format'][0] : 'CATXML';

	// get l10ncfg command line takes precedance over extConf
        //$l10ncfg = (string)$this->cli_args['_DEFAULT'][2];
	$l10ncfg = isset($this->cli_args['--config']) ? $this->cli_args['--config'][0] : 'EXTCONF';
	if ($l10ncfg !== "EXTCONF" && !empty($l10ncfg)) {
		//export single
		$l10ncfgs = split(",",$l10ncfg);
	} elseif (!empty($this->lConf['l10nmgr_cfg'])) {
		//export multiple
		$l10ncfgs = split(",",$this->lConf['l10nmgr_cfg']);
	} else {
		$this->cli_echo($lang->getLL('error.no_l10ncfg.msg')."\n");
		exit;
	}

	// get target languages
	//$tlang = (string)$this->cli_args['_DEFAULT'][3]; //extend to list of target languages!
	$tlang = isset($this->cli_args['--target']) ? $this->cli_args['--target'][0] : '0';
	if ($tlang !== "0") {
		//export single
		$tlangs = split(",",$tlang);
	} elseif (!empty($this->lConf['l10nmgr_tlangs'])) {
		//export multiple
		$tlangs = split(",",$this->lConf['l10nmgr_tlangs']);
	} else {
		$this->cli_echo($lang->getLL('error.target_language_id.msg')."\n");
		exit;
	}

	// get workspace ID
	//$wsId = (string)$this->cli_args['_DEFAULT'][4];
	$wsId = isset($this->cli_args['--workspace']) ? $this->cli_args['--workspace'][0] : '0';
	if (t3lib_div::testInt($wsId)===FALSE) {
		$this->cli_echo($lang->getLL('error.workspace_id_int.msg')."\n");
		exit;
	}
	$msg = "";

        // Force user to admin state
        $GLOBALS['BE_USER']->user['admin'] = 1;

        // Set workspace to the required workspace ID from CATXML:
       	$GLOBALS['BE_USER']->setWorkspace($wsId);

        if ($format == 'CATXML') {
		foreach ($l10ncfgs as $l10ncfg){
			if (t3lib_div::testInt($l10ncfg)===FALSE) {
				$this->cli_echo($lang->getLL('error.l10ncfg_id_int.msg')."\n");
				exit;
			}
			foreach ($tlangs as $tlang){
				if (t3lib_div::testInt($tlang)===FALSE) {
					$this->cli_echo($lang->getLL('error.target_language_id_integer.msg')."\n");
					exit;
				}
            			$msg.= $this->exportCATXML($l10ncfg,$tlang);
			}
		}
        } elseif ($format == 'EXCEL') {
            $msg.= "Not yet implemented!";
        }
	// Send email notification if set

	$time_end = microtime(true);
	$time = $time_end - $time_start;
	$this->cli_echo($msg."\n".$time."\n");
    }

    /**
    * exportCATXML which is called over cli
    *
    */
    function exportCATXML($l10ncfg,$tlang){

	global $lang;

	$error = "";

	// Load the configuration
	$this->loadExtConf();

	$l10nmgrCfgObj = t3lib_div::makeInstance( 'tx_l10nmgr_l10nConfiguration' );
	$l10nmgrCfgObj->load($l10ncfg);
	if ($l10nmgrCfgObj->isLoaded()) {

		$l10nmgrXML = t3lib_div::makeInstanceClassName( 'tx_l10nmgr_CATXMLView' );
		$l10nmgrGetXML=new $l10nmgrXML($l10nmgrCfgObj,$tlang);

		$onlyChanged = isset($this->cli_args['--updated']) ? $this->cli_args['--updated'][0] : 'FALSE';
		if ($onlyChanged === "TRUE") {
			$l10nmgrGetXML->setModeOnlyChanged();
	        }
		$hidden = isset($this->cli_args['--hidden']) ? $this->cli_args['--hidden'][0] : 'FALSE';
		if ($hidden === "TRUE") {
			$GLOBALS['BE_USER']->uc['moduleData']['xMOD_tx_l10nmgr_cm1']['noHidden']=TRUE;
			$l10nmgrGetXML->setModeNoHidden();
		}
		//Check the export
		//if ((t3lib_div::_POST('check_exports')=='1') && ($viewClass->checkExports() == FALSE)) {
		//	$info .= '<br />'.$this->doc->icons(2).$LANG->getLL('export.process.duplicate.message');
		//	$info .= $viewClass->renderExports();
		//} else {
			//$viewClass->saveExportInformation();
		//}
		$xmlFileName = PATH_site . 'uploads/tx_l10nmgr/jobs/out/' . $l10nmgrGetXML->getFileName();
		$xmlContent = $l10nmgrGetXML->render();
		$writeXmlFile = t3lib_div::writeFile($xmlFileName, $xmlContent );

		// If email notification is set send export files to responsable translator
		if ($this->lConf['enable_notification'] == 1) {
			if (empty($this->lConf['email_recipient'])){
				$this->cli_echo($lang->getLL('error.email.repient_missing.msg')."\n");
			} else {
				$this->emailNotification($xmlFileName,$l10nmgrCfgObj,$tlang);
			}
		} else {
			$this->cli_echo($lang->getLL('error.email.notification_disabled.msg')."\n");
		}

		// If FTP option is set upload files to remote server
		if ($this->lConf['enable_ftp'] == 1) {
			if (file_exists($xmlFileName)) {
				$error.= $this->ftpUpload($xmlFileName,$l10nmgrGetXML->getFileName());
			} else {
				$this->cli_echo($lang->getLL('error.ftp.file_not_found.msg')."\n");
			}
		} else {
			$this->cli_echo($lang->getLL('error.ftp.disabled.msg')."\n");
		}

		//$removeXmlFile = t3lib_div::unlink_tempfile($xmlFileName);
	} else {
		$error.= $lang->getLL('error.l10nmgr.object_not_loaded.msg')."\n";
	}

    	return($error);
    }

    /**
     * The function emailNotification sends an email with a translation job to the recipient specified in the extension config.
     * @return      string	Error message
     *
     */
    function emailNotification($xmlFileName,$l10nmgrCfgObj,$tlang) {

	global $lang;

	    // Get source & target language ISO codes
	$sourceStaticLangArr = t3lib_BEfunc::getRecord('static_languages',$l10nmgrCfgObj->l10ncfg['sourceLangStaticId'],'lg_iso_2');
	$targetStaticLang = t3lib_BEfunc::getRecord('sys_language',$tlang,'static_lang_isocode');
	$targetStaticLangArr = t3lib_BEfunc::getRecord('static_languages',$targetStaticLang['static_lang_isocode'],'lg_iso_2');
	$sourceLang = $sourceStaticLangArr['lg_iso_2'];
	$targetLang = $targetStaticLangArr['lg_iso_2'];

             // Construct email message
       	$email = t3lib_div::makeInstance('t3lib_htmlmail');
       	$email->start();
       	$email->useQuotedPrintable();

	$email->subject = sprintf($lang->getLL('email.suject.msg'),$sourceLang,$targetLang,$GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']);
        if ( empty( $GLOBALS['BE_USER']->user['email'] ) || empty( $GLOBALS['BE_USER']->user['realName'] ) ) {
        	$email->from_email = $this->lConf['email_sender'];
	       	$email->from_name = $this->lConf['email_sender_name'];
                $email->replyto_email = $this->lConf['email_sender'];
                $email->replyto_name = $this->lConf['email_sender_name'];
       	}else{
                $email->from_email = $GLOBALS['BE_USER']->user['email'];
              	$email->from_name = $GLOBALS['BE_USER']->user['realName'];
       	        $email->replyto_email = $GLOBALS['BE_USER']->user['email'];
                $email->replyto_name = $GLOBALS['BE_USER']->user['realName'];
        }
        $email->organisation = $this->lConf['email_sender_organisation'];

	$message = array(
		'msg1' => $lang->getLL('email.greeting.msg'),
		'msg2' => '',
		'msg3' => sprintf($lang->getLL('email.new_translation_job.msg'),$sourceLang,$targetLang,$GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']),
		'msg4' => $lang->getLL('email.info.msg'),
		'msg5' => $lang->getLL('email.info.import.msg'),
		'msg6' => '',
		'msg7' => $lang->getLL('email.goodbye.msg'),
		'msg8' => $email->from_name,
		'msg9' => '--',
		'msg10' => $lang->getLL('email.info.exportef_file.msg'),
		'msg11' => $xmlFileName,
	);
	if ($this->lConf['email_attachment']) {
		$message['msg3'] = sprintf($lang->getLL('email.new_translation_job_attached.msg'),$sourceLang,$targetLang,$GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']);
	}
	$msg = implode( chr(10), $message );

	$email->addPlain($msg);
	if ($this->lConf['email_attachment']) {
		$email->addAttachment($xmlFileName);
	}
	$email->send( $this->lConf['email_recipient'] );
    }

    /**
     * The function ftpUpload puts an export on a remote FTP server for further processing
     * @return      string	Error message
     *
     */
    function ftpUpload($xmlFileName,$filename) {

	global $lang;

	$connection = ftp_connect($this->lConf['ftp_server']) or die("Connection failed");
	if ($connection) {
		if (@ftp_login($connection, $this->lConf['ftp_server_username'], $this->lConf['ftp_server_password'])) {
			if(ftp_put($connection, $this->lConf['ftp_server_path'].$filename, $xmlFileName, FTP_BINARY) or die("Transfer failed")) {
				ftp_close($connection) or die("Couldn't close connection");
			} else {
				$error.= sprintf($lang->getLL('error.ftp.connection.msg'),$this->lConf['ftp_server_path'],$filename)."\n";
			}
		} else {
			$error.= sprintf($lang->getLL('error.ftp.connection_user.msg'),$this->lConf['ftp_server_username'])."\n";
			ftp_close($connection) or die("Couldn't close connection");
		}
	} else {
			$error.= $lang->getLL('error.ftp.connection_failed.msg');
	}
	return $error;
    }

    /**
     * The function loadExtConf loads the extension configuration.
     * @return      void
     *
     */
    function loadExtConf() {
          // Load the configuration
         $this->lConf = unserialize( $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['l10nmgr'] );
    }


}

// Call the functionality
$cleanerObj = t3lib_div::makeInstance('tx_cliexport_cli');
$cleanerObj->cli_main($_SERVER['argv']);


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/l10nmgr/cli/cli.export.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/l10nmgr/cli/cli.export.php']);
}

?>