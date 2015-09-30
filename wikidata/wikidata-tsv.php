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
		$mainSnaks = $this->convertSnak($statement['mainsnak'], false);
		$tsvs = [];

		if(array_key_exists('qualifiers', $statement)) {
			if($this->countSnakBag($statement['qualifiers']) > 5) {
				$qualifiersToAdd = [$this->convertSnaksBag($statement['qualifiers'], false)];
				//we avoid the exponential cost
			} else {
				$qualifiersToAdd = $this->convertQualifiers($statement['qualifiers']);
			}

			foreach($qualifiersToAdd as $qualifiers) {
				foreach($mainSnaks as $mainSnak) {
					$tsvs[] = array_merge([$mainSnak], $qualifiers);
				}
			}
		} else {
			foreach($mainSnaks as $mainSnak) {
				$tsvs[] = [$mainSnak];
			}
		}

		if(array_key_exists('references', $statement)) {
			$size = count($tsvs);
			foreach($statement['references'] as $reference) {
				$reference = $this->convertSnaksBag($reference['snaks'], true);
				for($i = 0; $i < $size; $i++) {
					$tsvs[] = array_merge($tsvs[$i], $reference);
				}
			}
		}

		return $tsvs;
	}

	private function countSnakBag($snakBag) {
		$count = 0;
		foreach($snakBag as $snaks) {
			$count += count($snaks);
		}
		return $count;
	}

	private function convertQualifiers($snakBag) {
		$tsvs = [[]];
		foreach($snakBag as $snaks) {
			foreach($snaks as $snak) {
				$serialisations = $this->convertSnak($snak, false);
				$size = count($tsvs);
				for($i = 0; $i < $size; $i++) {
					foreach($serialisations as $serial) {
						$tsv = $tsvs[$i];
						$tsv[] = $serial;
						$tsvs[] = $tsv;
					}

				}
			}
		}

		foreach($tsvs as $key => $tsv) {
			sort($tsvs[$key]);
		}

		return $tsvs;
	}

	private function convertSnaksBag($bag, $isSource) {
		$tsv = [];
		foreach($bag as $snaks) {
			foreach($snaks as $snak) {
				$snaks = $this->convertSnak($snak, $isSource);
				if(!empty($snaks)) {
					$tsv[] = $snaks[0];
				}
			}
		}
		sort($tsv);
		return $tsv;
	}

	private function convertSnak($snak, $isSource) {
		if($snak['snaktype'] !== 'value') {
			return [];
		}

		$base = $isSource ? str_replace('P', 'S', $snak['property']) : $snak['property'];

		if($base === 'P39') {
			$bases = ['P39', 'P97']; //Bad hack for https://www.freebase.com/royalty/noble_title_tenure/noble_title
		} else {
			$bases = [$base];
		}
		$tsvs = [];
		foreach($this->convertValue($snak['datavalue']) as $value) {
			foreach($bases as $base) {
				$tsvs[] = $base . "\t" . $value;
			}
		}

		return $tsvs;
	}

	private function convertValue($datavalue) {
		$value = $datavalue['value'];
		switch($datavalue['type']) {
			case 'globecoordinate':
				return ['@' . $value['latitude'] . '/' . $value['longitude']]; //TODO: proches
			case 'monolingualtext':
				return [$value['language'] . ':"' . str_replace(["\n", '"'], [' ', ' '], $value['text']) . '"'];
			case 'quantity':
				return [$value['amount']];
			case 'string':
				return ['"' . str_replace(["\n", '"'], [' ', ' '], $value) . '"'];
			case 'time':
				$match = [];
				$times = [$value['time'] . '/' . $value['precision']];
				if($value['precision'] > 10) {
					if(preg_match('/([+-]\d+\-\d{2})\-/', $value['time'], $match)) {
						$times[] = $match[1] . '-00T00:00:00Z/10';
					}
				}
				if($value['precision'] > 9) {
					if(preg_match('/([+-]\d+)\-/', $value['time'], $match)) {
						$times[] = $match[1] . '-00-00T00:00:00Z/9';
					}
				}
				return $times;
			case 'wikibase-entityid':
				switch($value['entity-type']) {
					case 'item':
						return ['Q' . $value['numeric-id']];
					case 'property':
						return ['P' . $value['numeric-id']];
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
			foreach($converter->convertStatement($statement) as $tsv) {
				fwrite($output, $json['id'] . "\t" . implode("\t", $tsv) . "\n");
			}
		}
	}

	$count++;
	if($count % 100000 === 0) {
		echo '.';
	}
}

fclose($input);
fclose($output);
