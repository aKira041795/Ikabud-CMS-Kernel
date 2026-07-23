<?php
$_SERVER["HTTP_HOST"] = "aiss.test";
ob_start();
require "bootstrap.php";
ob_end_clean();
$db = app()->db();
$r = $db->query("SELECT result_json FROM ac_similarity_processing_jobs WHERE submission_id=26 AND job_type='semantic_match' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$j = json_decode($r["result_json"], true);
echo $j["semantic_status"] . " " . $j["reason"] . "\n";
if (isset($j["gates"])) echo json_encode($j["gates"]) . "\n";
if (isset($j["error"])) echo "ERROR: " . $j["error"] . "\n";
