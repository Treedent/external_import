<?php

namespace Cobweb\ExternalImport\Utility;

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

use Cobweb\ExternalImport\Domain\Model\Log;
use Cobweb\ExternalImport\Domain\Repository\LogRepository;
use Cobweb\ExternalImport\Importer;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * This class performs various reporting actions after a data import has taken place.
 *
 * @package Cobweb\ExternalImport\Utility
 */
class ReportingUtility
{
    /**
     * @var Importer Back-reference to the calling instance
     */
    protected $importer;

    /**
     * @var array Extension configuration
     */
    protected $extensionConfiguration = [];

    /**
     * @var array List of arbitrary values reported by different steps in the process
     */
    protected $reportingValues = [];

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var LogRepository
     */
    protected $logRepository;

    /**
     * @var PersistenceManager
     */
    protected $persistenceManager;

    public function injectObjectManager(\TYPO3\CMS\Extbase\Object\ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    public function injectLogRepository(\Cobweb\ExternalImport\Domain\Repository\LogRepository $logRepository)
    {
        $this->logRepository = $logRepository;
    }

    public function injectPersistenceManager(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager $persistenceManager) {
        $this->persistenceManager = $persistenceManager;
    }

    /**
     * Sets a back-reference to the Importer object.
     *
     * @param Importer $importer
     * @return void
     */
    public function setImporter(Importer $importer)
    {
        $this->importer = $importer;
        $this->extensionConfiguration = $importer->getExtensionConfiguration();
    }

    /**
     * Stores the messages to the devLog.
     *
     * @return void
     */
    public function writeToDevLog()
    {
        if ($this->importer->isDebug()) {
            $messages = $this->importer->getMessages();

            // Define a global severity based on the highest issue level reported
            $severity = -1;
            if (count($messages[FlashMessage::ERROR]) > 0) {
                $severity = 3;
            } elseif (count($messages[FlashMessage::WARNING]) > 0) {
                $severity = 2;
            }

            // Log all the messages in one go
            GeneralUtility::devLog(
                    LocalizationUtility::translate(
                            'LLL:EXT:external_import/Resources/Private/Language/ExternalImport.xlf:sync_table',
                            'external_import',
                            $this->importer->getExternalConfiguration()->getTable()
                    ),
                    'external_import',
                    $severity,
                    $messages
            );
        }
    }

    /**
     * Stores the messages to the external_import log.
     *
     * @return void
     */
    public function writeToLog()
    {
        // Don't log in preview mode
        if (!$this->importer->isPreview()) {
            $messages = $this->importer->getMessages();
            $context = $this->importer->getContext();
            foreach ($messages as $status => $messageList) {
                foreach ($messageList as $message) {
                    /** @var Log $logEntry */
                    $logEntry = $this->objectManager->get(Log::class);
                    $logEntry->setPid($this->extensionConfiguration['logStorage']);
                    $logEntry->setStatus($status);
                    $logEntry->setCrdate(
                            new \DateTime('@' . $GLOBALS['EXEC_TIME'])
                    );
                    $logEntry->setCruserId(
                            $this->getBackendUser()->user['uid'] ?? 0
                    );
                    $logEntry->setConfiguration(
                            $this->importer->getExternalConfiguration()->getTable() . ' / ' . $this->importer->getExternalConfiguration()->getIndex()
                    );
                    $logEntry->setContext($context);
                    $logEntry->setMessage($message);
                    try {
                        $this->logRepository->add($logEntry);
                    }
                    catch (\Exception $e) {
                        // Nothing to do
                    }
                }
            }
            // Make sure the entries are persisted (this will not happen automatically
            // when called from the command line)
            $this->persistenceManager->persistAll();
        }
    }

    /**
     * Assembles a synchronization report for a given table/index.
     *
     * @param string $table Name of the table
     * @param integer $index Number of the synchronisation configuration
     * @param array $messages List of messages for the given table
     * @return string Formatted text of the report
     */
    public function reportForTable($table, $index, $messages)
    {
        $languageObject = $this->getLanguageObject();
        $report = sprintf(
                        $languageObject->sL('LLL:EXT:external_import/Resources/Private/Language/ExternalImport.xlf:synchronizeTableX'),
                        $table,
                        $index
                ) . "\n";
        foreach ($messages as $type => $messageList) {
            $report .= $languageObject->sL('LLL:EXT:external_import/Resources/Private/Language/ExternalImport.xlf:label.' . $type) . "\n";
            if (count($messageList) === 0) {
                $report .= "\t" . $languageObject->sL('LLL:EXT:external_import/Resources/Private/Language/ExternalImport.xlf:no.' . $type) . "\n";
            } else {
                foreach ($messageList as $aMessage) {
                    $report .= "\t- " . $aMessage . "\n";
                }
            }
        }
        $report .= "\n\n";
        return $report;
    }

    /**
     * Sends a reporting mail to the configured e-mail address.
     *
     * @param string $subject Subject of the mail
     * @param string $body Text body of the mail
     * @return void
     */
    public function sendMail($subject, $body)
    {
        $result = 0;
        // Define sender mail and name
        $senderMail = '';
        $senderName = '';
        $backendUser = $this->getBackendUser();
        if (!empty($backendUser->user['email'])) {
            $senderMail = $backendUser->user['email'];
            if (empty($backendUser->user['realName'])) {
                $senderName = $backendUser->user['username'];
            } else {
                $senderName = $backendUser->user['realName'];
            }
        } elseif (!empty($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'])) {
            $senderMail = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'];
            if (empty($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'])) {
                $senderName = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'];
            } else {
                $senderName = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'];
            }
        }
        // If no mail could be found, avoid sending the mail
        // The message will be logged as an error
        if (empty($senderMail)) {
            $message = 'No sender mail defined. Please check the manual.';

            // Proceed with sending the mail
        } else {
            // Instantiate and initialize the mail object
            /** @var $mailObject MailMessage */
            $mailObject = GeneralUtility::makeInstance(MailMessage::class);
            try {
                $sender = [
                        $senderMail => $senderName
                ];
                $mailObject->setFrom($sender);
                $mailObject->setReplyTo($sender);
                $mailObject->setTo(
                        [
                                $this->extensionConfiguration['reportEmail']
                        ]
                );
                $mailObject->setSubject($subject);
                $mailObject->setBody($body);
                // Send mail
                $result = $mailObject->send();
                $message = '';
            } catch (\Exception $e) {
                $message = $e->getMessage() . '[' . $e->getCode() . ']';
            }
        }

        // Report error in log, if any
        if ($result === 0) {
            $comment = 'Reporting mail could not be sent to ' . $this->extensionConfiguration['reportEmail'];
            if (!empty($message)) {
                $comment .= ' (' . $message . ')';
            }
            $backendUser->writelog(
                    4,
                    0,
                    1,
                    'external_import',
                    $comment,
                    []
            );
        }
    }

    /**
     * @param string $step Name of the step (class)
     * @param string $key Name of the key
     * @param mixed $value Value to store
     * @return void
     */
    public function setValueForStep(string $step, string $key, $value)
    {
        if (!array_key_exists($step, $this->reportingValues)) {
            $this->reportingValues[$step] = [];
        }
        $this->reportingValues[$step][$key] = $value;
    }

    /**
     * @param string $step Name of the step (class)
     * @param string $key Name of the key
     * @return mixed
     * @throws \Cobweb\ExternalImport\Exception\UnknownReportingKeyException
     */
    public function getValueForStep(string $step, string $key)
    {
        if (isset($this->reportingValues[$step][$key])) {
            return $this->reportingValues[$step][$key];
        }
        throw new \Cobweb\ExternalImport\Exception\UnknownReportingKeyException(
                sprintf(
                        'No value found for step "%1$s" and key "%2$s"',
                        $step,
                        $key
                ),
                1530635849

        );
    }

    /**
     * Returns the global language object.
     *
     * @return \TYPO3\CMS\Lang\LanguageService
     */
    protected function getLanguageObject()
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Returns the BE user data.
     *
     * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }
}