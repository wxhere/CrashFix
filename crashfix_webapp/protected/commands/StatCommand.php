<?php

class StatCommand extends CConsoleCommand
{
	public function getOptionHelp()
	{
		$help = "[project=<name>] [appver=<ver>]";

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

		$v_projectName = "";
		if (array_key_exists("project", $options))
			$v_projectName = $options["project"];

		$v_appVer = "";
		if (array_key_exists("appver", $options))
			$v_appVer = $options["appver"];

		echo "\nInput options: \n";
		echo "projectName = " . $v_projectName . "\n";
		echo "appver = " . $v_appVer . "\n";

		$criteria = new CDbCriteria;
		if ($v_projectName != "")
			$criteria->compare('name', $v_projectName, false, 'AND');
		$projects = Project::model()->findAll($criteria);

		if ($projects === null) {
			echo "No projects found!\n";
			return 0;
		}

		echo "\nProject list:\n";
		foreach ($projects as $project) {
			$projectID = $project->getAttribute("id");
			$projectName = $project->getAttribute("name");
			echo "name: $projectName, id = $projectID\n";

			$criteria = new CDbCriteria;
			$criteria->condition = 'project_id=' . $projectID;
			$criteria->order = 'version DESC';
			$appVersions = AppVersion::model()->findAll($criteria);
			$appVersions = array_reverse($appVersions);

			$found_ver = false;
			$version_count_in_project = 0;
			$report_count_in_project = 0;
			foreach ($appVersions as $appVersion) {
				if ($v_appVer != "" && $v_appVer != $appVersion->version)
					continue;

				$appVerID = $appVersion->id;
				$criteria = new CDbCriteria;
				$criteria->compare('project_id', $projectID, false, 'AND');
				$criteria->compare('appversion_id', $appVerID, false, 'AND');
				$count = CrashReport::model()->count($criteria);

				if ($count <= 0)
					continue;

				echo "  appver: $appVersion->version, report count = $count\n";
				$found_ver = true;
				$version_count_in_project++;
				$report_count_in_project += $count;
			}
			if (!$found_ver)
				echo " appver: $v_appVer, no report found\n";

			echo "count of versions in $projectName = $version_count_in_project\n";
			echo "count of reports in $projectName = $report_count_in_project\n";
		}

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
