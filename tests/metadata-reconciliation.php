<?php
require __DIR__ . '/../classes/Core/WorkflowSnapshot.php';
require __DIR__ . '/../classes/Core/MetadataReconciliation.php';
use APP\plugins\generic\studioIntegration\classes\Core\MetadataReconciliation as M;
final class MetadataRecord {
    public function __construct(public array $data) {}
    public function getId() { return $this->data['id']; }
    public function getData($key) { return $this->data[$key] ?? null; }
    public function getDoi() { return '10.1234/test'; }
}
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$s = new MetadataRecord(['id' => 7, 'stageId' => 4, 'status' => 1]);
$p = new MetadataRecord(['id' => 9, 'status' => 1, 'title' => ['hu' => 'Cím', 'en' => 'Title'], 'abstract' => ['hu' => '<p>Egy &amp; kettő</p><p>Három</p>']]);
$contributors = [['externalId' => '8', 'name' => ['given' => 'Author'], 'email' => 'private@test.hu', 'orcidAccessToken' => 'SECRET']];
$a = M::snapshot($s, $p, ['hu', 'en'], ['hu' => ['kulcs'], 'en' => ['key']], $contributors, true);
check($a['metadata']['abstract']['hu'] === "Egy & kettő\nHárom", 'abstract plain text must preserve paragraphs and entities');
check(!str_contains(json_encode($a), 'SECRET') && !str_contains(json_encode($a), 'private@test.hu'), 'snapshot must exclude private identity fields');
check($a['metadata']['doi'] === '10.1234/test', 'native DOI must be readable');
$patch = M::patch($p, 'hu', ['title' => 'Új <cím>', 'keywords' => ['új']], ['hu', 'en'], $a['metadata']['keywords']);
check($patch['title']['en'] === 'Title' && $patch['title']['hu'] === 'Új &lt;cím&gt;', 'selected locale must preserve other languages and encode literal markup');
check($patch['keywords']['en'] === ['key'], 'keyword changes must preserve other locales');
foreach ([['doi' => '10.1234/forged'], ['status' => 3], ['contributors' => []], ['title' => []], ['keywords' => ['']], []] as $invalid) {
    try { M::patch($p, 'hu', $invalid, ['hu']); throw new RuntimeException('Invalid proposal accepted'); }
    catch (InvalidArgumentException $expected) {}
}
check(M::snapshot($s, $p, ['hu', 'en'], ['hu' => ['changed']], $contributors, true)['stateToken'] !== $a['stateToken'], 'keyword changes must invalidate the metadata token');
check(M::snapshot($s, $p, ['hu', 'en'], ['hu' => ['kulcs'], 'en' => ['key']], $contributors, false)['writableFields'] === [], 'read-only permission must not advertise writes');
echo "Metadata reconciliation checks passed.\n";
