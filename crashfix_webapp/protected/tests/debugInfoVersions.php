<?php
// Standalone regression test: php -d extension=pdo_sqlite protected/tests/debugInfoVersions.php
error_reporting(E_ALL & ~E_DEPRECATED);
$base = dirname(__FILE__).'/..';
require_once $base.'/framework/yii.php';

class ReleaseTestUser extends CApplicationComponent
{
    public $version = -1;
    public $canManage = true;
    public $currentProject = 1;
    public function getCurProjectId() { return $this->currentProject; }
    public function getCurProjectVer() { return $this->version; }
    public function checkAccess($permission, $params) { return $this->canManage && $params['project_id'] == 1; }
}

function verify($condition, $message)
{
    if (!$condition) throw new Exception($message);
    echo "PASS: $message\n";
}

function removeReleaseTestFiles($path)
{
    if (is_dir($path)) {
        foreach (scandir($path) as $name)
            if ($name !== '.' && $name !== '..') removeReleaseTestFiles($path.'/'.$name);
        rmdir($path);
    } else {
        unlink($path);
    }
}
$runtime = tempnam(sys_get_temp_dir(), 'crashfix-release-');
unlink($runtime);
mkdir($runtime);
register_shutdown_function(function() use ($runtime) { removeReleaseTestFiles($runtime); });

$app = Yii::createConsoleApplication(array(
    'basePath'=>realpath($base),
    'runtimePath'=>$runtime,
    'import'=>array('application.models.*', 'application.components.*'),
    'components'=>array(
        'db'=>array('class'=>'CDbConnection', 'connectionString'=>'sqlite::memory:', 'tablePrefix'=>'test_'),
        'user'=>array('class'=>'ReleaseTestUser'),
        'request'=>array('class'=>'CHttpRequest'),
    ),
));
$db = $app->db;
$db->createCommand()->createTable('{{project}}', array('id'=>'pk', 'name'=>'text', 'status'=>'integer', 'debug_info_files_disc_quota'=>'integer'));
$db->createCommand()->createTable('{{appversion}}', array('id'=>'pk', 'project_id'=>'integer', 'version'=>'text'));
$db->createCommand()->createTable('{{debuginfo}}', array('id'=>'pk', 'project_id'=>'integer', 'filename'=>'text', 'guid'=>'text', 'status'=>'integer', 'dateuploaded'=>'integer', 'md5'=>'text', 'filesize'=>'integer'));
$db->createCommand()->insert('{{project}}', array('id'=>1, 'name'=>'BitComet', 'status'=>1, 'debug_info_files_disc_quota'=>0));
$db->createCommand()->insert('{{project}}', array('id'=>2, 'name'=>'Other', 'status'=>1, 'debug_info_files_disc_quota'=>0));
foreach (array(array(1,1,'2.22'), array(2,1,'2.21'), array(3,2,'other')) as $v)
    $db->createCommand()->insert('{{appversion}}', array('id'=>$v[0], 'project_id'=>$v[1], 'version'=>$v[2]));
$db->createCommand()->insert('{{debuginfo}}', array('id'=>1, 'project_id'=>1, 'filename'=>'old.pdb', 'guid'=>'old', 'status'=>3, 'dateuploaded'=>1));
$migration = $base.'/migrations/m261024_120000_debug_info_release_version.php';
verify(file_exists($migration), 'release-version migration exists');
require_once $migration;
$migration = new m261024_120000_debug_info_release_version;
$migration->up();
verify($db->createCommand('SELECT appversion_id FROM {{debuginfo}} WHERE id=1')->queryScalar() === null, 'migration preserves old files as unassigned');
foreach (array(array(2,1,1,'new.pdb'), array(3,1,2,'previous.pdb'), array(4,2,3,'other.pdb')) as $r)
    $db->createCommand()->insert('{{debuginfo}}', array('id'=>$r[0], 'project_id'=>$r[1], 'appversion_id'=>$r[2], 'filename'=>$r[3], 'guid'=>'guid'.$r[0], 'dateuploaded'=>$r[0], 'status'=>3));
function resultIds($version, $filter='')
{
    $model = new DebugInfo('search');
    $model->releaseVersion = $version;
    $model->filter = $filter;
    return array_map(function($r) { return (int)$r->id; }, $model->search()->getData());
}
verify(resultIds(-1) === array(3,2,1), 'all versions remain scoped to project');
verify(resultIds(1) === array(2), 'specific release filter');
verify(resultIds(0) === array(1), 'unassigned release filter');
verify(resultIds(1, 'old') === array(), 'text search cannot escape release filter');
verify(DebugInfo::model()->findByPk(2)->getReleaseVersionLabel() === '2.22', 'release label');
verify(DebugInfo::model()->findByPk(1)->getReleaseVersionLabel() === '(unassigned)', 'old file label');
foreach (array(null, '', 0, 1, 2) as $value) {
    $model = new DebugInfo('assignVersion');
    $model->project_id = 1;
    $model->appversion_id = $value;
    verify($model->validate(array('appversion_id')), 'valid release '.var_export($value, true));
}
foreach (array(-1, 3, 999, '1oops', array(1)) as $value) {
    $model = new DebugInfo('assignVersion');
    $model->project_id = 1;
    $model->appversion_id = $value;
    verify(!$model->validate(array('appversion_id')), 'reject invalid or foreign release '.json_encode($value));
}

file_put_contents($runtime.'/upload.pdb', 'disposable test symbol');
$uploaded = new DebugInfo('create');
$uploaded->project_id = 1;
$uploaded->appversion_id = 1;
$uploaded->guid = 'tmp_release_upload';
$uploaded->fileAttachmentIsUploaded = false;
$uploaded->fileAttachment = new CUploadedFile('upload.pdb', $runtime.'/upload.pdb', 'application/octet-stream', 22, 0);
verify($uploaded->save(), 'file creation saves release association');
$app->user->version = 2;
$uploaded->status = DebugInfo::STATUS_PROCESSING_IN_PROGRESS;
verify($uploaded->save() && DebugInfo::model()->findByPk($uploaded->id)->appversion_id == 1, 'processing retains release after current version changes');
require_once $base.'/commands/PollCommand.php';
file_put_contents($runtime.'/import.xml', '<DebugInfo><Summary><GUID>release-guid</GUID><Age>1</Age></Summary></DebugInfo>');
$poll = new PollCommand('poll', null);
verify($poll->importDebugInfoFromXml($runtime.'/import.xml', $uploaded->id), 'processing XML imports successfully');
verify(DebugInfo::model()->findByPk($uploaded->id)->appversion_id == 1, 'processing XML preserves release metadata');

mkdir($runtime.'/import/debugInfo/BitComet/2.22', 0777, true);
file_put_contents($runtime.'/import/debugInfo/BitComet/2.22/directory.pdb', 'disposable directory symbol');
$batch = new BatchImporter;
$crashes = $symbols = 0;
verify($batch->importFiles($runtime.'/import', $crashes, $symbols) && $symbols === 1, 'directory import succeeds');
verify(DebugInfo::model()->findByAttributes(array('filename'=>'directory.pdb'))->appversion_id == 1, 'directory import saves its directory release');

require_once $base.'/controllers/DebugInfoController.php';
class ReleaseTestController extends DebugInfoController
{
    public $rendered;
    public function redirect($url, $terminate=true, $statusCode=302) {}
    public function render($view, $data=null, $return=false) { $this->rendered = $data; }
}
$controller = new ReleaseTestController('debugInfo');
$_SERVER['REQUEST_METHOD'] = 'POST';
$app->user->currentProject = 2;
$_POST = array('DebugInfo'=>array('project_id'=>1, 'appversion_id'=>1));
$controller->actionUploadFile();
verify($controller->rendered['model']->project_id == 1 && $controller->rendered['model']->appversion_id == 1, 'upload uses explicit project and version after another tab switches project');
$app->user->currentProject = 1;
$_POST = array('DeleteRows'=>array(1,2), 'project_id'=>1, 'appversion_id'=>2);
$controller->actionAssignVersion();
verify(DebugInfo::model()->findByPk(1)->appversion_id == 2 && DebugInfo::model()->findByPk(2)->appversion_id == 2, 'batch assignment persists all selected files');
verify(DebugInfo::model()->findByPk(1)->status == 3, 'batch assignment preserves processing status');
$_POST['DeleteRows'] = array(1,4);
$_POST['appversion_id'] = 1;
try {
    $controller->actionAssignVersion();
    throw new Exception('Expected forbidden foreign project');
} catch (CHttpException $e) {
    verify($e->statusCode === 403, 'foreign project rejected');
}
verify(DebugInfo::model()->findByPk(1)->appversion_id == 2, 'mixed-project failure rolls back earlier changes');
$_POST['DeleteRows'] = array(1);
$_POST['appversion_id'] = 3;
try {
    $controller->actionAssignVersion();
    throw new Exception('Expected invalid foreign release');
} catch (CHttpException $e) {
    verify($e->statusCode === 400, 'foreign release rejected by batch endpoint');
}
$app->user->canManage = false;
$_POST['appversion_id'] = 1;
try {
    $controller->actionAssignVersion();
    throw new Exception('Expected forbidden management action');
} catch (CHttpException $e) {
    verify($e->statusCode === 403, 'read-only user cannot assign versions');
}
$app->user->canManage = true;
$_POST['appversion_id'] = 0;
$controller->actionAssignVersion();
verify(DebugInfo::model()->findByPk(1)->appversion_id === null, 'batch assignment can clear release');
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
    $controller->actionAssignVersion();
    throw new Exception('Expected POST-only action');
} catch (CHttpException $e) {
    verify($e->statusCode === 400, 'batch endpoint requires POST');
}
verify($migration->down() === false, 'SQLite rollback is refused before modifying schema');
verify(isset($db->schema->getTable('{{debuginfo}}', true)->columns['appversion_id']), 'refused SQLite rollback preserves release metadata');
verify((int)$db->createCommand('SELECT COUNT(*) FROM {{debuginfo}}')->queryScalar() === 6, 'refused SQLite rollback preserves files');
echo "All release-version regression checks passed.\n";
