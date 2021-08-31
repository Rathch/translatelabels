<?php

namespace Sitegeist\Translatelabels\Hooks;

/**
 *
 * This file is part of the "translatelabels" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 *
 */

use PHPUnit\Runner\Exception;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Sitegeist\Translatelabels\Utility\TranslationLabelUtility;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Class TypoScriptFrontendController
 * Used for Hook $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['contentPostProc-output']
 *
 * @package Sitegeist\Translatelabels\Hooks
 */
class TypoScriptFrontendController
{
    /**
     * Search for LLL:("<translation>","<ke>") tags and replace them with a tooltip markup
     * but only if a be user is logged in
     *
     * @param array $params
     * @param \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController $ref
     * @throws \TYPO3\CMS\Backend\Exception
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     */
    public function contentPostProcAll(
        array &$params,
        \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController $ref
    ) {
        // ini_set("pcre.backtrack_limit", "-1");

        // single quote ' is escaped in fluid inside of tag attributes to &#039;
        // so we have to look out for single quotes as either ' or &#039;
        // a regex group starting with (?: instead of ( is a non-capturing group
        $quote = '(?:&#039;|\')'; // delimiter for parameters => has to be same as defined in typo3conf/ext/translatelabels/Classes/Utility/TranslationLabelUtility.php:107
        // var_dump('FE with logged-in BE user:' . (TranslationLabelUtility::isFrontendWithLoggedInBEUser() ? 'TRUE' : 'FALSE'));
        if (TranslationLabelUtility::isFrontendWithLoggedInBEUser()) {
            $sysFolderWithTranslationsUid = TranslationLabelUtility::getStoragePid();
            if ($sysFolderWithTranslationsUid === null) {
                throw new Exception('TSFE.constants.plugin.tx_translatelabels.settings.storagePid not set in page TSconfig', 1543243652);
            }
            // store all labels in T3_VAR to show them in admin panel later on
            $search = '/LLL:\(' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . '\)/si';
            if (preg_match_all($search, $ref->content, $matches)) {
                $countLabels = \count($matches[0]);
                $keys = [];
                for ($i = 0; $i < $countLabels; $i++) {
                    if (!in_array($matches[2][$i], $keys)) { // each label/key only once!
                        $GLOBALS['T3_VAR']['ext']['translatelabels']['labels'][] = [
                            'key' => $matches[2][$i],
                            'identifier' => $this->getIdentifier($matches[2][$i]),
                            'file' => $this->getFilePart($matches[2][$i]),
                            'value' => html_entity_decode($matches[1][$i]),
                            'overrides' => $this->getOverrides(
                                $sysFolderWithTranslationsUid,
                                $matches[2][$i],
                                $matches[3][$i],
                                $matches[4][$i],
                                $matches[5][$i]
                            ),
                            'linkToBE' => (string) $this->getLinkToBE(
                                $sysFolderWithTranslationsUid,
                                $matches[2][$i],
                                $matches[1][$i]
                            )
                        ];
                    }
                    $keys[] = $matches[2][$i];
                }
            }
            // step 1
            // replace labels outside of html tags only but not inside tag attributes
            // find <div>...LLL:...</div> but not <div data="...LLL:..."></div>

            // (?![^<]*>) at the end of this regex means negative lookahead for a closing >
            // with optional characters before
            $search = '/(LLL:\(' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . '\))(?![^<]*>)/i';
            $message = $ref->sL('LLL:EXT:translatelabels/Resources/Private/Language/locallang_adminpanel.xlf:tooltip.message');
            $ref->content = preg_replace_callback(
                $search,
                function ($matches) use ($sysFolderWithTranslationsUid, $message) {
                    $uri = $this->getLinkToBE($sysFolderWithTranslationsUid, $matches[3], $matches[2]);
                    return $this->renderLabelAsTooltip(
                        $matches[3], // key
                        $matches[2], // translation string
                        $uri,
                        $message
                    );
                },
                $ref->content
            );
            if($ref->content === null) {
                throw new \Sitegeist\Translatelabels\Exception(preg_last_error_msg(), preg_last_error());
            }

            // step 2
            // find all labels inside of tag attributes
            // seach for all tags with at least one LLL marker and add a data attribute
            // 'data-translatelabel-attributes' with a json array with translations for each attribute containing a LLL: marker
            $search = '/(<[^>]*?)(LLL:\(' . $quote . '.*?' . $quote . ',' . $quote . '.*?' . $quote . ',' . $quote . '.*?' . $quote . ',' . $quote . '.*?' . $quote . ',' . $quote . '.*?' . $quote . '\))(.*?)(\/?>)/si';
            $message = $ref->sL('LLL:EXT:translatelabels/Resources/Private/Language/locallang_adminpanel.xlf:tooltip.message');
            $ref->content = preg_replace_callback(
                $search,
                // iterate through each tag containing at least one LLL marker, f.e. '<img/>'
                function ($matches) use ($sysFolderWithTranslationsUid, $message, $quote) {
                    $search = '/([\w-]+)\s*=\s*"LLL:\(' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . ',' . $quote . '(.*?)' . $quote . '\)"/si';
                    $attributesToTranslate = [];
                    // iterate trough each attribute containing a LLL marker, f.e. 'alt', 'placeholder'
                    $innerContent = preg_replace_callback(
                        $search,
                        function ($matches) use ($message, &$attributesToTranslate) {
                            $attributesToTranslate[] = [
                                'attribute' => trim($matches[1]),
                                'key' => trim($matches[3]),
                                'identifier' => $this->getIdentifier(trim($matches[3])),
                                'translation' => $matches[2]
                            ];
                            return $this->renderLabelAsTooltipInsideTags(
                                $matches[1], // name of attribute, f.e. 'alt' or 'value'
                                $matches[3], // key
                                $matches[2], // translation string
                                $message
                            );
                        },
                        $matches[1] . $matches[2] . $matches[3]
                    );
                    return $innerContent . ' data-translatelabels-role="translations-in-attributes" data-translatelabel-attributes="' . htmlentities(json_encode($attributesToTranslate)) . '" ' . $matches[4];
                },
                $ref->content
            );

            // search for the rest of LLL markers, these are all outside of html tags
            if($ref->content === null) {
                throw new \Sitegeist\Translatelabels\Exception(preg_last_error_msg(), preg_last_error());
            }
        }
    }

    protected function getIdentifier(string $labelKey)
    {
        return substr(strrchr($labelKey, ':'), 1);
    }

    protected function getFilePart(string $labelKey)
    {
        if (strpos($labelKey, 'LLL:') === 0) {
            $labelKey = substr($labelKey, 4);
        }
        if (strpos($labelKey, 'EXT:') === 0) {
            $labelKey = substr($labelKey, 4);
        }
        $filePart = substr($labelKey, 0, strrpos($labelKey, ':'));
        return $filePart === '' ? $labelKey : $filePart;
    }

    protected function renderLabelAsTooltip($key, $translationString, $uri, string $message = 'Translate label \'%s\'...', $target = 'backend')
    {
        return '<span class="translatelabels-tooltip">'
            . '<span class="translatelabels-translation" data-translatelabels-role="translation" data-translatelabels-key="' . $key .'">' . $translationString . '</span>'
            . '<span style="display: none;" class="translatelabels-tooltip-inner"><span data-translatelabels-link="' . $key . '">' . vsprintf($message, [ $this->getIdentifier($key) ]) . '</span></span>'
            . '</span>';
    }

    protected function renderLabelAsTooltipInsideTags($attributeName, $key, $translationString, string $message = 'Translate label \'%s\'...')
    {
        return $attributeName . '="' . $translationString . ' (LABEL: ' . htmlentities( $this->getIdentifier($key)) . ')"';
    }

    /**
     * returns an uri to the backend module defined by $route, f.e. 'web_list' with
     * the given parameters
     *
     * @param $route
     * @param $urlParameters
     * @return \TYPO3\CMS\Core\Http\Uri
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function getLinkToBEModule($route, $urlParameters)
    {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        try {
            $uri = $uriBuilder->buildUriFromRoute($route, $urlParameters);
        } catch (\TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException $e) {
            $uri = $uriBuilder->buildUriFromRoutePath($route, $urlParameters);
        }
        return $uri;
    }

    /**
     * Fetches current site language from site configuration / language / typo3Language or
     * TypoScript config.language
     *
     * @return string key of current system language, f.e. 'default' or 'de'
     */
    protected function getCurrentLanguageKey()
    {
        $siteLanguage = null;
        if (isset($GLOBALS['TYPO3_REQUEST'])
            && $GLOBALS['TYPO3_REQUEST'] instanceof ServerRequestInterface
            && $GLOBALS['TYPO3_REQUEST']->getAttribute('language') instanceof SiteLanguage) {
            $siteLanguage = $GLOBALS['TYPO3_REQUEST']->getAttribute('language');
        }
        if ($siteLanguage) {
            $languageKey = $siteLanguage->getTypo3Language();
        } else {
            $languageKey = $this->config['config']['language'] ?? 'default';
        }
        return $languageKey;
    }

    /**
     * returns array of translation overrides for a label
     *
     * @param int $pid
     * @param string $labelKey
     * @param string $extensionName
     * @param string|null $optionalLanguageKey
     * @param string|null $optionalAlternativeLanguageKeys
     * @return array
     */
    protected function getOverrides(int $pid, string $labelKey, string $extensionName, string $optionalLanguageKey = null, string $optionalAlternativeLanguageKeys = null)
    {
        $reversedParts = explode(':', strrev($labelKey), 2);
        $languageFile = strrev($reversedParts[1]);
        $labelName = strrev($reversedParts[0]);
        $overrides[] = ['Xliff File', $languageFile];
        $locallangXMLOverride = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'];
        $languageKey = $this->getCurrentLanguageKey();
        if (isset($locallangXMLOverride[$languageKey][$languageFile]) &&
            is_array($locallangXMLOverride[$languageKey][$languageFile])
        ) {
            foreach ($locallangXMLOverride[$languageKey][$languageFile] as $override) {
                $overrides[] = ['Xliff File', $override];
            }
        }
        $pluginName = (strpos($extensionName, 'tx_') === 0) ? $extensionName : 'tx_' . strtolower($extensionName);
        if (isset($GLOBALS['TSFE']->tmpl->setup['plugin.'][$pluginName . '.']['_LOCAL_LANG.'][$languageKey . '.']) &&
            is_array($GLOBALS['TSFE']->tmpl->setup['plugin.'][$pluginName . '.']['_LOCAL_LANG.'][$languageKey . '.'])) {
            $typoScriptOverride = ArrayUtility::flatten(
                $GLOBALS['TSFE']->tmpl->setup['plugin.'][$pluginName . '.']['_LOCAL_LANG.'][$languageKey . '.'],
                '.'
            );
        }
        if ($typoScriptOverride['.' . $labelName]) {
            $overrides[] = ['TypoScript' , 'plugin.' . $pluginName . '._LOCAL_LANG.' . $languageKey . '.' . $labelName];
        }
        if (TranslationLabelUtility::getLabelFromDatabase($labelKey, $pid) !== null) {
            $overrides[] = ['Translation record', $labelKey];
        }
        return $overrides;
    }

    /**
     * Generates link to edit translation records in backend
     * Works in conjunction with Sitegeist\Translatelabels\Middleware\CreateLabelResolver which
     * does some magic to automatically create missing translations records in default language and prepares
     * localizations
     *
     * @param $sysFolderWithTranslationsUid
     * @param $translation
     * @param $key
     * @param $sysLanguageUid
     * @return \TYPO3\CMS\Core\Http\Uri
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function getLinkToEditTranslation($sysFolderWithTranslationsUid, $translation, $key, $sysLanguageUid)
    {
        $uri = $this->getLinkToBEModule('record_edit', [
            'id' => $sysFolderWithTranslationsUid,
            'tx_translatelabels[key]' => $key,
            'tx_translatelabels[language]' => $sysLanguageUid,
            'tx_translatelabels[pid]' => $sysFolderWithTranslationsUid,
            'tx_translatelabels[translation]' => $translation
        ]);
        return $uri;
    }

    /**
     * returns link to edit/create translation record in BE
     *
     * @param $sysFolderWithTranslationsUid
     * @param $key
     * @param $translationString
     * @return \TYPO3\CMS\Core\Http\Uri
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     */
    public function getLinkToBE($sysFolderWithTranslationsUid, $key, $translationString)
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $sysLanguageUid = $context->getPropertyFromAspect('language', 'id');
        $uri = $this->getLinkToEditTranslation(
            $sysFolderWithTranslationsUid,
            $translationString,
            $key,
            $sysLanguageUid
        );
        return $uri;
    }
}
