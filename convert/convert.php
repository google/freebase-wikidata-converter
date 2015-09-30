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

class CvtStorage implements CVTProvider {

	private $triples = [];
	private $cvtProperties;

	public function __construct() {
		$this->cvtProperties = $this->getCVTProperties();
	}

	private function getCVTProperties() {
		$input = fopen(__DIR__ . '/../data/properties_expecting_cvt.csv', 'r');
		$props = [];

		while($line = fgets($input)) {
			$id = str_replace('/', '.', trim($line));
			$id[0] = '/';
			$props['<http://rdf.freebase.com/ns' . $id . '>'] = true;
		}

		return $props;
	}

	public function insertTriple($subject, $predicate, $object) {
		$key = substr($subject, 28, -1);

		if(array_key_exists($key, $this->triples)) {
			$this->triples[$key] .= "\n" . substr($predicate, 28, -1) . "\t" . $object;
		} else {
			$this->triples[$key] = substr($predicate, 28, -1) . "\t" . $object;
		}
	}

	public function isCVTProperty($id) {
		return array_key_exists($id, $this->cvtProperties);
	}

	public function getCVT($id) {
		$key = substr($id, 28, -1);
		if(!array_key_exists($key, $this->triples)) {
			return [];
		}

		$result = [];
		foreach(explode("\n", $this->triples[$key]) as $predicateValue) {
			list($predicate, $value) = explode("\t", $predicateValue, 2);
			$result['<http://rdf.freebase.com/ns/' . $predicate . '>'][] = $value;
		}
		return $result;
	}

	public function saveToFile($fileName) {
		$file = fopen($fileName, 'w');
		foreach($this->triples as $subject => $temp) {
			foreach(explode("\n", $temp) as $predicateValue) {
				fwrite($file, $subject . "\t" . $predicateValue . "\n");
			}
		}
		fclose($file);
	}

	public function loadfromFile($fileName) {
		$file = fopen($fileName, 'r');
		while($line = fgets($file)) {
			list($s, $po) = explode("\t", trim($line), 2);
			if(array_key_exists($s, $this->triples)) {
				$this->triples[$s] .= "\n" . $po;
			} else {
				$this->triples[$s] = $po;
			}
		}
		fclose($file);
	}
}


class ReviewedFactsStorage implements ReviewedFacts {
	//Map $subject\t$predicate => used
	public $reviewedFacts = [];

	public function isReviewedFact($subject, $property) {
		$key = substr($subject, 28, -1) . "\t" . substr($property, 28, -1);

		if(!array_key_exists($key, $this->reviewedFacts)) {
			return false;
		}

		$this->reviewedFacts[$key] = true;
		return true;
	}

	public function insertFact($subject, $property) {
		$key = substr($subject, 28, -1) . "\t" . $property;
		$this->reviewedFacts[$key] = false;
	}

	private function buildKey($subject, $property) {
		return substr($subject, 28, -1) . "\t" . substr($property, 28, -1);
	}

	public function saveToFile($fileName) {
		$file = fopen($fileName, 'w');
		foreach($this->reviewedFacts as $fact => $used) {
			fwrite($file, $fact . "\n");
		}
		fclose($file);
	}

	public function loadfromFile($fileName) {
		$file = fopen($fileName, 'r');
		while($line = fgets($file)) {
			$this->reviewedFacts[trim($line)] = false;
		}
		fclose($file);
	}

	public function countUsedFacts() {
		$count = 0;
		foreach($this->reviewedFacts as $used) {
			if($used) {
				$count++;
			}
		}
		return $count;
	}
}

$outputdirectory = $argv[4];
$input = fopen($argv[1], 'r');


$cvtStorage = new CvtStorage();
$reviewedFactsStorage = new ReviewedFactsStorage();
$mapping = new Mapping($cvtStorage, $reviewedFactsStorage, $argv[3]);

print_r($mapping->getStatistics());
//Extract CVTss
if(file_exists($outputdirectory . '/cvt-triples.tsv')) {
	echo "\nLoading CVTs\n";
	$cvtStorage->loadfromFile($outputdirectory . '/cvt-triples.tsv');
} else {
	//Find CVT nodes
	$cvtMids = array();
	if(file_exists($outputdirectory . '/freebase-cvt-ids.csv')) {
		$cvtIdsFile = fopen($outputdirectory . '/freebase-cvt-ids.csv', 'r');
		while($line = fgets($cvtIdsFile)) {
			$cvtMids[trim($line)] = true;
		}
		fclose($cvtIdsFile);
	} else {
		echo "\nFind CVTs\n";
		$cvtIdsFile = fopen($outputdirectory . '/freebase-cvt-ids.csv', 'w');
		$count = 0;

		while($line = fgets($input)) {
			list($s, $p, $o) = explode("\t", trim($line, " .\t\n\r\0\x0B"), 3);
			if($cvtStorage->isCVTProperty($p)) {
				$mid = substr($o, 28, -1);
				$cvtMids[$mid] = [];
				fwrite($cvtIdsFile, $mid . "\n");
			}

			$count++;
			if($count % 1000000 === 0) {
				echo '.';
			}
		}
		fclose($cvtIdsFile);
	}

	echo "\nExtract CVTs\n";
	rewind($input);
	$count = 0;

	while($line = fgets($input)) {
		list($s, $p, $o) = explode("\t", trim($line, " .\t\n\r\0\x0B"), 3);
		if(array_key_exists(substr($s, 28, -1), $cvtMids)) {
			$cvtStorage->insertTriple($s, $p, $o);
		}

		$count++;
		if($count % 1000000 === 0) {
			echo '.';
		}
	}

	echo "\nSaving CVTs\n";
	$cvtStorage->saveToFile($outputdirectory . '/cvt-triples.tsv');
}


//Extract reviewed facts
if(file_exists($outputdirectory . '/reviewed-facts.tsv')) {
	echo "\nLoading reviewed facts\n";
	$reviewedFactsStorage->loadfromFile($outputdirectory . '/reviewed-facts.tsv');
} else {
	echo "\nExtracting reviewed facts\n";

	//Get property id => mid
	$propertyIds = [];
	$mql = json_decode(file_get_contents('https://www.googleapis.com/freebase/v1/mqlread?query=[{"type":"/type/property","id":null,"mid":null,"limit":30000}]'), true);
	foreach($mql['result'] as $prop) {
		$propertyIds['m.' . substr($prop['mid'], 3)] = str_replace('/', '.', substr($prop['id'], 1));
	}

	rewind($input);
	$count = 0;
	while($line = fgets($input)) {
		list($s, $p, $o) = explode("\t", trim($line, " .\t\n\r\0\x0B"), 3);
		if($p !== '<http://rdf.freebase.com/ns/freebase.valuenotation.is_reviewed>') {
			continue;
		}

		$mid = substr($o, 28, -1);
		if(!array_key_exists($mid, $propertyIds)) {
			continue; //There seems to be some invalid ids
		}

		$reviewedFactsStorage->insertFact($s, $propertyIds[$mid]);

		$count++;
		if($count % 100000 === 0) {
			echo '.';
		}
	}

	echo "\nSaving reviewed facts\n";
	$reviewedFactsStorage->saveToFile($outputdirectory . '/reviewed-facts.tsv');
}


//Extract references
echo "\nExtracting and mapping references\n";
$referencesUrls = [];
$referencesInput = fopen($argv[2], 'r');
$count = 0;
while($line = fgets($referencesInput)) {
	$parts = explode("\t", trim($line, " .\t\n\r\0\x0B"));
	if(count($parts) < 4) {
		continue; //Some lines don't have references
	}

	$s = '<http://rdf.freebase.com/ns/m.' . substr($parts[0], 3) . '>';
	$p = '<http://rdf.freebase.com/ns/' . trim(str_replace('/', '.', $parts[1]), '.') . '>';
	$o = $parts[2];
	if(strpos($o, '/m/') !== false) {
		$o = '<http://rdf.freebase.com/ns/m.' . substr($o, 3) . '>';
	} elseif(is_numeric($o)) {
		$o = '"' . $o . '"^^<http://www.w3.org/2001/XMLSchema#gYear>';
	} elseif(preg_match('/^-?\d+-\d{2}$/', $o)) {
		$o = '"' . $o . '"^^<http://www.w3.org/2001/XMLSchema#gYearMonth>';
	} elseif(preg_match('/^-?\d+-\d{2}-\d{2}$/', $o)) {
		$o = '"' . $o . '"^^<http://www.w3.org/2001/XMLSchema#date>';
	} else {
		$o = '"' . $o . '"';
	}
	$statement = new Statement($s, $p, $o);

	try {
		foreach($mapping->mapFreebaseStatement($statement) as $statement) {
			$referencesUrls[$statement->toTSV()] = array_slice($parts, 3);
		}
	} catch(Mappingfailure $e) {
	} catch(Exception $e) {
		echo $e->getMessage() . ': ' . $line . "\n";
	}

	$count++;
	if($count % 100000 === 0) {
		echo '.';
	}
}
fclose($referencesInput);


//Do mapping
echo "\nMapping\n";
rewind($input);
$mapping->resetStatistics();
$count = 0;
$freebaseMappedFile = fopen($outputdirectory . '/freebase-mapped.tsv', 'w');
$coordinatesMappedFile = fopen($outputdirectory . '/coordinates-mapped.tsv', 'w');
$reviewedMappedFile = fopen($outputdirectory . '/reviewed-mapped.tsv', 'w');
while($line = fgets($input)) {
	list($s, $p, $o) = explode("\t", trim($line, " .\t\n\r\0\x0B"), 3);

	$statement = new Statement($s, $p, $o);

	try {
		$statements = $mapping->mapFreebaseStatement($statement);
	} catch(Mappingfailure $e) {
		continue;
	} catch(Exception $e) {
		echo $e->getMessage() . ': ' . $line . "\n"; //TODO change to have verbose output
		continue;
	}

	foreach($statements as $statement) {
		if($statement->getObject()[0] === '@') {
			fwrite($coordinatesMappedFile, $statement->toTSV());
		} else {
			//references
			$serialization = $statement->toTSV();

			if(array_key_exists($serialization, $referencesUrls)) {
				foreach($referencesUrls[$serialization] as $url) {
					fwrite($freebaseMappedFile, str_replace("\n", "\tS854\t\"" . $url . "\"\n", $serialization));
				}
			} else {
				fwrite($freebaseMappedFile, $serialization);
			}
		}

		if($statement->isReviewed()) {
			fwrite($reviewedMappedFile, $statement->toTSV());
		}
	}

	$count++;
	if($count % 100000 === 0) {
		echo '.';
	}
}
fclose($freebaseMappedFile);
fclose($coordinatesMappedFile);

print_r($mapping->getStatistics());
echo "used reviewed facts: " . $reviewedFactsStorage->countUsedFacts();