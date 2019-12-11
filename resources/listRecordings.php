<?php
require 'sqlPDO/sqlPDO.php';
require 'GrayLogger/GrayLogger.php';

$log = new GrayLogger(
    [
        'log_level' => GrayLogger::LEVEL_DEBUG
        ,'log_method' => GrayLogger::LOG_LOCAL
        ,'application_name' => 'cattura'
        ,'script_name' => 'recordingLister'
        
    ]);
// handle delete events
if(isset($_GET['action']) && strtolower($_GET['action']) == 'removeevent'){
    // check for IP and event code
    $ip = $_GET['target'];
    $eventId = $_GET['eventid'];
    if(empty($ip) || empty($eventId)){
        http_response_code(400);
        die;
    }

    $url = "http://$ip/api/1.1/scheduler/events/$eventId";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    $info = curl_getinfo($ch);

    if(!$response || $info['http_code'] == 0 || $info['http_code'] >= 400){
        http_response_code(500);
        die;
    } else {
        http_response_code(204);
        die;
    }
}



if(!isset($_GET['target']) || !isset($_GET['range'])){
    http_response_code(400);
    die;
}


$url = 'http://' . trim($_GET['target']) . '/api/1/scheduler?range=' . urlencode(trim($_GET['range']));
$log->debug('Built the URL ' . $url);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

$log->debug('Response from cattura',['response' => $response, 'curl_info' => curl_getinfo($ch)]);

$httpcode = curl_getinfo($ch,CURLINFO_HTTP_CODE);

if(!$response || $httpcode == 0 || $httpcode >= 400){
    http_response_code(500);
    die;
}

$events =  json_decode($response);
if(count($events) < 1){
    $log->info('No events found.');
    http_response_code(204);
    die;
}

$cleanedEvents = [];
$ids = [];
foreach($events as $event){
    $tmp = new stdClass();
    $tmp->id = $event->title;
    $tmp->name = $event->title;
    $tmp->eventId = $event->id;
    $tmp->ip = trim($_GET['target']);

    $startTime = new DateTime('@' . $event->startTime / 1000,new dateTimeZone('UTC'));
    $endTime = new DateTime('@' . $event->stopTime / 1000,new dateTimeZone('UTC'));
    
    $startTime->setTimezone(new DateTimeZone('America/New_York'));
    $endTime->setTimezone(new DateTimeZone('America/New_York'));

    $tmp->startTime = $startTime->format('Y-m-d H:i');
    $tmp->endTime = $endTime->format('Y-m-d H:i');
    
    $tmp->recordingComplete = $event->completed;

    $cleanedEvents[strtolower($tmp->id)] = $tmp;
    
    if (preg_match('/^\{?[A-Z0-9a-z]{8}-[A-Z0-9a-z]{4}-[A-Z0-9a-z]{4}-[A-Z0-9a-z]{4}-[A-Z0-9a-z]{12}\}?$/', $tmp->id)){
        $ids[] = strtolower($tmp->id);
    }
    unset($tmp);
}
if(count($ids) > 0 ){
    $ids = '\'' . implode('\',\'',$ids) . '\'';
    $sql = "SELECT 
            pkid
            ,subject
            FROM IPCC.dbo.LCS_jobTracker
            WHERE pkid IN ($ids)";

    try{
        $db = new sqlPDO(
            [
                'db' => 'sqlinst01'
                //,'ini_path' => 'C:\Users\jvlogwood\ini\db.ini'
            ]);
        $rows = $db->simpleQuery($sql);
    } catch (Exception $e){
        http_response_code(500);
        die;
    }

    foreach($rows as $row){
        $id = strtolower($row['pkid']);
        $event = $cleanedEvents[$id];
        $event->name = empty($row['subject'])? $event->title : $row['subject'];

        $cleanedEvents[$id] = $event;
    }
}
foreach($cleanedEvents as $key => $value){
    $cleanedEvents[$value->startTime] = $value;
    unset($cleanedEvents[$key]);
}

ksort($cleanedEvents);
$cleanedEvents = array_values($cleanedEvents);

http_response_code(200);
echo json_encode($cleanedEvents,JSON_PRETTY_PRINT);
die;