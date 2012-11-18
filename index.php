<?php
require_once 'google-api/Google_Client.php';
require_once 'google-api/contrib/Google_CalendarService.php';

session_start();

$client = new Google_Client();
$client->setApplicationName("Google Calendar PHP Starter Application");

// Visit https://code.google.com/apis/console?api=calendar to generate your
// client id, client secret, and to register your redirect uri.
$client->setClientId('881243075742.apps.googleusercontent.com');
$client->setClientSecret('xBRiQQeDPjBF1hR6PO71ju0h');
$client->setRedirectUri('http://www.steffenvogel.de/demos/lazymeter');
$client->setDeveloperKey('AIzaSyCgi7GeXk0FQpYgROFnVmd5lG0_t1USM-M');
$cal = new Google_CalendarService($client);

if (isset($_GET['logout'])) {
	unset($_SESSION['token']);
}

if (isset($_GET['code'])) {
	$client->authenticate($_GET['code']);
	$_SESSION['token'] = $client->getAccessToken();
	header('Location: http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
}

if (isset($_SESSION['token'])) {
	$client->setAccessToken($_SESSION['token']);
}

if ($client->getAccessToken()) {
	$busy = array();
	$calItems = array();
	$calBlacklist = array(
		"#contacts@group.v.calendar.google.com",
		"#weeknum@group.v.calendar.google.com",
		"#holiday@group.v.calendar.google.com",
		"#weather@group.v.calendar.google.com"
	);

	// filter calendars
	$calList = array_filter($cal->calendarList->listCalendarList()['items'], function($cal) use ($calBlacklist) {
		foreach ($calBlacklist as $black) {
			if (strpos($cal['id'], $black) !== false) {
				return false;
			}
		}

		return true;
	});

	// fetch free/busy times
	foreach ($calList as $calendar) {
		$item = new Google_FreeBusyRequestItem();
		$item->setId($calendar['id']);
		array_push($calItems, $item);
	}

	$fbReq = new Google_FreeBusyRequest();
	$timezone = new DateTimeZone($cal->settings->get('timezone')['value']);
	$midnight = new DateTime("today", $timezone);
	$tomorrow = new DateTime("tomorrow", $timezone);

	$fbReq->setTimeMin(date('c', $midnight->getTimestamp()));
	$fbReq->setTimeMax(date('c', $tomorrow->getTimestamp()));
	$fbReq->setItems($calItems);

	$fb = $cal->freebusy->query($fbReq);

	// aggregate free/busy events
	foreach ($fb['calendars'] as $id => $cal) {
		array_walk($cal['busy'], function(&$entry) use ($id) {
			$entry['cal'] = $id;

			// convert rfc datetime strings to unix timestamps
			$entry['start_ts'] = new DateTime($entry['start']);
			$entry['end_ts'] = new DateTime($entry['end']);
		});

		$busy = array_merge($busy, $cal['busy']);
	}

	// filter full-time events
	$busy = array_filter($busy, function() {
		return true;
	});

	// sort with start time
	usort($busy, function($a, $b) {
		if ($a['start_ts'] == $b['start_ts']) return 0;
		else return ($a['start_ts'] < $b['start_ts']) ? -1 : 1;
	});

	$duration = 0;
	for ($i = 0; $i < count($busy); $i++) {
		$start = $busy[$i]['start_ts']->getTimestamp();
		$end = $busy[$i]['end_ts']->getTimestamp();

		for ($j = $i+1; $j < count($busy); $j++) {
			if ($busy[$j]['start_ts'] <= $end && $busy[$j]['end_ts'] > $end) {
				echo "found overlap between $i and $j<br/>";

				$end = $busy[$j]['end_ts'];
				$i = $j;
			}
		}

		$diff = $end - $start;

		echo "adding $i with dur = " . $diff . "<br />";

		$duration += $diff;
	}

	$sleep = 7*60*60;
	$awake = $duration - $sleep;

	$percentage = $awake / (24*60*60-$sleep);

	print "<h1>Busy</h1>";
	print_pre($busy);
	print "<h1>Result</h1>
		<ul>
			<li>duration: " . $duration / 3600 . " hours</li>
			<li>awake: " . $awake / 3600 . " hours</li>
			<li>percentage: " . ($percentage*100) . " %</li>
		</ul>";


	$_SESSION['token'] = $client->getAccessToken();
} else {
	header('Location: ' . $client->createAuthUrl());
}

function print_pre($data) {
	echo "<pre>" . print_r($data, true) . "</pre>";
}
