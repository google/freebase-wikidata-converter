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

class Reconciliation {
	public $stats = [
		'wikidata-ids' => 0,
		'freebase-keys' => 0,
		'conflict' => 0,
		'matched-all' => 0,
		'matched' => [],
		'mapped' => 0
	];
	private $propertyMapping;

	public function __construct() {
		$this->propertyMapping = $this->getPropertyMapping();
	}

	public function run($wikidataFileName, $freebaseFileName, $outputFileName) {
		echo "Get Wikidata ids\n";
		$wikidataIds = $this->getWikidataIds($wikidataFileName);
		echo "Get Freebase keys\n";
		$freebaseKeys = $this->getFreebaseKeys($freebaseFileName);
		echo "Do mapping\n";
		$mapping = $this->createMapping($freebaseKeys, $wikidataIds);
		echo "Save mapping\n";
		$this->saveMapping($mapping, $outputFileName);
	}

	private function getPropertyMapping() {
		$pids = [];

		$wikitext = file_get_contents('https://www.wikidata.org/wiki/Wikidata:WikiProject_Freebase/Mapping?action=raw');
		$mapping = [];
		$out = [];

		list($nsPart, $keyPart) = explode('(/key/ namespace)', $wikitext);

		preg_match_all('/\|\-\n *\| *https?:\/\/www\.freebase\.com\/([a-zA-Z0-9\/_\-]+) *\n\| *{\{[pP]\|(\d+)\}\} *\n/', $keyPart, $out, PREG_SET_ORDER);
		foreach($out as $match) {
			$pids['/key/' . str_replace('/', '.', $match[1])] = 'P' . $match[2];
		}

		$this->stats['key-used'] = count($pids);

		return $pids;
	}

	private function getWikidataIds($fileName) {
		$usedProperties = [];
		foreach($this->propertyMapping as $pid) {
			$usedProperties[$pid] = true;
		}

		$ids = [];

		$input = fopen($fileName, 'r');
		while($line = fgets($input)) {
			list($s, $p, $o) = explode("\t", $line, 3);

			if(!array_key_exists($p, $usedProperties)) {
				continue;
			}

			$ids[$p][strtolower(trim($o, "\" \n\t"))] = $s;
			$this->stats['wikidata-ids']++;
		}
		fclose($input);

		return $ids;
	}

	private function getFreebaseKeys($fileName) {
		$keys = [];

		$input = fopen($fileName, 'r');
		while($line = fgets($input)) {
			list($s, $p, $o) = explode("\t", $line, 3);
			$p = substr($p, 24, -1);

			if(!array_key_exists($p, $this->propertyMapping)) {
				continue;
			}
			if(!preg_match('/^"(.*)"(@en)?/', $o, $m)) {
				echo "$o\n";
				continue;
			}

			$keys[$this->propertyMapping[$p]][strtolower($this->unescapeFreebaseKey($m[1]))] = substr($s, 28, -1);
			$this->stats['freebase-keys']++;
		}
		fclose($input);

		return $keys;
	}

	private function unescapeFreebaseKey($key) {
		return preg_replace_callback('/\$([0-9A-F]{4})/', function($matches) {
			return json_decode('"\u' . strtolower($matches[1]) . '"');
		}, $key);
	}

	private function createMapping($freebaseKeys, $wikidataIds) {
		$mapping = [];

		foreach($freebaseKeys as $prop => $values) {
			if(!array_key_exists($prop, $wikidataIds)) {
				echo "Property not in Wikidata: $prop\n";
				continue;
			}

			foreach($values as $value => $mid) {
				if(!array_key_exists($value, $wikidataIds[$prop])) {
					continue;
				}
				$qid = $wikidataIds[$prop][$value];

				$this->stats['matched-all']++;
				if(array_key_exists($prop, $this->stats['matched'])) {
					$this->stats['matched'][$prop]++;
				} else {
					$this->stats['matched'][$prop] = 1;
				}
				if(array_key_exists($mid, $mapping)) {
					if($qid !== $mapping[$mid]) {
						$this->stats['conflict']++;
					}
				} else {
					$mapping[$mid] = $qid;
					$this->stats['mapped']++;
				}
			}
		}

		return $mapping;
	}

	private function saveMapping($mapping, $fileName) {
		$output = fopen($fileName, 'w');
		foreach($mapping as $mid => $qid) {
			fwrite($output, "$mid\t$qid\n");
		}
		fclose($output);
	}
}

$recon = new Reconciliation();
$recon->run($argv[1], $argv[2], $argv[3]);
arsort($recon->stats['matched']);
print_r($recon->stats);
