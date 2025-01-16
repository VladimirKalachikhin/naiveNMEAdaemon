<?php
/* https://www.navcen.uscg.gov/types-of-ais
Mobile equipment to assist homing to itself (i.e. life boats, life rafts). 
An AIS SART transmits a text broadcast (message 14) of either 'SART TEST' or 'ACTIVE SART'. 
When active the unit also transmits a position message 
(message 1 with a 'Navigation Status' = 14) in a burst of 8 messages once per minute

https://www.e-navigation.nl/content/safety-related-broadcast-message 
The AIS-SART should use Message 14, and the safety related text should be:
1)          For the active SART, the text should be “SART ACTIVE”.
2)          For the SART test mode, the text should be “SART TEST”.
3)          For the active MOB, the text should be “MOB ACTIVE”.
4)          For the MOB test mode, the text should be “MOB TEST”.
5)          For the active EPIRB, the text should be “EPIRB ACTIVE”.
6)          For the EPIRB test mode, the text should be “EPIRB TEST”.

*/
$SARTdata = Array(	// $SARTdata это то же самое, что $instrumentsData['AIS'], только для MOB, EPIRB или просто Search and Rescue Transmitterь(SART)
'970999999' => array(
	"mmsi" => 970999999,
	"status" => 14,
	"status_text" => "AIS-SART is active",
	"lon" => 28.259,
	"lat" => 61.0785,
	"safety_related_text" => "SART TEST",
	"timestamp" => time()
),
'972999999' => array(
	"mmsi" => 972999999,
	"status" => 14,
	"status_text" => "AIS-SART is active",
	"lon" => 28.2593,
	"lat" => 61.079,
	"safety_related_text" => "MOB TEST",
	"timestamp" => time()
),
'974999999' => array(
	"mmsi" => 974999999,
	"status" => 14,
	"status_text" => "AIS-SART is active",
	"lon" => 28.2615,
	"lat" => 61.0787,
	"safety_related_text" => "EPIRB TEST",
	"timestamp" => time()
)
);

?>
