<?php
require_once __DIR__ . '/../classes/Core/WorkflowSnapshot.php';
use APP\plugins\generic\studioIntegration\classes\Core\WorkflowSnapshot;
final class SnapshotRecord {
    public function __construct(public array $data) {}
    public function getId() { return $this->data['id']; }
    public function getData($key) { return $this->data[$key] ?? null; }
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$s = new SnapshotRecord(['id' => 7, 'stageId' => 4, 'status' => 1]);
$p = new SnapshotRecord(['id' => 9, 'status' => 1, 'title' => ['hu' => 'Cím', 'en' => 'Title'], 'reviewerEmail' => 'secret@example.org']);
$a = WorkflowSnapshot::create($s, $p);
check($a['stage'] === 'copyediting', 'native stage mapping');
check(strlen($a['stateToken']) === 64, 'state token');
check(!str_contains(json_encode($a), 'secret@example.org'), 'confidential fields must not be projected');
$p->data['title'] = ['en' => 'Title', 'hu' => 'Cím'];
check(WorkflowSnapshot::create($s, $p)['stateToken'] === $a['stateToken'], 'metadata key order is irrelevant');
$p->data['pages'] = '12–19';
check(WorkflowSnapshot::create($s, $p)['stateToken'] !== $a['stateToken'], 'publication metadata changes invalidate the token');
$s->data['stageId'] = 5;
check(WorkflowSnapshot::create($s, $p)['stage'] === 'production', 'production mapping');
check(WorkflowSnapshot::create($s, null)['publicationId'] === null, 'missing publication is explicit');
echo "Workflow snapshot checks passed.\n";
