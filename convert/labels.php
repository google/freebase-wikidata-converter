<?php

/**
 * Copyright 2015 Google Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS-IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require __DIR__ . '/lib.php';

$stats = [
		'mapped-topic-labels' => ['*' => 0],
		'new-labels' => ['*' => 0],
		'existing-labels' => ['*' => 0],
		'missing-data' => 0
];
function addToStat($key, $language) {
	global $stats;

	$stats[$key]['*']++;
	if(array_key_exists($language, $stats[$key])) {
		$stats[$key][$language]++;
	} else {
		$stats[$key][$language] = 1;
	}
}

$wikidataLabels = [];

if(file_exists($argv[4] . '/wikidata-labels-languages.tsv')) {
	echo "\nLoading Wikidata labels languages\n";
	
	$file = fopen($argv[4] . '/wikidata-labels-languages.tsv', 'r');
	while($line = fgets($file)) {
		list($qid, $languages) = explode("\t", trim($line), 2);
		$wikidataLabels[$qid] = explode(' ', $languages);
	}
	fclose($file);
} else {
	echo "\nCreating Wikidata labels languages list\n";

	$input = fopen($argv[1], 'r');
	$count = 0;
	while($line = fgets($input)) {
		$line = trim($line, ", \n\t\r");
		if($line === '' || $line[0] !== '{' ) {
			continue;
		}

		$json = json_decode($line, true);
		if(array_key_exists('labels', $json)) {
			$wikidataLabels[$json['id']] = array_keys($json['labels']);
		}
	
		$count++;
		if($count % 100000 === 0) {
			echo '.';
		}
	}
	fclose($input);
	
	echo "\nSaving Wikidata labels languages\n";
	$file = fopen($argv[4] . '/wikidata-labels-languages.tsv', 'w');
	foreach($wikidataLabels as $qid => $languages) {
		fwrite($file, $qid . "\t" . implode(' ', $languages) . "\n");
	}
	fclose($file);
}

$input = fopen($argv[2], 'r');
$output = fopen($argv[4] . '/freebase-new-labels.tsv', 'w');


$LANGUAGE_CODE_CONVERSION = [
	'iw' => 'he',
	'pt-pt' => 'pt', //We use pt for Portugal Portuguese
	'fil' => 'tl', //Filipino is a standardized version of Tagalog
	'es-419' => 'es', //W don't have Latin America Spanish
	'en-us' => 'en',
	//TODO: expend?
];

//Count types
$count = 0;
$mapping = new Mapping(new DummyCVTProvider(), new DummyReviewedFacts(), $argv[3]);
echo "\nCreating missing labels list\n";
while($line = fgets($input)) {
	list($mid, $labelSer) = explode("\t", trim($line));
	
	$parts = explode('@', $labelSer);
	if(count($parts) === 2) {
		list($label, $language) = $parts;
	} else {
		$label = $parts[0];
		for($i = 1; $i < count($parts) - 1; $i++) {
			$label .= '@' . $parts[$i];
		}
		$language = $parts[$i];
	}
	$language = strtolower($language);

	if(array_key_exists($language, $LANGUAGE_CODE_CONVERSION)) {
		$language = $LANGUAGE_CODE_CONVERSION[$language];
	}
	if($language === 'no' | $language === 'zh-hant') {
		continue; //TODO: what should we do with these two languages?
	}

	if(!$mapping->isFreebaseMapped($mid)) {
		continue;
	}
	addToStat('mapped-topic-labels', $language);
	$qid = $mapping->mapMid($mid);

	if(!array_key_exists($qid, $wikidataLabels)) {
		$stats['missing-data']++;
		continue;
	}
	if(in_array($language, $wikidataLabels[$qid])) {
		addToStat('existing-labels', $language);
		continue;
	}

	fwrite($output, $qid . "\t" . $language . "\t" . $label . "\n");
	addToStat('new-labels', $language);

	$count++;
	if($count % 100000 === 0) {
		echo '.';
	}
}

arsort($stats['mapped-topic-labels']);
arsort($stats['new-labels']);
arsort($stats['existing-labels']);

print_r($stats);