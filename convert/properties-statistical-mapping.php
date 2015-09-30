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

//Property statistical mapping
//Only works with simple item -> item properties


$wikidataValues = []; //Gives the properties for each (subject, object)
$correlations = []; //gives for each Freebase property the number of same (subject, object) for each Wikidata property
$occurences = []; //Number of occurencies of each Freebase property

echo "\nLoad Wikidata\n";
$count = 0;
$input = fopen($argv[1], 'r');
while($line = fgets($input)) {
	$count++;
	if($count % 1000000 === 0) {
		echo '.';
	}

	$parts = explode("\t", trim($line));
	if(count($parts) !== 3) {
		continue;
	}
	list($s, $p, $o) = $parts;

	$key = $s . '#' . $o;
	if(array_key_exists($key, $wikidataValues)) {
		$wikidataValues[$key] .= "\t" . $p;
	} else {
		$wikidataValues[$key] = $p;
	}
}
fclose($input);


echo "\nLoad Freebase and compute\n";
$count = 0;
$input = fopen($argv[2], 'r');
$mapping = new Mapping(new DummyCVTProvider(), new DummyReviewedFacts(), $argv[3]);
$isoDateParser = new ValueParsers\IsoTimestampParser(
	new ValueParsers\CalendarModelParser(new ValueParsers\ParserOptions()),
	new ValueParsers\ParserOptions()
);

while($line = fgets($input)) {
	$count++;
	if($count % 1000000 === 0) {
		echo '.';
	}

	list($s, $p, $o) = explode("\t", trim($line, " .\t\n\r\0\x0B"), 3);

	$s = substr($s, 28, -1);
	if(!$mapping->isFreebaseMapped($s)) {
		continue;
	}
	$s = $mapping->mapMid($s);

	$p = substr($p, 24, -1);

	//Format object
	if($o[0] === '"') {
		if(preg_match('/^"(.+)"(@en)?$/', $o, $matches)) {
			$o = '"' . str_replace(["\n", '"'], [' ', ' '], $matches[1]) . '"';
		} elseif(preg_match('/"(.+)"\^\^<([^<>]+)>/', $o, $matches)) {
			$value = $matches[1];
			switch($matches[2]) {
				case 'http://www.w3.org/2001/XMLSchema#gYear':
					$value .= '-00';
				case 'http://www.w3.org/2001/XMLSchema#gYearMonth': // FALLTHROUGH
					$value .= '-00';
				case 'http://www.w3.org/2001/XMLSchema#date': // FALLTHROUGH
					$value .= 'T00:00:00Z';
				case 'http://www.w3.org/2001/XMLSchema#dateTime':
					try {
						$parsedValue = $isoDateParser->parse($value);
					} catch(\ValueParsers\ParseException $e) {
						continue;
					}
					$o = $parsedValue->getTime() . '/' . $parsedValue->getPrecision();
			}
		} else {
			continue;
		}
	} elseif(strpos($o, '<http://rdf.freebase.com/ns') === 0) {
		$o = substr($o, 28, -1);
		if(!$mapping->isFreebaseMapped($o)) {
			continue;
		}
		$o = $mapping->mapMid($o);
	} else {
		continue;
	}

	$key = $s . '#' .  $o;
	if(array_key_exists($key, $wikidataValues)) {
		$wdProps = array_unique(explode("\t", $wikidataValues[$key]));
		foreach($wdProps as $wdProp) {
			$correlations[$p][$wdProp] += 1;
		}
	}
	$occurences[$p] += 1;
}
fclose($input);


//Create the mapping
$mostProbableProperty = [];
$frequency = [];
$matchingAmount = [];
foreach($correlations as $fProp => $wdProps) {
	arsort($wdProps);
	$amount = reset($wdProps);
	$freq = $amount / $occurences[$fProp];
	if($freq < 0.1 || $amount < 10) {
		continue; //Filter very bad guess
	}
	$matchingAmount[$fProp] = $amount;
	$frequency[$fProp] = $freq;
	$mostProbableProperty[$fProp] = key($wdProps);
}


//Compare with existing mapping
$compareProps = 0;
$diffProps = 0;
foreach($mostProbableProperty as $fProp => $wdProp) {
	if($mapping->isPropertyMapped($fProp)) {
		$compareProps++;
		$existingWdProp = $mapping->mapFreebaseProperty('<http://rdf.freebase.com' . $fProp . '>');
		if($existingWdProp instanceof WikidataProperty && $existingWdProp->getPid() !== $wdProp) {
			echo "Conflict for property $fProp: $wdProp with frequency {$frequency[$fProp]} instead of {$existingWdProp->getPid()}\n";
			$diffProps++;
		}
	}
}
echo "$compareProps compared and $diffProps are different\n";


//Output the other suggestions
$output = fopen($argv[4], 'w');
arsort($matchingAmount);
fputcsv($output, ['Freebase property', 'Wikidata property', 'Matching frequency', 'Number of matched']);
foreach($matchingAmount as $fProp => $amount) {
	if($mapping->isPropertyMapped($fProp)) {
		continue;
	}
	fputcsv($output, ['https://www.freebase.com/' . str_replace('.', '/', explode('/', $fProp)[2]), 'https://www.wikidata.org/entity/' . $mostProbableProperty[$fProp], $frequency[$fProp], $amount]);
}
fclose($output);
