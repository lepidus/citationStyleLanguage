<?php

/**
 * @file plugins/generic/citationStyleLanguage/CitationStyleLanguageHandler.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class CitationStyleLanguageHandler
 * @ingroup plugins_generic_citationstylelanguage
 *
 * @brief Handle router requests for the citation style language plugin
 */

import('classes.handler.Handler');

class CitationStyleLanguageHandler extends Handler {
	/** @var Submission article or preprint being requested */
	public $submission = null;

	/** @var Publication publication being requested */
	public $publication = null;

	/** @var Issue issue being requested */
	public $issue = null;

	/** @var array citation style being requested */
	public $citationStyle = '';

	/** @var bool Whether or not to return citation in JSON format */
	public $returnJson = false;

	/** @var string application-specific submission noun */
	public $submissionNoun = '';

	/**
	 * Get a citation style
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 * @return null|JSONMessage
	 */
	public function get($args, $request) {
		$this->_setupRequest($args, $request);

		$plugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
		$citation = $plugin->getCitation($request, $this->submission, $this->citationStyle, $this->issue, $this->submissionNoun);

		if ($citation === false ) {
			if ($this->returnJson) {
				return new JSONMessage(false);
			}
			exit;
		}

		if ($this->returnJson) {
			return new JSONMessage(true, $citation);
		}

		echo $citation;
		exit;
	}

	/**
	 * Download a citation in a downloadable format
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 */
	public function download($args, $request) {
		$this->_setupRequest($args, $request);

		$plugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
		$plugin->downloadCitation($request, $this->submission, $this->citationStyle, $this->issue, $this->submissionNoun);
		exit;
	}

	/**
	 * Generate a citation based on page parameters
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 */
	public function _setupRequest($args, $request) {
		$userVars = $request->getUserVars();
		$user = $request->getUser();
		$context = $request->getContext();

		if (empty($userVars['submissionId']) || !$context || empty($args)) {
			$request->getDispatcher()->handle404();
		}

		// Load plugin categories which might need to add data to the citation
		PluginRegistry::loadCategory('pubIds', true);
		PluginRegistry::loadCategory('metadata', true);

		$this->citationStyle = $args[0];
		$this->returnJson = isset($userVars['return']) && $userVars['return'] === 'json';
		$this->submission = Services::get('submission')->get($userVars['submissionId']);
		$this->issue = $userVars['issueId'] ? Services::get('issue')->get($userVars['issueId']) : null;
		$this->submissionNoun = $userVars['submissionNoun'];
		
		if (!$this->submission) {
			$request->getDispatcher()->handle404();
		}
		
		$application = Application::get();
		$applicationName = $application->getName();
		
		// Disallow access to unpublished submissions, unless the user is a
		// journal manager or an assigned subeditor or assistant. This ensures the
		// article preview will work for those who can see it.
        if ($applicationName == 'ojs2') {
			if (!$this->issue || !$this->issue->getPublished() || $this->submission->getStatus() != STATUS_PUBLISHED) {
				$userCanAccess = false;
				if ($user && $user->hasRole([ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT], $context->getId())) {
					$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
					$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
					$assignmentsResults = $stageAssignmentDao->getBySubmissionAndStageId($this->submission->getId());
					while ($assignment = $assignmentsResults->next()) {
						if ($assignment->getUserId() !== $user->getId()) continue;
						$userGroup = $userGroupDao->getById($assignment->getUserGroupId($context->getId()));
						if (in_array($userGroup->getRoleId(), [ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT])) {
							$userCanAccess = true;
							break;
						}
					}
				}
				
				if ($user && $user->hasRole(ROLE_ID_MANAGER, $context->getId())) {
					$userCanAccess = true;
				}

				if (!$userCanAccess) {
					$request->getDispatcher()->handle404();
				}
			}
		}
	}
}
