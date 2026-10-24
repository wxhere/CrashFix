<?php
// Isolated regression test: PHP 7.4 with PDO SQLite; no production configuration.
require_once dirname(__FILE__).'/../framework/yii.php';
class OSNameTestUser extends CApplicationComponent
{
    public $version = -1;
    public function getCurProjectId() { return 1; }
    public function getCurProjectVersions(&$version) { $version = $this->version; return array(); }
}
function checkOSName($condition, $message)
{
    if (!$condition) throw new Exception($message);
    echo "PASS: $message\n";
}
$app = Yii::createConsoleApplication(array(
    'basePath'=>realpath(dirname(__FILE__).'/..'),
    'import'=>array('application.models.*'),
    'components'=>array(
        'db'=>array('class'=>'CDbConnection', 'connectionString'=>'sqlite::memory:', 'tablePrefix'=>'test_'),
        'user'=>array('class'=>'OSNameTestUser'),
    ),
));
$db = $app->db;
$db->createCommand()->createTable('{{crashreport}}', array(
    'id'=>'pk', 'project_id'=>'integer', 'appversion_id'=>'integer',
    'os_name_reg'=>'text', 'os_ver_mdmp'=>'text', 'product_type'=>'text',
));
$cases = array(
    array('Windows 10 Pro Build 26300', '10.0.26300', '1 Workstation', 'Windows 11 Pro Build 26300'),
    array('Windows 10 Home Build 22000', '10.0.22000 ', '1 Workstation', 'Windows 11 Home Build 22000'),
    array('Windows 10 Pro Build 21999', '10.0.21999', '1 Workstation', 'Windows 10 Pro Build 21999'),
    array('Windows 10 Pro Build 19045', '10.0.19045', '1 Workstation', 'Windows 10 Pro Build 19045'),
    array('Windows Server 2025 Build 26100', '10.0.26100', '3 Server', 'Windows Server 2025 Build 26100'),
    array('Windows 10 Pro Build 26300', '10.0.26300', '3 Server', 'Windows 10 Pro Build 26300'),
    array('Windows 10 Pro Build 26300', '10.0.26300', '2 Domain Controller', 'Windows 10 Pro Build 26300'),
    array('Windows 10 Pro Build 26300', '', '1 Workstation', 'Windows 10 Pro Build 26300'),
    array('Windows 10 Pro Build 26300', '10.0.26300', '', 'Windows 10 Pro Build 26300'),
    array('Windows 10 Pro Build 26300', '10.0.26300oops', '1 Workstation', 'Windows 10 Pro Build 26300'),
    array('Windows 10 Pro Build 26300', '10.0.26300', '10 invalid', 'Windows 10 Pro Build 26300'),
    array('Windows 11 Pro Build 26300', '10.0.26300', '1 Workstation', 'Windows 11 Pro Build 26300'),
    array('Windows 100', '10.0.26300', '1 Workstation', 'Windows 100'),
    array('Windows 7', '6.1.7601', '1 Workstation', 'Windows 7'),
);
foreach ($cases as $case) {
    $model = new CrashReport;
    $model->os_name_reg = $case[0];
    $model->os_ver_mdmp = $case[1];
    $model->product_type = $case[2];
    checkOSName($model->getOSDisplayName() === $case[3], 'display: '.implode(' / ', $case));
    checkOSName($model->os_name_reg === $case[0], 'raw report name preserved');
}
function seedOS($name, $version, $type, $project, $release, $count)
{
    for ($i=0; $i<$count; $i++) Yii::app()->db->createCommand()->insert('{{crashreport}}', array(
        'os_name_reg'=>$name, 'os_ver_mdmp'=>$version, 'product_type'=>$type,
        'project_id'=>$project, 'appversion_id'=>$release,
    ));
}
seedOS('Windows 10 Pro Build 26300', '10.0.26300', '1 Workstation', 1, 1, 2);
seedOS('Windows 11 Pro Build 26300', '10.0.26300', '1 Workstation', 1, 1, 3);
seedOS('Windows 10 Pro Build 26300', '10.0.26300', '3 Server', 1, 1, 1);
seedOS('Windows 10 Pro Build 19045', '10.0.19045', '1 Workstation', 1, 2, 4);
seedOS('Windows 10 Pro Build 26300', '10.0.26300', '1 Workstation', 2, 1, 10);
$counts = CrashReport::getOSVersionDistribution();
checkOSName($counts['Windows 11 Pro Build 26300'] === 5, 'old and new client names merge before chart percentages');
checkOSName(array_sum($counts) === 10, 'distribution remains scoped to project');
checkOSName(key($counts) === 'Windows 11 Pro Build 26300', 'distribution sorted after name merging');
checkOSName($counts['Windows 10 Pro Build 26300'] === 1, 'server product type remains distinct');
$app->user->version = 1;
checkOSName(array_sum(CrashReport::getOSVersionDistribution()) === 6, 'distribution respects selected release');
echo "All OS-name checks passed.\n";
