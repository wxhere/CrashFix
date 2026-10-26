<?php
// Isolated SQLite checks for the legacy console clean command.
require_once dirname(__FILE__).'/../framework/yii.php';
$base = realpath(dirname(__FILE__).'/..');
Yii::createConsoleApplication(array(
    'basePath'=>$base,
    'import'=>array('application.models.*'),
    'components'=>array('db'=>array(
        'class'=>'CDbConnection', 'connectionString'=>'sqlite::memory:', 'tablePrefix'=>'test_',
    )),
));
$db = Yii::app()->db;
foreach (array(
    'project'=>array('id'=>'pk', 'name'=>'text'),
    'appversion'=>array('id'=>'pk', 'project_id'=>'integer', 'version'=>'text'),
    'crashreport'=>array('id'=>'pk', 'project_id'=>'integer', 'appversion_id'=>'integer',
        'groupid'=>'integer', 'date_created'=>'integer', 'received'=>'integer', 'md5'=>'text'),
    'crashgroup'=>array('id'=>'pk', 'project_id'=>'integer', 'appversion_id'=>'integer',
        'title'=>'text', 'status'=>'integer', 'created'=>'integer', 'md5'=>'text'),
    'bug'=>array('id'=>'pk', 'appversion_id'=>'integer'),
    'debuginfo'=>array('id'=>'pk', 'appversion_id'=>'integer'),
    'bug_crashgroup'=>array('id'=>'pk', 'crashgroup_id'=>'integer'),
    'user'=>array('id'=>'pk', 'cur_appversion_id'=>'integer'),
    'thread'=>array('id'=>'pk', 'crashreport_id'=>'integer'),
    'module'=>array('id'=>'pk', 'crashreport_id'=>'integer'),
    'fileitem'=>array('id'=>'pk', 'crashreport_id'=>'integer'),
    'customprop'=>array('id'=>'pk', 'crashreport_id'=>'integer'),
    'processingerror'=>array('id'=>'pk', 'srcid'=>'integer', 'type'=>'integer'),
) as $table=>$columns) {
    $db->createCommand()->createTable('{{'.$table.'}}', $columns);
}
function checkClean($condition, $message)
{
    if (!$condition) throw new Exception($message);
    echo "PASS: $message\n";
}
function seedClean($table, $attributes)
{
    Yii::app()->db->createCommand()->insert('{{'.$table.'}}', $attributes);
}
function countClean($table, $where='1=1', $params=array())
{
    return (int)Yii::app()->db->createCommand('SELECT COUNT(*) FROM {{'.$table.'}} WHERE '.$where)
        ->queryScalar($params);
}
require_once $base.'/commands/CleanCommand.php';
$command = new CleanCommand('clean', null);
seedClean('project', array('id'=>1, 'name'=>'bitcomet'));
seedClean('appversion', array('id'=>1, 'project_id'=>1, 'version'=>'2.8.5.30'));
seedClean('appversion', array('id'=>2, 'project_id'=>1, 'version'=>'2.8.5.30-extra'));
seedClean('appversion', array('id'=>3, 'project_id'=>1, 'version'=>'2.8.5.31'));
seedClean('user', array('id'=>1, 'cur_appversion_id'=>1));
seedClean('crashgroup', array('id'=>10, 'project_id'=>1, 'appversion_id'=>1,
    'title'=>'old collection', 'status'=>1, 'created'=>1, 'md5'=>str_repeat('a', 32)));
foreach (array(1, 2) as $id) {
    seedClean('crashreport', array('id'=>$id, 'project_id'=>1, 'appversion_id'=>1,
        'groupid'=>10, 'date_created'=>$id, 'received'=>$id, 'md5'=>str_repeat('f', 32)));
}
seedClean('crashreport', array('id'=>3, 'project_id'=>1, 'appversion_id'=>2,
    'groupid'=>0, 'date_created'=>3, 'received'=>3, 'md5'=>str_repeat('e', 32)));
function runCleanVersion($command, $version, $limit=10)
{
    ob_start();
    try {
        return $command->run(array('project=bitcomet', 'appver='.$version,
            'limit='.$limit, 'deleteversion=1', 'maintenance=1'));
    } finally {
        ob_end_clean();
    }
}
try {
    $command->run(array('project=bitcomet', 'appver=2.8.5.30',
        'limit=1', 'deleteversion=1'));
    throw new Exception('Expected maintenance-mode guard');
} catch (Exception $e) {
    checkClean(strpos($e->getMessage(), 'maintenance=1') !== false,
        'version deletion requires maintenance acknowledgement');
}
checkClean(countClean('crashreport', 'appversion_id=1') === 2,
    'missing maintenance acknowledgement does not delete reports');
checkClean(runCleanVersion($command, '2.8.5.30', 1) === 0,
    'first bounded batch succeeds');
checkClean(countClean('crashreport', 'appversion_id=1') === 1 &&
    countClean('appversion', 'id=1') === 1, 'version remains while reports remain');
checkClean(countClean('crashreport', 'appversion_id=2') === 1,
    'deleteversion requires an exact version match');
checkClean(runCleanVersion($command, '2.8.5.30', 1) === 0,
    'final bounded batch succeeds');
checkClean(countClean('crashreport', 'appversion_id=1') === 0 &&
    countClean('crashgroup', 'appversion_id=1') === 0 &&
    countClean('appversion', 'id=1') === 0, 'finished version and empty collections removed');
checkClean((int)$db->createCommand('SELECT cur_appversion_id FROM {{user}} WHERE id=1')->queryScalar() === 0 ||
    $db->createCommand('SELECT cur_appversion_id FROM {{user}} WHERE id=1')->queryScalar() === null,
    'saved user version selection cleared');
foreach (array('bug', 'debuginfo') as $table) {
    seedClean($table, array('appversion_id'=>3));
    try {
        runCleanVersion($command, '2.8.5.31');
        throw new Exception('Expected a blocked version deletion');
    } catch (Exception $e) {
        checkClean(strpos($e->getMessage(), 'still reference') !== false,
            $table.' reference blocks version deletion');
    }
    $db->createCommand()->delete('{{'.$table.'}}');
}
seedClean('crashgroup', array('id'=>11, 'project_id'=>1, 'appversion_id'=>3,
    'title'=>'tracked collection', 'status'=>1, 'created'=>1, 'md5'=>str_repeat('b', 32)));
seedClean('bug_crashgroup', array('crashgroup_id'=>11));
try {
    runCleanVersion($command, '2.8.5.31');
    throw new Exception('Expected a blocked collection deletion');
} catch (Exception $e) {
    checkClean(strpos($e->getMessage(), 'still reference') !== false,
        'bug collection mapping blocks version deletion');
}
checkClean(countClean('appversion', 'id=3') === 1 && countClean('crashgroup', 'id=11') === 1,
    'blocked deletion leaves version and collection intact');
seedClean('appversion', array('id'=>4, 'project_id'=>1, 'version'=>'2.8.5.32'));
ob_start();
try {
    $command->run(array('project=bitcomet', 'appver=2.8.5.32', 'limit=1'));
} finally {
    ob_end_clean();
}
checkClean(countClean('appversion', 'id=4') === 1,
    'legacy clean command retains version without deleteversion');
seedClean('crashgroup', array('id'=>12, 'project_id'=>1, 'appversion_id'=>4,
    'title'=>'cross-version collection', 'status'=>1, 'created'=>1, 'md5'=>str_repeat('c', 32)));
$db->createCommand()->update('{{crashreport}}', array('groupid'=>12), 'id=3');
try {
    runCleanVersion($command, '2.8.5.32');
    throw new Exception('Expected a blocked cross-version collection deletion');
} catch (Exception $e) {
    checkClean(strpos($e->getMessage(), 'still reference') !== false,
        'a collection containing another version report is preserved');
}
checkClean(countClean('crashreport', 'id=3') === 1 && countClean('crashgroup', 'id=12') === 1,
    'blocked collection deletion does not cascade to another version');
echo "All clean-version checks passed.\n";
