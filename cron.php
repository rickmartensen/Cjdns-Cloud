<?php
/**
 * ownCloud
 *
 * @author Jakob Sack
 * @copyright 2012 Jakob Sack owncloud@jakobsack.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

// Unfortunately we need this class for shutdown function
class TemporaryCronClass {
	public static $sent = false;
	public static $lockfile = "";
	public static $keeplock = false;
}

// We use this function to handle (unexpected) shutdowns
function handleUnexpectedShutdown() {
	// Delete lockfile
	if (!TemporaryCronClass::$keeplock && file_exists(TemporaryCronClass::$lockfile)) {
		unlink(TemporaryCronClass::$lockfile);
	}

	// Say goodbye if the app did not shutdown properly
	if (!TemporaryCronClass::$sent) {
		if (OC::$CLI) {
			echo 'Unexpected error!' . PHP_EOL;
		} else {
			OC_JSON::error(array('data' => array('message' => 'Unexpected error!')));
		}
	}
}

require_once 'lib/base.php';

session_write_close();

// Don't do anything if ownCloud has not been installed
if (!OC_Config::getValue('installed', false)) {
	exit(0);
}

// Handle unexpected errors
register_shutdown_function('handleUnexpectedShutdown');

// Delete temp folder
OC_Helper::cleanTmpNoClean();

// Exit if background jobs are disabled!
$appmode = OC_BackgroundJob::getExecutionType();
if ($appmode == 'none') {
	TemporaryCronClass::$sent = true;
	if (OC::$CLI) {
		echo 'Background Jobs are disabled!' . PHP_EOL;
	} else {
		OC_JSON::error(array('data' => array('message' => 'Background jobs disabled!')));
	}
	exit(1);
}

if (OC::$CLI) {
	// Create lock file first
	TemporaryCronClass::$lockfile = OC_Config::getValue("datadirectory", OC::$SERVERROOT . '/data') . '/cron.lock';

	// We call ownCloud from the CLI (aka cron)
	if ($appmode != 'cron') {
		// Use cron in feature!
		OC_BackgroundJob::setExecutionType('cron');
	}

	// check if backgroundjobs is still running
	if (file_exists(TemporaryCronClass::$lockfile)) {
		TemporaryCronClass::$keeplock = true;
		TemporaryCronClass::$sent = true;
		echo "Another instance of cron.php is still running!";
		exit(1);
	}

	// Create a lock file
	touch(TemporaryCronClass::$lockfile);

	// Work
	$jobList = new \OC\BackgroundJob\JobList();
	$jobs = $jobList->getAll();
	foreach ($jobs as $job) {
		$job->execute($jobList);
	}
} else {
	// We call cron.php from some website
	if ($appmode == 'cron') {
		// Cron is cron :-P
		OC_JSON::error(array('data' => array('message' => 'Backgroundjobs are using system cron!')));
	} else {
		// Work and success :-)
		$jobList = new \OC\BackgroundJob\JobList();
		$job = $jobList->getNext();
		$job->execute($jobList);
		$jobList->setLastJob($job);
		OC_JSON::success();
	}
}

// done!
TemporaryCronClass::$sent = true;
exit();
