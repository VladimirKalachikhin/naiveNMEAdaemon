<?php
/* This is the simplest web daemon to broadcast NMEA sentences from the given file.
Designed for debugging applications that use gpsd.
The file set in $nmeaFileName and must content correct sentences, one per line.
Required options:
-i log file name
-b bind to proto://address:port
Run:
$ php naiveNMEAdaemon.php -isample1.log -btcp://127.0.0.1:2222 -t200000 --run=60 --filtering=GGA,GLL,GNS,RMC,VTG,GSA --updsat=12 --updtime --updcourse --updspeed=6 --savesentences=my-new-log
- complex usage
or:
$ php naiveNMEAdaemon.php
- with default sample file (sample1.log) and default port (2222) on localhost
or:
$ php naiveNMEAdaemon.php sample1.log
- getted log and defaults
gpsd run to connect this:
$ gpsd -N -n tcp://192.168.10.10:2222
*/
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);

$windSpeedDelta = 1.5;	// 
$windDirDelta = 10;	// 
$windDeviation = 0.4;
$windDeviationPeriod = 30;	// через сколько посылок ветра его параметры будут изменены
$depthDelta = 0.1;	// m
$depthDeviationPeriod = 10;

$options = getopt("i::t::b::h",['help','run::','filtering::','updsat::','updtime::','updcourse','updspeed::','savesentences::','wind::','depth::','sart::']);
//print_r($options); echo "\n";
// NMEA sentences file name;
if(@$options['i']) $nmeaFileName = filter_var(@$options['i'],FILTER_SANITIZE_URL);
elseif(@$argv[1]) $nmeaFileName = filter_var(@end($argv),FILTER_SANITIZE_URL);	// последний аргумент в коммандной строке
if(!isset($nmeaFileName) or ($nmeaFileName[0]=='-')) $nmeaFileName = __DIR__ . '/sample1.log';
$nmeaFileNames = explode(',',$nmeaFileName);

if(!($delay = filter_var(@$options['t'],FILTER_SANITIZE_NUMBER_INT))) $delay = 200000; 	// Min interval between sends sentences, in microseconds. 200000 are semi-realtime for sample1.log
if(!($bindAddres=filter_var(@$options['b'],FILTER_VALIDATE_DOMAIN))) $bindAddres = "tcp://127.0.0.1:2222"; 	// Daemon's access address;
if(!($run = filter_var(@$options['run'],FILTER_SANITIZE_NUMBER_INT))) $run = 0; 	// Overall time of work, in seconds. If 0 - infinity.
if(@$options['filtering']) {
	$filtering = explode(',',$options['filtering']);	// те, что пропустить
	$noFiltering = array();	// те, что не пропускать
	foreach($filtering as $key => $value){
		$code = substr($value,0,-3);
		if($code=='x'){
			$noFiltering[substr($value,-3)] = array(0,0);
			unset($filtering[$key]);
		}
		elseif($interval = intval($code)) {
			$noFiltering[substr($value,-3)] = array($interval,0);
			unset($filtering[$key]);
		}
	}
	//echo('noFiltering:'); print_r($noFiltering);
}
else {
	$filtering = false;
	$noFiltering = false;
}
if(isset($options['updsat'])){		// заменять в GGA нулевое количество видимых спутников на какое-то, если есть координаты -- исправление кривизны gpsd, который не любит нулевого количества спутников
	if($updSat = filter_var($options['updsat'],FILTER_SANITIZE_NUMBER_INT)) $updSat = sprintf('%02d', $updSat);
	else $updSat = FALSE; 	// 
}
else $updSat = '06';
if(isset($options['updtime']) and $options['updtime']==FALSE) $updTime = FALSE;	// исправлять время везде, где оно есть, на сейчас
elseif(is_numeric(@$options['updtime'])) $updTime = $options['updtime'];
else $updTime = TRUE;
if(isset($options['updcourse'])) $updCourse = TRUE;	// в предложениях RMC устанавливать поле 8 Track made good по значению предыдущих координат и координат из этого предложения
else $updCourse = FALSE;
$saveSentences = filter_var(@$options['savesentences'],FILTER_SANITIZE_URL); 	// записывать ли предложения NMEA в отдельный файл. Например, результат фильтрации
if(isset($options['sart'])){ 	// добавлять ли предложения AIS SART для точек из указанного файла
	if(!($SARTdataFile = filter_var(@$options['sart'],FILTER_SANITIZE_URL))) $SARTdataFile = 'SARTsample.php';
	include($SARTdataFile);
	if($SARTdata) require "fAIS.php";
	$SARTpoints = array_keys($SARTdata);	// массив mmsi
};
// км/ч, если в RMC скорость 0, заменять на. 
// При этом не должно быть предложений GGA, потому что для них gpsd посчитает скорость по времени
// и рассоянию. Помогает --filtering=RMC
if(isset($options['updspeed'])){
	if(($updSpeed = filter_var($options['updspeed'],FILTER_SANITIZE_NUMBER_FLOAT))=='') $updSpeed = 10;
}
else $updSpeed = FALSE;
if(isset($options['wind'])){
	$wind = explode(',',$options['wind']);
	if(count($wind)<2) $wind = array(0.0,10.0);	// ветер северный
	$userWind=$wind;
	if($wind[2]) $windDeviation = $wind[2];
	if(@$wind[3]=='T') $windTrue = 'T';
	else $windTrue = 'R';
}
if(isset($options['depth'])){
	$userDepth = explode(',',$options['depth']);
	if(count($userDepth)<2) $userDepth = array(10.0,0.5);	// 
	$depth=$userDepth;
}
//echo "filtering=$filtering; saveSentences=$saveSentences; updSpeed=$updSpeed;\n"; var_dump($updSpeed);
//print_r($filtering);

if(!$argv[1] or array_key_exists('h',$options) or array_key_exists('help',$options)) {
	echo "Usage:\n  php naiveNMEAdaemon.php [-isample1.log] [-t200000] [-btcp://127.0.0.1:2222] [--run0] [--filteringGGA,GLL,GNS,RMC,VTG,GSA] [--updsat6] [--updtime]\n";
	echo "\n";
	echo "  -i list of nmea log files, default sample1.log\n";
	echo "  -t delay between the log file string sent, microsecunds (1/1 000 000 sec.), default 200000\n";
	echo "  -b bind address:port, default tcp://127.0.0.1:2222\n";
	echo "  --run overall time of work, in seconds. Default 0 - infinity.\n";
	echo "  --filtering sends only listed sentences from list GGA,GLL,GNS,RMC,VTG,VHW,GSA,HDT,ZDA,VDO,VDM... or send all sentences except in list xVDO,xVDM, or send only every n'th in list nVDO,nVDM. Default - all sentences.\n";
	echo "  --updcourse sets field 8 'Track made good' of RMC sentences as the bearing from the previous point, boolean\n";
	echo "  --updsat= sets specified number of satellites in GGA sentence if fix present, but number of satellites is 0. Default 6.\n";
	echo "  --updspeed sets field 7 'Speed over ground' of RMC sentences to the specified value if it is near zero. In km/h, real. Default no, or 10.0 if set.\n";
	echo "  --updtime sets the time in sentences to current, boolean. Default true.\n";
	echo "  --wind=direction,speed,deviation,trueWind send \$AIMWV sentences with specified true or apparent direction from N, speed and variation. 0-359 int degrees, int m/sec, real < 0, T. Default none.\n";
	echo "  --depth=depth,deviation send  \$SDDBT sentences with specified depth and variation. real depth in m, real < 0. Default none.\n";
	echo "  --savesentences writes NMEA sentences to file\n";
	echo "  --sart=SARTsample.php include AIS SART sentences for points from the SARTsample.php\n";
	echo "\n";
	if(array_key_exists('h',$options) or array_key_exists('help',$options)) return;
	echo "now run naiveNMEAdaemon.php -i$nmeaFileName -t$delay -b$bindAddres --updsat$updSat --updtime$updTime\n\n";
}


$r = array("|","/","-","\\");
$ri = 0;
$startAllTime = time();
$statCollection = array();
$default_timezone = date_default_timezone_get();
date_default_timezone_set('UTC');	// чтобы менять время в посылках

echo "\nWe'll send";
if($filtering) echo " only ".implode(',',$filtering);
if($noFiltering) echo " except (some) ".implode(',',array_keys($noFiltering));
echo " NMEA sentences";
echo " with delay $delay microsecunds between each";
if($run) echo " during $run second";
if($updSat) echo " correcting the number of visible satellites to $updSat";
if($updSat and $updTime) echo " and";
if($updTime) echo " correcting the time of message creation to now";
if(is_numeric($updTime)) echo " plus $updTime sec.";
if($updCourse) echo ", with setting the 'Track made good' of RMC sentences as the bearing from the previous point";
if($updSpeed) echo ", with setting the 'Speed over ground' of RMC sentences to ".round($updSpeed/1.852,2)." knots if it's near zero";
if(isset($wind)) {
	echo " with ";
	if($windTrue=='T') echo "true wind";
	else echo "apparent wind";
	echo " from {$wind[0]}, {$wind[1]} m/sec";
}
if(@$SARTdata) echo ", with adding AIS SART sentences for points from the $SARTdataFile file";
if($saveSentences) echo " and with writing sentences to $saveSentences";
echo ".\n\n";

$socket = stream_socket_server($bindAddres, $errno, $errstr);
if (!$socket) {
  return "$errstr ($errno)\n";
} 
echo "\nCreated streem socket server. Go to wait loop.\n";
echo "Wait for first connection on $bindAddres";
//$conn = stream_socket_accept($socket,-1);	// -1 -- бесконечно ждать соединения, как это указано в https://www.php.net/manual/en/filesystem.configuration.php#ini.default-socket-timeout, но в OpenWRT -1 - это -1, и она меньше, и оно не ждёт.
$conn = stream_socket_accept($socket,600);	// 

$nStr = 0; 	// number of sending string
$statSend = 0;
$time = ''; $date = '';	
$prevRMC = array();

if($saveSentences) $sentencesfh = fopen($saveSentences, 'w');
$handles = array();
foreach($nmeaFileNames as $i => $nmeaFileName){
	$handle = fopen($nmeaFileName, "r");
	if (FALSE === $handle) {
		echo "Failed to open file $nmeaFileName\n";
		unset($nmeaFileNames[$i]);
		continue;
	}
	$handles[] = $handle;
}
if(!$handles) exit("No logs to play, bye.\n");
echo "\rSending ".implode(',',$nmeaFileNames)." with delay {$delay}ms per string\n";
echo "\n";
$enought = array();
$windCount = $windDeviationPeriod;
$windAngle = null;
$depthCount = $depthDeviationPeriod;
$lastSARTsended = 0;
while ($conn) { 	// 
	foreach($handles as $i => $handle) {	// для каждого указанного файла строк NMEA
		if(($run AND ((time()-$startAllTime)>$run))) {
			foreach($handles as $handle) {
				fclose($handle);
			}
			echo "Timeout, go away                            \n";
			echo "Send $nStr str                         \n";
			statShow();
			break 2;
		}
		$startTime = microtime(TRUE);
		$nmeaData = trim(fgets($handle, 2048));	// без конца строки
		if(feof($handle)) { 	// достигнут конец файла
			rewind($handle);
			if($nStr) {
				echo "Send $nStr str                         \n";
				statShow();
			}
			if(is_array($enought)) {
				$enought[] = 1;
				if(count($enought) == count($handles)) {
					$enought = true;
					if($saveSentences) fclose($sentencesfh);
				}
			}
			continue;	// к следующему файлу
		}
		
		$NMEAtype = substr($nmeaData,3,3);
		//echo "NMEAtype=$NMEAtype;                                        \n";
		if($filtering) {
			if(!in_array($NMEAtype,$filtering)) continue;	// будем посылать только указанное
		}
		if($noFiltering) {	// будем посылать только не указанное
			if(@$noFiltering[$NMEAtype]){
				$noFiltering[$NMEAtype][1]++;
				//echo($noFiltering[$NMEAtype][0].' '.$noFiltering[$NMEAtype][1]."\n");
				if($noFiltering[$NMEAtype][0] != $noFiltering[$NMEAtype][1]) continue;	// или раз в указанное число раз
				$noFiltering[$NMEAtype][1] = 0;	// сбросим счётчик
			}
		}
		//echo "Filtered NMEAtype=$NMEAtype;                                        \n";
		//echo "nmeaData=$nmeaData;\n";
		//echo 'NMEAchecksumm '.NMEAchecksumm(substr($nmeaData,0,-3))."            \n";
		// Скорость есть в VTG и RMC
		// Координаты в GGA и RMC
		// fix указан в GSA (активные спутники), но там нет даты???
		
		//  Приведение времени к сейчас. Эпоху СЛЕДУЕТ начинать по RMC, и устанавливать
		// время всего остального равного времени RMC. (Есть ли более приоритетные сообщения? ZDA?)
		// Garry E. Miller утверждает, что  The PPS is the first thing to come from almost all receivers at the start of an epoch.
		// PPS (pulse per second) указывается только в GGA из стандартных сообщений.
		// При этом (для gpsd?) время GGA можно установить в пусто, но тогда информация из GGA
		// не воспринимается?
		// Если ставить время по GGA -- скорости не будет вообще, даже если она есть в RMC
		// Если у GGA и RMC будет разное время -- будет скорость по RMC, и, видимо, эпоха
		// тоже будет начинаться по RMC, в результате может оказаться, что перемещение по GGA
		// в эту эпоху будет равно 0, и gpsd выдаст TPV с нулевой скоростью. При этом о других
		// скоростях gpsd не сообщает.
		
		switch($NMEAtype){
		
		case 'GGA':
			// gpsbabel создает NMEA с выражениями GGA, в которых число используемых спутников
			// всегда равно 0.
			// gpsd считает, что если координаты есть, а спутников нет -- это ошибка, но не игнорирует
			// такое сообщение, а сообщает, что координат нет (NO FIX, "mode":1)
			// Следующий код добавляет в сообщения GGA сколько-то спутников, если их 0 и есть координаты
			
			//echo "Before|$nmeaData|\n";
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			if(!intval($nmea[7]) and $updSat and $nmea[2]!=NULL and $nmea[4]!=NULL) { 	// есть широта и долгота и нет спутников
				//echo "GGA: не указано количество спутников, исправляем          \n";
				$nmea[7] = $updSat; 	// будет столько спутников
			}
			//echo "Исходный момент привязки: {$nmea[1]}                     \n";
			//echo "GGA: time: $time                     \n";
			if($updTime) { 	//  Приведение времени к сейчас
				//$time = date('His.').str_pad(substr(round(substr(microtime(),0,10),2),2),2,'0');
				$nmea[1] = $time;
			}
			//$nmea[1] = '';
			//echo "After "; print_r($nmea);
			//echo "GGA: Lat $nmea[2],	Lon $nmea[4]                   \n";
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "GGA After |$nmeaData|                                   \n";
			break;

		case 'GLL':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			// Приведение времени к сейчас 
			if($updTime) { 	//  Приведение времени к сейчас
				//$time = date('His.').str_pad(substr(round(substr(microtime(),0,10),2),2),2,'0');
				$nmea[5] = $time;
			}
			//echo "After "; print_r($nmea);
			//echo "GLL: Lat $nmea[1],	Lon $nmea[3]                   \n";
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "After |$nmeaData|                                   \n";
			break;

		case 'GNS':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			if(!intval($nmea[7]) and $updSat and $nmea[2]!=NULL and $nmea[4]!=NULL) { 	// есть широта и долгота и нет спутников
				//echo "GNS: не указано количество спутников, исправляем          \n";
				$nmea[7] = $updSat; 	// будет столько спутников
			}
			// Приведение времени к сейчас 
			if($updTime) {
				//$time = date('His.').str_pad(substr(round(substr(microtime(),0,10),2),2),2,'0');
				$nmea[1] = $time;
			}
			//echo "After "; print_r($nmea);
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "After |$nmeaData|                                   \n";
			break;
		
		case 'RMC':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			// Хрен его знает, что это за статус, но при V gpsd это предложение игнорирует. 
			// А SignalK  -- нет.
			$nmea[2] = 'A'; 	// Status, A = Valid, V = Warning
			if($updCourse){	// исправление курса
				$prevRMC[8] = bearing(nmeaLatDegrees($prevRMC[3]),nmeaLonDegrees($prevRMC[5]),nmeaLatDegrees($nmea[3]),nmeaLonDegrees($nmea[5]));
				$tmp = $nmea;
				$nmea = $prevRMC;
				$prevRMC = $tmp;
				if(!$nmea[0]) continue 2;	// первый оборот, ещё нет всех данных
			}
			if(isset($wind) and $nmea[8]){	// вычислим направление ветра
				$windAngle = $wind[0]-$nmea[8];
				if($windAngle<0) $windAngle = 360+$windAngle;
			}
			else $windAngle = null; 
			if($updSpeed !== FALSE){	// Изменение скорости
				//echo "nmea[7]={$nmea[7]}              	\n";
				if($nmea[7]<0.001) $nmea[7] = round($updSpeed/1.852,2);
			}
			if($updTime){ 	//  Приведение времени к сейчас	
				// Время устанавливается только здесь, стало быть, предложения RMC должны быть.	
				if(is_numeric($updTime)) $time = time() + $updTime;
				else $time = time();
				$time = date('His.',$time).str_pad(substr(round(substr(microtime(),0,10),2),2),2,'0');
				$nmea[1] = $time; 	// 
				$date = date('dmy');
				$nmea[9] = $date; 	// 
			}
			//echo "RMC After "; print_r($nmea);
			//echo "RMC: Lat $nmea[3],	Lon $nmea[5]                          \n";
			$nmeaData = implode(',',$nmea);
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "RMC After |$nmeaData|                                   \n";
			break;
		
		case 'ZDA':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			if($updTime){ 	//  Приведение времени к сейчас	
				$nmea[1] = $time; 	// 
				$nmea[2] = substr($date,0,2); 	// Day
				$nmea[3] = substr($date,2,2); 	// Month
				$nmea[4] = date('Y');	// Year (4 digits)
				date_default_timezone_set($default_timezone);	// 
				[$nmea[5],$nmea[6]] = explode(':',date('P'));	// 
				date_default_timezone_set('UTC');	// 
			}
			//echo "ZDA After "; print_r($nmea);
			$nmeaData = implode(',',$nmea);
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			break;
		/*
		case 'VTG':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			//echo "After "; print_r($nmea);
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "After |$nmeaData|                                   \n";
			break;
		case 'GSA':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			//echo "After "; print_r($nmea);
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "After |$nmeaData|                                   \n";
			break;
		*/
		default:
		};
		if( !sendNMEA($nmeaData)) break;	// отошлём сообщение NMEA клиенту, если проблема - прекратим работу

		if($windAngle){	// добавим ветер после отсылки каждого сообщения RMC
			
			if($windCount<=0){	// случайно изменим ветер
				if($wind[0]>($userWind[0]+$userWind[0]*$windDeviation)){	// изменилось больше, чем требуется
					$wind[0] = $wind[0]-$windDirDelta;
					if($wind[0]<0) $wind[0] += 360;
				}
				elseif($wind[0]<($userWind[0]-$userWind[0]*$windDeviation)){
					$wind[0] = $wind[0]+$windDirDelta;
					if($wind[0]>=360) $wind[0] -= 360;
				}
				else {			
					if(rand()%2) {
						$wind[0] = $wind[0]+$windDirDelta;
						if($wind[0]>=360) $wind[0] -= 360;
					}
					else {
						$wind[0] = $wind[0]-$windDirDelta;
						if($wind[0]<0) $wind[0] += 360;
					}
				}
				
				if($wind[1]>($userWind[1]+$userWind[1]*$windDeviation)){	// изменилось больше, чем требуется
					$wind[1] = $wind[1]-$windSpeedDelta;
					if($wind[1]<0) $wind[1] = 0;
				}
				elseif($wind[1]<($userWind[1]-$userWind[1]*$windDeviation)){
					$wind[1] = $wind[1]+$windSpeedDelta;
					if($wind[1]>40) $wind[1] = 40;
				}
				else {
					if(rand()%2) {
						$wind[1] = $wind[1]+$windSpeedDelta;
						if($wind[1]>40) $wind[1] = 40;
					}
					else {
						$wind[1] = $wind[1]-$windSpeedDelta;
						if($wind[1]<0) $wind[1] = 0;
					}
				}
				$windCount = $windDeviationPeriod;
			}
			//echo "wind direction {$wind[0]}; wind speed {$wind[1]};      \n";

			// Это баг gpsd: N вместо М и скорости в м/сек.
			// м/сек gpsd вообще не понимает? Во всяком случает, оно работает правильно, если
			// указать N и скорость в узлах.
			//$nmeaData = "\$WIMWV,$windAngle,R,".($wind[1]*1.943844494).",N,A";
			//$nmeaData = "\$WIMWV,$windAngle,T,".($wind[1]*1.943844494).",N,A";
			$nmeaData = "\$WIMWV,$windAngle,$windTrue,".($wind[1]*1.943844494).",N,A";
			// Правильное выражение:	
			//$nmeaData = "\$WIMWV,$windAngle,R,{$wind[1]},M,A";	
			//$nmeaData = "\$WIMWV,$windAngle,T,{$wind[1]},M,A";	
			//$nmeaData = "\$WIMWV,$windAngle,$windTrue,{$wind[1]},M,A";	
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			$windCount--;
			if( !sendNMEA($nmeaData)) break;	// отошлём сообщение NMEA клиенту
		}
	
		if(isset($options['depth'])){	// добавим глубину
				
			if($depthCount<=0){	// случайно изменим глубину	
				if($depth[0]>($userDepth[0]+$userDepth[0]*$userDepth[1])){	// изменилось больше, чем требуется
					$depth[0] = $depth[0]-$depthDelta;
					if($depth[0]<0) $depth[0] = 0;
				}
				elseif($depth[0]<($userDepth[0]-$userDepth[0]*$userDepth[1])){
					$depth[0] = $depth[0]+$depthDelta;
				}
				else {			
					if(rand()%2) {
						$depth[0] = $depth[0]+$userDepth[1];
					}
					else {
						$depth[0] = $depth[0]-$userDepth[1];
					if($depth[0]<0) $depth[0] = 0;
					}
				}
				$depthCount = $depthDeviationPeriod;
			}
			//echo "depth {$depth[0]};      \n";
			$nmeaData = "\$SDDBT,,f,{$depth[0]},M,,F";	
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			$depthCount--;
			if( !sendNMEA($nmeaData)) break;	// отошлём сообщение NMEA клиенту
			
		};
		
		// добавим сообщения AIS SART раз в минуту пачками по 8 штук для каждой точки,
		// по точке за оборот
		if(@$SARTdata and ((time()-$lastSARTsended)>=60)){	
			$SARTpoint = array_shift($SARTpoints);
			//echo "SARTpoint=$SARTpoint                      \n";
			//print_r($SARTpoints);
			if($SARTpoint) {	// есть точка для отсылки
				//echo "SARTpoint=$SARTpoint;                         \n"; 
				$SARTdata[$SARTpoint]['timestamp'] = time();
				//print_r($SARTdata[$SARTpoint]);
				$AISsentencies = toAISphrases($SARTdata[$SARTpoint],'TPV','SART');
				for($i=0; $i<8; $i++){
					foreach($AISsentencies as $AISsentencie){
						//echo "SART AISsentencie=$AISsentencie;\n";
						if( !sendNMEA($AISsentencie)) break;	// отошлём сообщение NMEA клиенту
					};
				};
			}
			else{	// назначим новую паузу
				$lastSARTsended = time();
				$SARTpoints = array_keys($SARTdata);	// массив mmsi
			};
		};

		/*
		// Периодически будем показывать, какие сентенции были
		if(($nStr-$statSend)>9) {
			statShow();
			$statSend = $nStr;
		}
		*/
		$endTime = microtime(TRUE);
		$nStr++;
		echo($r[$ri]);	// вращающаяся палка
		echo " " . ($endTime-$startTime) . " str# $nStr";
		if($windAngle) echo ", wind: $windTrue dir {$wind[0]}, speed {$wind[1]}";
		if(isset($options['depth'])) echo " depth ".round($depth[0],1);
		echo "   \r";
		$ri++;
		if($ri>=count($r)) $ri = 0;
		usleep($delay);
	};
}
foreach($handles as $handle) {
	fclose($handle);
}
fclose($socket);
if($saveSentences) @fclose($sentencesfh);

function statCollect($nmeaData) {
/**/
global $statCollection;
$nmeaData1 = substr(trim(str_getcsv($nmeaData)[0]),-3);
//if(mb_strlen($nmeaData1,'8bit')<3) echo "\n$nmeaData\n";
@$statCollection["$nmeaData1"]++;	// при отсутствии ключа оно выдаёт предупреждение, поэтому @
/*
if(strpos($nmeaData,'ALM')!==FALSE) $statCollection['ALM']++;
elseif(strpos($nmeaData,'AIVDM')!==FALSE) $statCollection['AIVDM']++;
elseif(strpos($nmeaData,'AIVDO')!==FALSE) $statCollection['AIVDO']++;
elseif(strpos($nmeaData,'DBK')!==FALSE) $statCollection['DBK']++;
elseif(strpos($nmeaData,'DBS')!==FALSE) $statCollection['DBS']++;
elseif(strpos($nmeaData,'DBT')!==FALSE) $statCollection['DBT']++;
elseif(strpos($nmeaData,'DPT')!==FALSE) $statCollection['DPT']++;
elseif(strpos($nmeaData,'GGA')!==FALSE) $statCollection['GGA']++;
elseif(strpos($nmeaData,'GLL')!==FALSE) $statCollection['GLL']++;
elseif(strpos($nmeaData,'GNS')!==FALSE) $statCollection['GNS']++;
elseif(strpos($nmeaData,'GSV')!==FALSE) $statCollection['GSV']++;
elseif(strpos($nmeaData,'HDG')!==FALSE) $statCollection['HDG']++;
elseif(strpos($nmeaData,'HDM')!==FALSE) $statCollection['HDM']++;
elseif(strpos($nmeaData,'HDT')!==FALSE) $statCollection['HDT']++;
elseif(strpos($nmeaData,'MTW')!==FALSE) $statCollection['MTW']++;
elseif(strpos($nmeaData,'MWV')!==FALSE) $statCollection['MWV']++;
elseif(strpos($nmeaData,'RMA')!==FALSE) $statCollection['RMA']++;
elseif(strpos($nmeaData,'RMB')!==FALSE) $statCollection['RMB']++;
elseif(strpos($nmeaData,'RMC')!==FALSE) $statCollection['RMC']++;
elseif(strpos($nmeaData,'VHW')!==FALSE) $statCollection['VHW']++;
elseif(strpos($nmeaData,'VWR')!==FALSE) $statCollection['VWR']++;
elseif(strpos($nmeaData,'ZDA')!==FALSE) $statCollection['ZDA']++;
elseif(strpos($nmeaData,'PGRMZ')!==FALSE) $statCollection['PGRMZ']++;
elseif($nmeaData) $statCollection['other']++;
*/
} 	// end function statCollect

function sendNMEA($nmeaData){
global $conn,$socket,$saveSentences,$enought,$run,$sentencesfh;
if($saveSentences and (($enought !== true) or $run) ) $res = fwrite($sentencesfh, $nmeaData."\n");	// сохраним в файл, из-за $enought будет сохранён только один комплект предложений из всех файлов

//echo "nmeaData=|$nmeaData|               \n";
statCollect($nmeaData);
//$res = fwrite($conn, $nmeaData . "\r\n");
$res = fwrite($conn, $nmeaData."\n");
if($res===FALSE) {
	echo "Error write to socket. Break connection\n";
	fclose($conn);
	echo "Try to reopen\n";
	$conn = stream_socket_accept($socket,10);
	if(!$conn) {
		echo "Reopen false\n";
		return false;
	}
}
return true;
} // end function sendNMEA()

function statShow() {
/**/
global $statCollection;
ksort($statCollection);
echo "Messages have been sent:                                   \n";
foreach($statCollection as $code => $count){
	echo "$code: $count\n";
}
echo "\n";
//$statCollection = array();
} // end statShow

function NMEAchecksumm($nmea){
/**/
if(!(is_string($nmea) and $nmea[0]=='$')) return FALSE; 	// only not AIS NMEA string
$checksum = 0;
for($i = 1; $i < mb_strlen($nmea,'8bit'); $i++){
	if($nmea[$i]=='*') break;
	$checksum ^= ord($nmea[$i]);
}
$checksum = str_pad(strtoupper(dechex($checksum)),2,'0',STR_PAD_LEFT);
return $checksum;
} // end function NMEAchecksumm

function bearing($lat1,$lon1,$lat2,$lon2) {
/* азимут направления между двумя точками, пеленг */

$lat1 = deg2rad($lat1);
$lon1 = deg2rad($lon1);
$lat2 = deg2rad($lat2);
$lon2 = deg2rad($lon2);
$y = sin($lon2 - $lon1) * cos($lat2);
$x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lon2 - $lon1);
//echo "x=$x,y=$y";
$bearing = (rad2deg(atan2($y, $x)) + 360) % 360;
//echo "bearing=$bearing;              \n";
if($bearing >= 360) $bearing = $bearing-360;
/*
http://makinacorpus.github.io/Leaflet.GeometryUtil/leaflet.geometryutil.js.html#line689
$rad = M_PI/180;
$lat1 = $lat1 * $rad;
$lat2 = $lat2 * $rad;
$lon1 = $lon1 * $rad;
$lon2 = $lon2 * $rad;

$y = sin($lon2 - $lon1) * cos($lat2);
$x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lon2 - $lon1);

$bearing = ((atan2($y, $x) * 180 / M_PI) + 360) % 360;
if($bearing >= 180) $bearing = $bearing-360;
*/
return $bearing;
} // end function bearing

function nmeaLatDegrees($nmeaDegStr){
$dd = (int)substr($nmeaDegStr,0,2);	// градусы
$mm = (float)substr($nmeaDegStr,2);	// минуты
//echo "nmeaLatDegrees ".($dd + $mm/60)."\n";
return $dd + $mm/60;
} // end function nmeaDegrees

function nmeaLonDegrees($nmeaDegStr){
$dd = (int)substr($nmeaDegStr,0,3);	// градусы
$mm = (float)substr($nmeaDegStr,3);	// минуты
//echo "nmeaLonDegrees ".($dd + $mm/60)."\n";
return $dd + $mm/60;
} // end function nmeaDegrees

?>
