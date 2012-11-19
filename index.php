<?php
require_once 'google-api/Google_Client.php';
require_once 'google-api/contrib/Google_CalendarService.php';

error_reporting(E_ALL);
session_start();

$client = new Google_Client();
$client->setApplicationName("aLAZYsis");

// Visit https://code.google.com/apis/console?api=calendar to generate your
// client id, client secret, and to register your redirect uri.
$client->setClientId('881243075742.apps.googleusercontent.com');
$client->setClientSecret('xBRiQQeDPjBF1hR6PO71ju0h');
$client->setRedirectUri('http://t0.0l.de/aLAZYsis');
$client->setDeveloperKey('AIzaSyCgi7GeXk0FQpYgROFnVmd5lG0_t1USM-M');
$service['cal'] = new Google_CalendarService($client);

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
	$sum = array('total' => 0);
	$active = array();

	$calBlacklist = array(
		"#contacts@group.v.calendar.google.com",
		"#weeknum@group.v.calendar.google.com",
		"#holiday@group.v.calendar.google.com",
		"#weather@group.v.calendar.google.com"
	);

	// filter calendars
	$calList = array_filter($service['cal']->calendarList->listCalendarList()->getItems(), function($cal) {
		global $calBlacklist;
		global $sum;
		global $active;

		foreach ($calBlacklist as $black) {
			if (strpos($cal->getId(), $black) !== false) {
				return false;
			}
		}

		$sum[$cal->getId()] = 0;
		$active[$cal->getId()] = 0;

		return true;
	});

	// fetch events
	$timezone = new DateTimeZone($service['cal']->settings->get('timezone')->getValue());
	$midnight = new DateTime("today", $timezone);
	$tomorrow = new DateTime("tomorrow", $timezone);

	$options = array(
		'timeMin' => date('c', $midnight->getTimestamp()),
		'timeMax' => date('c', $tomorrow->getTimestamp()),
		'singleEvents' => true
	);

	// parse & aggregate event list
	$stack = array();
	foreach ($calList as $cal) {
		$pageToken = '';
		do {
			$events = $service['cal']->events->listEvents($cal->getId(),
				($pageToken) ? array('pageToken' => $pageToken) : $options);

			if ($events->getItems()) {
				foreach ($events->getItems() as $item) {
					if ($item->getStart()->getDateTime()) {
						$event['calId'] = $cal->getId();
						$event['summary'] = $item->getSummary();

						$event['action'] = 'start';
						$event['ts'] = new DateTime($item->getStart()->getDateTime());
						array_push($stack, $event);

						$event['action'] = 'end';
						$event['ts'] = new DateTime($item->getEnd()->getDateTime());
						array_push($stack, $event);
					}
				}
			}

			$pageToken = $events->getNextPageToken();
		} while ($pageToken);

	}

	// sort according to event start time, then end time
	usort($stack, function($a, $b) {
		if ($a['ts'] == $b['ts']) return 0;
		else return ($a['ts'] < $b['ts']) ? -1 : 1;
	});


	// start analysis
	for ($i = 0; $i < count($stack); $i++) {
		$current = $stack[$i];

		if ($i >= 1) {
			$filtered = array_filter($active);
			$count = count($filtered);

			$prev = $stack[$i-1];
			$diff = $current['ts']->getTimestamp() - $prev['ts']->getTimestamp();

			foreach (array_keys($filtered) as $calId) {
				$sum[$calId] += $diff / $count;
			}

			if ($count > 0) {
				$sum['total'] += $diff;
			}
		}

		if ($current['action'] == 'start') {
			$active[$current['calId']]++;
		}

		if ($current['action'] == 'end') {
			$active[$current['calId']]--;
		}
	}


	$json = array();
	foreach ($sum as $id => $hours) {
		if ($id == 'total') continue;

		array_push($json, array($id, $hours/3600));
	}

	header('Content-Type: application/json');
	echo json_encode($json);

	$_SESSION['token'] = $client->getAccessToken();
} else {
	header('Location: ' . $client->createAuthUrl());
}

function print_pre($data) {
	echo "<pre>" . print_r($data, true) . "</pre>";
}
