<?php

namespace TYPO3\DirectMailSubscription;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class FormUtility
{
    /**
     * @var ContentObjectRenderer
     */
    public $cObj;
    public $LOCAL_LANG = [];            // Local Language content
    public $LOCAL_LANG_charset = [];    // Local Language content charset for individual labels (overriding)
    public $LOCAL_LANG_loaded = 0;            // Flag that tells if the locallang file has been fetch (or tried to be fetched) already.
    public $LLkey = 'default';                // Pointer to the language to use.
    public $altLLkey = '';                    // Pointer to alternative fall-back language to use.
    public $LLtestPrefix = '';                // You can set this during development to some value that makes it easy for you to spot all labels that ARe delivered by the getLL function.
    public $LLtestPrefixAlt = '';            // Save as LLtestPrefix, but additional prefix for the alternative value in getLL() function calls

    public $scriptRelPath = 'pi/class.dmailsubscribe.php';
    public $extKey = 'direct_mail_subscription';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $this->conf = $this->getTyposcripFrontendController()->tmpl->setup['plugin.']['feadmin.']['dmailsubscription.'];

        if ($this->getTyposcripFrontendController()->config['config']['language']) {
            $this->LLkey = $this->getTyposcripFrontendController()->config['config']['language'];
            if ($this->getTyposcripFrontendController()->config['config']['language_alt']) {
                $this->altLLkey = $this->getTyposcripFrontendController()->config['config']['language_alt'];
            }
        }
        $this->pi_loadLL();
    }

    /**
     * Userfunc called per TS to create categories check boxes
     *
     * @param string $content
     * @param array $conf TS conf
     *
     * @return string
     */
    public function makeCheckboxes($content, $conf)
    {
        $content = '';
        $databaseConnection = $this->getDatabaseConnection();
        $pid = $this->cObj->stdWrap($conf['pid'], $conf['pid.']);

        if ($address_uid = GeneralUtility::_GP('rU')) {
            $res = $databaseConnection->exec_SELECTquery('*', 'sys_dmail_ttaddress_category_mm',
                'uid_local=' . intval($address_uid));
            $subscribed_to = [];
            while ($row = $databaseConnection->sql_fetch_assoc($res)) {
                $subscribed_to[] = $row['uid_foreign'];
            }
            $subscribed_to_list = implode(',', $subscribed_to);
        }

        $res = $databaseConnection->exec_SELECTquery(
            '*',
            'sys_dmail_category',
            'l18n_parent=0 AND pid=' . intval($pid) . $this->cObj->enableFields('sys_dmail_category')
        );

        $i = 1;
        while ($row = $databaseConnection->sql_fetch_assoc($res)) {
            $checked = GeneralUtility::inList($subscribed_to_list, $row['uid']);

            if ($theRow = $this->getTyposcripFrontendController()->sys_page->getRecordOverlay('sys_dmail_category',
                $row,
                $this->getTyposcripFrontendController()->sys_language_uid,
                $conf['hideNonTranslatedCategories'] ? 'hideNonTranslated' : '')
            ) {
                $content .= '<label for="option-' . $i . '">' . htmlspecialchars($theRow['category']) . '</label><input id="option-' . $i . '" type="checkbox" ' . ($checked ? 'checked' : '') . ' name="FE[tt_address][module_sys_dmail_category][' . $row['uid'] . ']" value="1" /><div class="clearall"></div>';
            }
            $i++;
        }

        return $content;
    }

    /**
     * userFunc on save of the record
     *
     * @param $conf
     */
    public function saveRecord($conf)
    {
        $databaseConnection = $this->getDatabaseConnection();

        //check loaded LL
        if (!$this->LOCAL_LANG_loaded) {
            $this->user_dmailsubscribe();
        }

        if (intval($conf['rec']['uid'])) {
            $fe = GeneralUtility::_GP('FE');
            $newFieldsArr = $fe['tt_address']['module_sys_dmail_category'];

            $count = 0;
            $databaseConnection->exec_DELETEquery('sys_dmail_ttaddress_category_mm',
                'uid_local=' . $conf['rec']['uid']);
            if (is_array($newFieldsArr)) {
                foreach (array_keys($newFieldsArr) as $uid) {
                    if (is_numeric($uid)) {
                        $count++;
                        $databaseConnection->exec_INSERTquery(
                            'sys_dmail_ttaddress_category_mm',
                            [
                                'uid_local'   => intval($conf['rec']['uid']),
                                'uid_foreign' => intval($uid),
                                'sorting'     => $count
                            ]
                        );
                    }
                }
                $databaseConnection->exec_UPDATEquery('tt_address', 'uid=' . intval($conf['rec']['uid']),
                    ['module_sys_dmail_category' => $count]);
            }

            if (GeneralUtility::inList('m,f', $conf['rec']['gender'])) {
                $databaseConnection->exec_UPDATEquery(
                    'tt_address',
                    'uid=' . intval($conf['rec']['uid']),
                    [
                        'tx_directmailsubscription_localgender' => $this->pi_getLL('tt_addressGender' . strtoupper($conf['rec']['gender']))
                    ]
                );
            }
        }

        return;
    }

    /**
     * Returns the localized label of the LOCAL_LANG key, $key
     * Notice that for debugging purposes prefixes for the output values can be set with the internal vars ->LLtestPrefixAlt and ->LLtestPrefix
     *
     * @param string $key The key from the LOCAL_LANG array for which to return the value.
     * @param string $alternativeLabel Alternative string to return IF no value is found set for the key, neither for the local language nor the default.
     * @param bool $hsc If TRUE, the output label is passed through htmlspecialchars()
     *
     * @return string The value from LOCAL_LANG.
     */
    public function pi_getLL($key, $alternativeLabel = '', $hsc = false)
    {
        if (isset($this->LOCAL_LANG[$this->LLkey][$key][0]['target'])) {

            // The "from" charset of csConv() is only set for strings from TypoScript via _LOCAL_LANG
            if (isset($this->LOCAL_LANG_charset[$this->LLkey][$key])) {
                $word = $this->getTyposcripFrontendController()->csConv(
                    $this->LOCAL_LANG[$this->LLkey][$key][0]['target'],
                    $this->LOCAL_LANG_charset[$this->LLkey][$key]
                );
            } else {
                $word = $this->LOCAL_LANG[$this->LLkey][$key][0]['target'];
            }
        } elseif ($this->altLLkey && isset($this->LOCAL_LANG[$this->altLLkey][$key][0]['target'])) {

            // The "from" charset of csConv() is only set for strings from TypoScript via _LOCAL_LANG
            if (isset($this->LOCAL_LANG_charset[$this->altLLkey][$key])) {
                $word = $this->getTyposcripFrontendController()->csConv(
                    $this->LOCAL_LANG[$this->altLLkey][$key][0]['target'],
                    $this->LOCAL_LANG_charset[$this->altLLkey][$key]
                );
            } else {
                $word = $this->LOCAL_LANG[$this->altLLkey][$key][0]['target'];
            }
        } elseif (isset($this->LOCAL_LANG['default'][$key][0]['target'])) {

            // Get default translation (without charset conversion, english)
            $word = $this->LOCAL_LANG['default'][$key][0]['target'];
        } else {

            // Return alternative string or empty
            $word = (isset($this->LLtestPrefixAlt)) ? $this->LLtestPrefixAlt . $alternativeLabel : $alternativeLabel;
        }

        $output = (isset($this->LLtestPrefix)) ? $this->LLtestPrefix . $word : $word;

        if ($hsc) {
            $output = htmlspecialchars($output);
        }

        return $output;
    }

    /**
     * Loads local-language values by looking for a "locallang.php" file in the plugin class directory ($this->scriptRelPath) and if found includes it.
     * Also locallang values set in the TypoScript property "_LOCAL_LANG" are merged onto the values found in the "locallang.php" file.
     *
     * @return    void
     */
    public function pi_loadLL()
    {
        if (!$this->LOCAL_LANG_loaded && $this->scriptRelPath) {
            $basePath = 'EXT:' . $this->extKey . '/' . dirname($this->scriptRelPath) . '/locallang.xml';

            // Read the strings in the required charset (since TYPO3 4.2)
            $this->LOCAL_LANG = GeneralUtility::readLLfile($basePath, $this->LLkey,
                $this->getTyposcripFrontendController()->renderCharset);
            if ($this->altLLkey) {
                $this->LOCAL_LANG = GeneralUtility::readLLfile($basePath, $this->altLLkey);
            }

            // Overlaying labels from TypoScript (including fictitious language keys for non-system languages!):
            $confLL = $this->conf['_LOCAL_LANG.'];
            if (is_array($confLL)) {
                foreach ($confLL as $languageKey => $languageArray) {
                    // Don't process label if the langue is not loaded
                    $languageKey = substr($languageKey, 0, -1);
                    if (is_array($languageArray) && is_array($this->LOCAL_LANG[$languageKey])) {
                        // Remove the dot after the language key
                        foreach ($languageArray as $labelKey => $labelValue) {
                            if (!is_array($labelValue)) {
                                $this->LOCAL_LANG[$languageKey][$labelKey][0]['target'] = $labelValue;

                                // For labels coming from the TypoScript (database) the charset is assumed to be "forceCharset"
                                // and if that is not set, assumed to be that of the individual system languages
                                if ($GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset']) {
                                    $this->LOCAL_LANG_charset[$languageKey][$labelKey] = $GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset'];
                                } else {
                                    $this->LOCAL_LANG_charset[$languageKey][$labelKey] = $this->getTyposcripFrontendController()->csConvObj->charSetArray[$languageKey];
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->LOCAL_LANG_loaded = 1;
    }

    /**
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * @return \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
     */
    protected function getTyposcripFrontendController()
    {
        return $GLOBALS['TSFE'];
    }
}
