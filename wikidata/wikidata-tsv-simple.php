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

class TsvConverter {

	public function convertStatement($statement) {
		$tsv = $this->convertSnak($statement['mainsnak'], false);

		if(array_key_exists('qualifiers', $statement)) {
			$tsv .= "\t" . $this->convertSnaksBag($statement['qualifiers'], false);
		}

		return $tsv;
	}

	private function convertSnaksBag($bag, $isSource) {
		$tsv = '';
		foreach($bag as $snaks) {
			foreach($snaks as $snak) {
				$tsv .= "\t" . $this->convertSnak($snak, $isSource);
			}
		}
		return $tsv;
	}

	private function convertSnak($snak, $isSource) {
		$tsv = $isSource ? str_replace('P', 'S', $snak['property']) : $snak['property'];

		if($snak['snaktype'] === 'value') {
			return $tsv . "\t" . $this->convertValue($snak['datavalue']);
		} else {
			return $tsv . "\t" . $snak['snaktype'];
		}
	}

	private function convertValue($datavalue) {
		$value = $datavalue['value'];
		switch($datavalue['type']) {
			case 'globecoordinate':
				return '@' . $value['latitude'] . '/' . $value['longitude'];
			case 'monolingualtext':
				return $value['language'] . ':"' . str_replace(["\n", '"'], [' ', ' '], $value['text']) . '"';
			case 'quantity':
				return $value['amount'];
			case 'string':
				return '"' . str_replace(["\n", '"'], [' ', ' '], $value) . '"';
			case 'time':
				return $value['time'] . '/' . $value['precision'];
			case 'wikibase-entityid':
				switch($value['entity-type']) {
					case 'item':
						return 'Q' . $value['numeric-id'];
					case 'property':
						return 'P' . $value['numeric-id'];
				}
		}
	}
}

$input = fopen($argv[1], 'r');
$output = fopen($argv[2], 'w');

$count = 0;
$converter = new TsvConverter();
while($line = fgets($input)) {
	$line = trim($line, ", \n\t\r");
	if($line === '' || $line[0] !== '{' ) {
		continue;
	}

	$json = json_decode($line, true);
	if(!array_key_exists('claims', $json)) {
		continue;
	}
	foreach($json['claims'] as $claims) {
		foreach($claims as $statement) {
			fwrite($output, $json['id'] . "\t" . $converter->convertStatement($statement) . "\n");
		}
	}

	$count++;
	if($count % 100000 === 0) {
		echo '.';
	}
}

fclose($input);
fclose($output);
