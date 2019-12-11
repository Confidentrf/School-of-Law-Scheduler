<?PHP
// include statements
	include('/var/core/classes/logging/KLogger.php');
	include('/var/core/classes/db/sqlDb.php');
	include('/var/core/classes/cattura/CatturaCaptureCastPro.php');
	include('/var/core/classes/tms/TmsBookingApi_2.php');
	include('/var/core/classes/kaltura/KalturaClient.php');
	include('/var/core/classes/utilities/helperFunctions.php');
	
$date = new DateTime(NULL,new DateTimeZone("UTC"));
$processed = false;

try{
	$logger = new KLogger ("logs/newHandler_" . $date->format("Y-m-d") . ".log", KLogger::DEBUG);
	$sqlObject = new sqlDb(array("host"=>"sqlinst01.university.liberty.edu:55225","db"=>"IPCC","user"=>"sqlipcc","password"=>"HeIsDeadJim","connectAttempts"=> 3));
} catch (Exception $e){
	$error = "An exception was encountered while creating the required objects: " . var_export($e);
	
	header("HTTP/1.1 500 Internal Server Error");
	$payload = '{"error":"' . $error . '"}';
	$logger->LogInfo("Sent the following payload back $payload");
	echo $payload;
	exit();
}

$input = file_get_contents("php://input");
$input = str_replace("'","",$input);
$logger->LogInfo("Request recieved with the following information: " . var_export($input, true));

// Attemtp to decode JSON
$json = json_decode($input,true);
if($json == NULL){
	$error = "There was an error decoding the JSON. The error is: " . json_last_error();
	$logger->LogError($error);
	header("HTTP/1.1 400 Bad Request");
	$payload = '{"error":"' . $error . '"}';
	$logger->LogInfo("Sent the following payload back $payload");
	echo $payload;
	exit();
}

if ($json['@live']){
	// Find Device(s)
	// Telepresence or Cattura
	// Cattura
		// Create live entry in Kaltura
		//Create/modify Cattura template with Live stream info from Kaltura
	// TCS
		// Find in TMS
		// set TCS to live send livestream to Kaltura
		// Magic
		// Send data off to Kaltura
	$logger->LogInfo("Provisioned the Live event");
	$processed = true;
} else if ($json['@record'] || !empty($json['metadata'])){
	//$json['subject'] = str_replace("'","",$json['subject']);
	$device = array();
	$logger->LogInfo("This event will be a recorded event.");
	$sql = "SELECT fkMailbox, pkSerial,ipAddress, class, managementId, name, deviceId
		FROM IPCC.dbo.Collab_mailboxToEndpointMap 
		INNER JOIN IPCC.dbo.Collab_EndpointInfo
		ON Collab_mailboxToEndpointMap.fkEndpoint = Collab_EndpointInfo.pkid
		WHERE fkMailbox = '{$json['location']}'";
		
	$sqlResponse = $sqlObject->query($sql);
	if ($sqlResponse == NULL){
		$error = "There was a SQL error when attempting to locate the device. The error is: " . $sqlObject->GetErrorString();
		$logger->LogError($error);
		header("HTTP/1.1 500 Internal server error");
		$payload = '{"error":"' . $error . '"}';
		$logger->LogInfo("Sent the following payload back $payload");
		echo $payload;
		exit();
	}
	$logger->LogDebug("Here is the SQL device response " . var_export($sqlResponse,true));
	//if(count($sqlResponse) > 1){
		foreach($sqlResponse as $row){
			if($row['class'] == "CatturaCaptureCastPro"){
				$device['serial'] = $row['pkSerial'];
				$device['ip'] = $row['ipAddress'];
				$device['type'] = "CATTURA";
				$device['name'] = $row['name'];
				$device['id'] = $row['deviceId'];
				break;
			} else {
				$device['serial'] = $sqlResponse[0]['pkSerial'];
				$device['ip'] = $sqlResponse[0]['ipAddress'];
				$device['managementId'] = $sqlResponse[0]['managementId'];
				$device['type'] = "TELEPRESENCE";
				$device['name'] = $row['name'];
			}
		}
	$logger->LogInfo("Located the device {$device['serial']} with the IP {$device['ip']} which is of the type {$device['type']}");
	
	$guid = guidv4();
	$startTime = new DateTime($json['startUTC'],new DateTimeZone("UTC"));
	$endTime = new DateTime($json['endUTC'],new DateTimeZone("UTC"));
	if ($device['type'] == "CATTURA"){
		$logger->LogInfo("Attempting to schedule the event on the cattura device.");
		try{
			$cattura = new CatturaCaptureCastPro(array('ipAddress'=>$device['ip'], 'username'=>'apiuser', 'password'=>'Th3re!sn0t5y'));	
			$logger->LogDebug("The start time is " . $startTime->format("U") . " and the end time is " . $endTime->format("U"));
			$duration = $endTime->format("U") - $startTime->format("U");
			$logger->LogDebug("The raw duration is $duration");
			$duration = round($duration/60);
			$logger->LogDebug("The duration after rounding is  $duration");
			$catturaResponse = $cattura->createEvent(array("presentationTitle" =>$guid, "template"=> $device['id'] . "_Default_template", "startTime"=> $startTime->format("U") * 1000, "duration"=>$duration));
		} catch (Exception $e){
			$error = "There was a issue creating the Cattura event. The error is: " . var_export($e,true);
			$logger->LogError($error);
			header("HTTP/1.1 500 Internal Server Error");
			$payload = '{"error":"' . $error . '"}';
			$logger->LogInfo("Sent the following payload back $payload");
			echo $payload;
			exit();
		}
			$logger->LogInfo("Recieved the following response from Cattura: " . var_export($catturaResponse,true));
			$eventID = $catturaResponse->id;
			$logger->LogInfo("The event has sucessfully been scheduled in Cattura.");
	} else if ($device['type'] == "TELEPRESENCE"){
		$logger->LogInfo("Attempting to schedule the event on the cisco endpoint.");
		$startTime->setTimezone(new DateTimeZone("EST"));
		$endTime->setTimezone(new DateTimeZone("EST"));
		$tmsStartTime = str_replace(" ", "T",$startTime->format("Y-m-d H:i:s"));
		$tmsEndTime = str_replace(" ", "T",$startTime->format("Y-m-d H:i:s"));
		$startTime->setTimezone(new DateTimeZone("UTC"));
		$endTime->setTimezone(new DateTimeZone("UTC"));
		try{
			$tmsBookingApi = new TmsBookingApi(array("hostname" => "lutms.phones.liberty.edu", "user" => "sensenet\LectureCapture", "password" => "AndIHaveTouchedTheSky"));
		} catch (Exception $e){
			$error = "There was a issue with the TMS. The error is: " . var_export($e,true);
			$logger->LogError($error);
			header("HTTP/1.1 500 Internal Server Error");
			$payload = '{"error":"' . $error . '"}';
			$logger->LogInfo("Sent the following payload back $payload");
			echo $payload;
			exit();
		}
		
			$tmsConference = $tmsBookingApi->GetConferencesForSystems(array("SystemIds" => $device['managementId'], "StartTime" => $tmsStartTime, "EndTime" => $tmsEndTime, "ConferenceStatus" => "Pending"));
			if($tmsConference == NULL){
				$error = "There was a locating the confereence in TMS. The error is: " . $tmsBookingApi->GetErrorString();
				$logger->LogError($error);
				header("HTTP/1.1 500 Internal Server Error");
				$payload = '{"error":"' . $error . '"}';
				$logger->LogInfo("Sent the following payload back $payload");
				echo $payload;
				exit();
			}
			$logger->LogInfo("Located a confereence with the conference ID: " . $tmsConference->ConferenceId);
			$tmsConference = $tmsBookingApi->GetConferenceById(array("ConferenceId" => $tmsConference->ConferenceId));
			
			if($tmsConference == NULL){
				$error = "There was a locating the confereence in TMS. The error is: " . $tmsBookingApi->GetErrorString();
				$logger->LogError($error);
				header("HTTP/1.1 500 Internal Server Error");
				$payload = '{"error":"' . $error . '"}';
				$logger->LogInfo("Sent the following payload back $payload");
				echo $payload;
				exit();
			}
			$logger->LogInfo("Retrieved the following conference object" . var_export($tmsConference,true));
			
			$participentArray = NULL;
			if (is_array($tmsConference->Participants->Participant)){
				$participentArray = $tmsConference->Participants->Participant;
			} else {
				$participentArray = array($tmsConference->Participants->Participant);
			}
			$tcs = new stdClass();
			$tcs->ParticipantId = 150;
			$tcs->NameOrNumber = "818CAA38-73A0-40AF-963A-013F5F9A12E5";
			$tcs->ParticipantCallType = "TMS";
			
			$participentArray [] = $tcs;
			$tmsConference->Participants->Participant = $participentArray; 
			$logger->LogInfo("Attempting to save the following conference object " . var_export($tmsConference,true));
			
			$tmsResponse = $tmsBookingApi->SaveConferenceWithMode(array("Conference"=>$tmsConference,"BookingMode"=>"BestEffortForced"));
			if($tmsResponse == NULL){
				$error = "Failed to save the conference: " . $tmsBookingApi->GetErrorString();
				$logger->LogError($error);
				header("HTTP/1.1 500 Internal Server Error");
				$payload = '{"error":"' . $error . '"}';
				$logger->LogInfo("Sent the following payload back $payload");
				echo $payload;
				exit();
			}
	} else {
		$error = "Unknown device type: " . var_export($device,true);
			$logger->LogError($error);
			header("HTTP/1.1 500 Internal Server Error");
			$Ppayload = '{"error":"' . $error . '"}';
			$logger->LogInfo("Sent the following payload back $payload");
			echo $payload;
			exit();
	}
	$startTime = $startTime->format("Y-m-d H:i:s");
	$endTime = $endTime->format("Y-m-d H:i:s");
	$metadata = json_encode($json['metadata']);
	if ($json['attachments']){
		$attachments = 1;
	} else {
		$attachments = 0;
	}
	$eventInfo ="INSERT INTO IPCC.dbo.LCS_eventInfo
		(guid,
		deviceSerial,
		deviceIP,
		eventID,
		notificationObj,
		createdUTC,
		updateUTC)
		VALUES( 
		'$guid',
		'" . $device['serial'] . "',
		'" . $device['ip'] . "',
		'$eventID',
		'$input',
		GETUTCDATE(),
		GETUTCDATE())";
				
	$jobTracker = "INSERT INTO IPCC.dbo.LCS_jobTracker
				(pkid,
				organizer,
				subject,
				location,
				startUTC,
				endUTC,
				jsonMetadata,
				source,
				generatedUTC,
				lastUpdatedUTC,
				hasAttachment)
				VALUES(
				'$guid',
				'" . $json['organizer'] . "',
				'" . $json['subject'] . "',
				'" . $device['name'] . "',
				'$startTime',
				'$endTime',
				'$metadata',
				'ews',
				GETUTCDATE(),
				GETUTCDATE(),
				$attachments)";
	$logger->LogInfo("Attemtpting to execute a SQL insert: " . var_export($eventInfo,true));
	$eventResponse = $sqlObject->query($eventInfo);
	if ($eventResponse == NULL){
		$error = "A non fatal error was encountered was encountered while attempting to insert the LCS_eventInfo record. The error is " . $sqlObject->GetErrorString();
		$logger->LogError($error);
		mail("TelepresenceService@liberty.edu", "Exchange Scheduler Error", "$error This SQL will need to be inserted manually.");
		header("HTTP/1.1 206 Partial Content");
		$payload =  '{"guid":"' . $guid . '"}';
		$logger->LogInfo("Sent the following payload back $payload");
		echo $payload;
	}
	
	$logger->LogInfo("Attemtpting to execute a SQL insert: " . var_export($jobTracker,true));
	$jobtrackerResponse = $sqlObject->query($jobTracker);
	if ($jobtrackerResponse == NULL){
		$error = "An non fatal error was encountered attempting to insert the LCS_jobTracker record. The error is " . $sqlObject->GetErrorString();
		$logger->LogError($error);
		mail("TelepresenceService@liberty.edu", "Exchange Scheduler Error", "$error This SQL will need to be inserted manually.");
		header("HTTP/1.1 206 Partial Content");
		$payload =  '{"guid":"' . $guid . '"}';
		$logger->LogInfo("Sent the following payload back $payload");
		echo $payload;
	}
	$logger->LogInfo("SQL inserts were executed sucessfully.");
	$logger->LogInfo("Provisioned the recorded event");
	$processed =  true;
} else {
	$logger->LogInfo("No Live event or Recording was provisioned for ths request");
}

if ($json['@webex']){
	// webex things
	$logger->LogInfo("Provisioned the WebEx event");
	$processed = true;
}

if ($json['@spark']){
	// spark things
	$logger->LogInfo("Provisioned the Spark thingy");
	$processed = true;
} 

if ($processed){
	header("HTTP/1.1 201 Created");
	$payload =  '{"guid":"' . $guid . '"}';
	$logger->LogInfo("Sent the following payload back $payload");
	echo $payload;
} else {
	header("HTTP/1.1 204 NO CONTENT");
}

$logger->LogInfo("The script has finished processing the request.");

?>