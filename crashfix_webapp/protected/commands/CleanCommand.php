<?php

class CleanCommand extends CConsoleCommand
{
	public function getOptionHelp()
	{
		$help = "project=<name> appver=<ver> [limit=<number>]";

		$options = array();
		$options[] = $help;
		return $options;
	}

	public function run($args)
	{
		Yii::log("Entering the method run()", "info");
		echo "Entering the method run()\n";

		// dump memory start
		if (extension_loaded('tideways_xhprof')) {
			tideways_xhprof_enable(
				TIDEWAYS_XHPROF_FLAGS_MEMORY_MU |
					TIDEWAYS_XHPROF_FLAGS_MEMORY_PMU |
					TIDEWAYS_XHPROF_FLAGS_MEMORY_ALLOC
				//TIDEWAYS_XHPROF_FLAGS_MEMORY_ALLOC_AS_MU
			);
		}

		//echo "cmdline args = " . var_export($args, TRUE) . "\n";

		$keys = ["project", "appver", "limit"];
		$options = [];
		foreach ($args as $arg) {
			parse_str($arg, $output);

			foreach ($keys as $key) {
				if (array_key_exists($key, $output) && $output[$key] != "")
					$options[$key] = $output[$key];
			}
		}
		//echo "parsed options = " . var_export($options, TRUE) . "\n";

		if (array_key_exists("project", $options))
			$projectName = $options["project"];
		else
			throw new Exception('project name not found in parameters');

		if (array_key_exists("appver", $options))
			$appVer = $options["appver"];
		else
			throw new Exception('appver not found in parameters');

		$limit = 0;
		if (array_key_exists("limit", $options))
			$limit = intval($options["limit"]);
		if ($limit == 0)
			$limit = 10;
		echo "\nInput options: \n";
		echo "projectName = " . $projectName . "\n";
		echo "appver = " . $appVer . "\n";
		echo "limit = " . $limit . "\n";

		$project = Project::model()->find(
			'name=:name',
			array(':name' => $projectName)
		);
		if ($project === null)
			throw new Exception('Such a project name not found');
		$projectID = $project->getAttribute("id");
		echo "\nfind project $projectName, id = $projectID\n";

		$criteria = new CDbCriteria;
		$criteria->condition = 'project_id=' . $projectID;
		$criteria->order = 'version DESC';
		$appVersions = AppVersion::model()->findAll($criteria);
		$appVersions = array_reverse($appVersions);

		foreach ($appVersions as $appVerion) {
			if (substr($appVerion->version, 0, strlen($appVer)) != $appVer) {
				continue;
			}

			$appVerionID = $appVerion->getAttribute("id");
			echo "\nfind appver $appVerion->version, id = $appVerionID\n";
	
			$criteria = new CDbCriteria;
			$criteria->compare('project_id', $projectID, false, 'AND');
			$criteria->compare('appversion_id', $appVerionID, false, 'AND');
			$count = CrashReport::model()->count($criteria);
			echo "CrashReport count = $count\n";
	
			$criteria = new CDbCriteria;
			$criteria->compare('project_id', $projectID, false, 'AND');
			$criteria->compare('appversion_id', $appVerionID, false, 'AND');
			$criteria->limit = $limit;
			$criteria->order = 'date_created ASC';
			$reports = CrashReport::model()->findAll($criteria);
			//$reports = array_merge($reports, $reports, $reports, $reports, $reports, $reports, $reports);
			$index = 1;
			$start_time = new DateTime;
			foreach ($reports as &$report) {
				echo "[" . $index++ . "/" . sizeof($reports) . "] deleting CrashReport received on " . date("Y-m-d H:i:s", $report->received) . "\n";
				if (!$report->delete()) {
					//throw new CHttpException(404, 'The specified record doesn\'t exist in the database or could not be deleted.');
					echo "The specified record doesn\'t exist in the database or could not be deleted.\n";
				}
				$report = null;
				//sleep(1);
	
				$mem_usage = memory_get_usage();
				if($mem_usage > 500 * 1024 * 1024){
					echo "Memory usage = " . round($mem_usage / 1024 / 1024) . "MB\n";
					echo "Out of memory, exit now!\n";
					return 1;
				}
	
				if ($index % 10 == 1) {
					$stop_time = new DateTime;
					echo $index - 1 . " reports deleted, elapsed time = " . $stop_time->diff($start_time)->format("%h:%i:%s\n");
					echo "Memory usage = " . round($mem_usage / 1024 / 1024) . "MB\n";
				}
			}
		}

		$stop_time = new DateTime;
		echo sizeof($reports) . " reports deleted, elapsed time = " . $stop_time->diff($start_time)->format("%h:%i:%s\n");

		// dump memory stop
		if (extension_loaded('tideways_xhprof')) {
			$data = tideways_xhprof_disable();
			file_put_contents(
				sprintf("%s/%d.yourapp.xhprof", "c:/temp", getmypid()),
				serialize($data)
			);
			file_put_contents(
				sprintf("%s/%d.yourapp.xhprof.json", "c:/temp", getmypid()),
				json_encode($data)
			);
		}

		// Success
		Yii::log("Leaving the method run()", "info");
		echo "\nLeaving the method run()\n";
		return 0;
	}
};
