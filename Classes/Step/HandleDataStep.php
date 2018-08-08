<?php
namespace Cobweb\ExternalImport\Step;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Cobweb\ExternalImport\DataHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * This step takes the raw data from the "read" step and makes it into a structured
 * array, ready for further processing.
 *
 * @package Cobweb\ExternalImport\Step
 */
class HandleDataStep extends AbstractStep
{
    /**
     * @var \Cobweb\ExternalImport\Handler\ArrayHandler
     */
    protected $arrayHandler;

    /**
     * @var \Cobweb\ExternalImport\Handler\XmlHandler
     */
    protected $xmlHandler;

    public function injectArrayHandler(\Cobweb\ExternalImport\Handler\ArrayHandler $handler)
    {
        $this->arrayHandler = $handler;
    }

    public function injectXmlHander(\Cobweb\ExternalImport\Handler\XmlHandler $handler)
    {
        $this->xmlHandler = $handler;
    }

    /**
     * Maps the external data to TCA fields.
     *
     * @return void
     */
    public function run()
    {
        $ctrlConfiguration = $this->configuration->getCtrlConfiguration();
        $originalData = $this->getData()->getRawData();
        // Check for custom data handlers
        if (!empty($ctrlConfiguration['dataHandler'])) {
            try {
                /** @var $dataHandler DataHandlerInterface */
                $dataHandler = GeneralUtility::makeInstance($ctrlConfiguration['dataHandler']);
                if ($dataHandler instanceof DataHandlerInterface) {
                    $records = $dataHandler->handleData(
                            $originalData,
                            $this->importer
                    );
                } else {
                    $this->abortFlag = true;
                    LocalizationUtility::translate(
                            'LLL:EXT:external_import/Resources/Private/Language/ExternalImport.xlf:invalidCustomHandler',
                            'external_import',
                            array($ctrlConfiguration['dataHandler'])
                    );
                    return;
                }
            }
            catch (\Exception $e) {
                $this->abortFlag = true;
                LocalizationUtility::translate(
                        'LLL:EXT:external_import/Resources/Private/Language/ExternalImport.xlf:wrongCustomHandler',
                        'external_import',
                        array($ctrlConfiguration['dataHandler'])
                );
                return;
            }

        // Use default handlers
        } else {

            // Prepare the data, depending on result type
            switch ($ctrlConfiguration['data']) {
                case 'xml':
                    $records = $this->xmlHandler->handleData(
                            $originalData,
                            $this->importer
                    );
                    break;
                case 'array':
                    $records = $this->arrayHandler->handleData(
                            $originalData,
                            $this->importer
                    );
                    break;

                // This should really not happen
                default:
                    $records = $originalData;
                    break;
            }
        }

        // Apply any existing pre-processing hook to the raw data
        $records = $this->preprocessRawData($records);

        // Set the records in the Data object (and also as preview, if activated)
        $this->getData()->setRecords($records);
        $this->setPreviewData($records);
    }

    /**
     * Applies any existing pre-processing to the data before it moves on to the next step.
     *
     * Note that this method does not do anything by itself. It just calls on a pre-processing hook.
     *
     * @param array $records Records containing the mapped data
     * @return array
     */
    protected function preprocessRawData($records)
    {
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['external_import']['preprocessRawRecordset'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['external_import']['preprocessRawRecordset'] as $className) {
                try {
                    $preProcessor = GeneralUtility::makeInstance($className);
                    $records = $preProcessor->preprocessRawRecordset($records, $this->importer);
                    // Compact the array again, in case some values were unset in the pre-processor
                    $records = array_values($records);
                } catch (\Exception $e) {
                    $this->importer->debug(
                            sprintf(
                                    'Could not instantiate class %s for hook %s',
                                    $className,
                                    'preprocessRawRecordset'
                            ),
                            1
                    );
                }
            }
        }
        return $records;
    }
}