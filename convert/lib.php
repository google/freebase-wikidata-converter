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

require __DIR__ . '/vendor/autoload.php';

class Mappingfailure extends Exception {}

class Statement {

	private $subject;
	private $predicate;
	private $object;
	private $qualifiers;
	private $source;
	private $isReviewed;

	public function __construct($subject, $predicate, $object, $qualifiers = [], $source = [], $isReviewed = false) {
		$this->subject = $subject;
		$this->predicate = $predicate;
		$this->object = $object;
		$this->qualifiers = $qualifiers;
		$this->source = $source;
		$this->isReviewed = $isReviewed;
	}

	public function toTSV() {
		$str = $this->subject . "\t" . $this->predicate . "\t" . $this->object;
		$additional = $this->qualifiers + $this->source;
		ksort($additional);
		foreach($additional as $predicate => $values) {
			foreach($values as $value) {
				$str .= "\t" . $predicate . "\t" . $value;
			}
		}
		return $str . "\n";
	}

	public function getSubject() {
		return $this->subject;
	}

	public function getPredicate() {
		return $this->predicate;
	}

	public function getObject() {
		return $this->object;
	}

	/**
	 * @return bool
	 */
	public function isReviewed() {
		return $this->isReviewed;
	}
}


interface CVTProvider {
	public function isCVTProperty($id);
	public function getCVT($id);
}

class DummyCVTProvider implements CVTProvider {
	public function isCVTProperty($id) {
		return false;
	}

	public function getCVT($id) {
		return [];
	}
}


interface ReviewedFacts {
	public function isReviewedFact($subject, $property);
}

class DummyReviewedFacts implements ReviewedFacts {
	public function isReviewedFact($subject, $property) {
		return false;
	}
}

abstract class Property {
	public function getRange() {
		throw new Exception('Unknown range');
	}

	public function isSource() {
		return false;
	}
}

class WikidataProperty extends Property {
	private $pid;
	private $isSource;
	private $range;

	public function __construct($pid, $isSource = false, $range = '') {
		$this->pid = $pid;
		$this->isSource = $isSource;
		$this->range = $range;
	}

	public function getPid() {
		return $this->pid;
	}

	public function getRange() {
		return $this->range;
	}

	public function isSource() {
		return $this->isSource;
	}
}

class ValueProperty extends Property {
	private $range;

	public function __construct($range) {
		$this->range = $range;
	}

	public function getRange() {
		return $this->range;
	}
}

class SpouseProperty extends Property {
	public function getRange() {
		return 'wikibase-item';
	}
}

class Mapping {
	private $itemMapping;
	private $propertyMapping;
	private $isoDateParser;
	private $cvtProvider;
	private $reviewedFacts;
	private $statistics = [];

	private static $HARDCODED_ITEM_MAPPING = [
		'm.05zppz' => 'Q6581097', //sex: male
		'm.02zsn' => 'Q6581072', //sex: female
		'm.0jst35z' => 'Q637413', //United States Census Bureau
		'g.11x1k306j' => 'Q637413', //United States Census Bureau
		'g.11x1gf2m6' => 'Q637413' //United States Census Bureau
	];
	private static $HARDCODED_PROPERTY_MAPPING = [
		'/ns/measurement_unit.dated_float.number' => 'QUANTITY',
		'/ns/measurement_unit.dated_integer.number' => 'QUANTITY',
		'/ns/measurement_unit.dated_integer.source' => 'S248',
		'/ns/measurement_unit.dated_money_value.amount' => 'QUANTITY',
		'/ns/measurement_unit.dated_percentage.rate' => 'QUANTITY',
		'/ns/measurement_unit.dated_percentage.source' => 'S248',
		'/ns/measurement_unit.money_value.amount' => 'QUANTITY',
		'/ns/location.mailing_address.citytown' => 'ITEM',
		'/ns/people.person.spouse_s' => 'SPOUSE'
	];

	private static $PROPERTY_PREFIX_TO_FILTER = [ //TODO: update
		//'/ns/media_common.cataloged_instance.',
		//'/key/'
	];

	public function __construct(CVTProvider $cvtProvider, ReviewedFacts $reviewedFacts, $mappingsDirectory) {
		$this->resetStatistics();
		$this->propertyMapping = $this->getPropertyMapping();
		$this->itemMapping = $this->getItemMapping($this->getMappingfiles($mappingsDirectory), $mappingsDirectory . '/wikidata-redirects.tsv');
		$this->isoDateParser = new ValueParsers\IsoTimestampParser(
				new ValueParsers\CalendarModelParser(new ValueParsers\ParserOptions()),
				new ValueParsers\ParserOptions()
		);
		$this->cvtProvider = $cvtProvider;
		$this->reviewedFacts = $reviewedFacts;
		$this->getPropertyData();
	}

	private function getMappingfiles($mappingsDirectory) {
		$files = glob($mappingsDirectory . '/*.pairs');
		sort($files);
		return $files;
	}

	private function getItemMapping($files, $redirectionFile) {
		$qids = [];
		$this->statistics['mapping-item-differences'] = 0;
		$this->statistics['redirection-resolved'] = 0;

		//Load redirections
		$redirections = [];
		if(is_file($redirectionFile)) {
			$input = fopen($redirectionFile, 'r');
			while($line = fgets($input)) {
				$parts = explode("\t", trim($line));
				if(count($parts) !== 2) {
					echo "Invalid redirect line: $line";
					continue;
				}
				$redirections[$parts[0]] = $parts[1];
			}
			fclose($input);
		}

		foreach($files as $file) {
			$input = fopen($file, 'r');
			while($line = fgets($input)) {
				$parts = explode("\t", trim($line));
				if(count($parts) !== 2) {
					echo "Invalid mapping line: $line";
					continue;
				}
				list($mid, $qid) = $parts;

				if(array_key_exists($qid, $redirections)) {
					$qid = $redirections[$qid];
					$this->statistics['redirection-resolved']++;
				}

				if(array_key_exists($mid, $qids)) {
					if($qids[$mid] !== $qid) {
						$this->statistics['mapping-item-differences']++;
					}
				}

				$qids[$mid] = $qid;
			}
			fclose($input);
		}

		foreach(self::$HARDCODED_ITEM_MAPPING as $mid => $qid) {
			$qids[$mid] = $qid;
		}

		unset($qids['m.04lsf6']); //German Wikipedia
		unset($qids['m.01wfbm']); //English Wikipedia
		unset($qids['m.0d07ph']); //Wikipedia

		$this->statistics['mapping-item'] = count($qids);

		return $qids;
	}

	private function getPropertyMapping() {
		$pids = [];

		$wikitext = file_get_contents('https://www.wikidata.org/wiki/Wikidata:WikiProject_Freebase/Mapping?action=raw');
		$mapping = [];
		$out = [];

		list($nsPart, $keyPart) = explode('(/key/ namespace)', $wikitext);

		preg_match_all('/\|\-\n *\| *https?:\/\/www\.freebase\.com\/([a-zA-Z0-9\/_\-]+) *\n\| *(.*) *\n/', $nsPart, $out, PREG_SET_ORDER);
		foreach($out as $match) {
			try {
				$pids['/ns/' . str_replace('/', '.', $match[1])] = $this->buildPropertyFromString($match[2]);
			} catch(InvalidArgumentException $e) {
			}
		}

		preg_match_all('/\|\-\n *\| *https?:\/\/www\.freebase\.com\/([a-zA-Z0-9\/_\-]+) *\n\| *(.*) *\n/', $keyPart, $out, PREG_SET_ORDER);
		foreach($out as $match) {
			try {
				$pids['/key/' . str_replace('/', '.', $match[1])] = $this->buildPropertyFromString($match[2]);
			} catch(InvalidArgumentException $e) {
			}
		}

		foreach(self::$HARDCODED_PROPERTY_MAPPING as $pid => $str) {
			$pids[$pid] = $this->buildPropertyFromString($str);
		}

		foreach($pids as $pid => $wp) {
			$found = false;
			foreach(self::$PROPERTY_PREFIX_TO_FILTER as $prefix) {
				if(strpos($pid, $prefix) === 0) {
					$found = true;
					break;
				}
			}
			if($found) {
				unset($pids[$pid]);
			}
		}

		$this->statistics['mapping-property'] = count($pids);
		return $pids;
	}

	private function buildPropertyFromString($str) {
		$str = trim($str);

		$match = [];
		if(preg_match('/^\{\{[pP]\|(\d+)\}\}$/', $str, $match)) {
			return new WikidataProperty('P' . $match[1], false);
		} elseif(preg_match('/^S(\d+)$/', $str, $match)) {
			return new WikidataProperty('P' . $match[1], true);
		} elseif($str === 'QUANTITY') {
			return new ValueProperty('quantity');
		} elseif($str === 'ITEM') {
			return new ValueProperty('wikibase-item');
		} elseif($str === 'SPOUSE') {
			return new SpouseProperty();
		} else {
			throw new InvalidArgumentException();
		}
	}

	private function getPropertyData() {
		$api = new \Mediawiki\Api\MediawikiApi('https://www.wikidata.org/w/api.php');
		$services = new \Wikibase\Api\WikibaseFactory($api);

		foreach($this->getUsedPropertyIdsByBucket() as $ids) {
			foreach($services->newRevisionsGetter()->getRevisions($ids)->toArray() as $revision) {
				$entity = $revision->getContent()->getData();
				$pid = $entity->getId()->getSerialization();

				foreach($this->propertyMapping as $key => $prop) {
					if($prop instanceof WikidataProperty && $prop->getPid() === $pid) {
						$this->propertyMapping[$key] = new WikidataProperty($pid, $prop->isSource(), $entity->getDataTypeId());
					}
				}
			}
		}
	}

	private function getUsedPropertyIdsByBucket() {
		$ids = [];
		$bucketId = 0;
		$i = 0;
		foreach($this->propertyMapping as $property) {
			if(!($property instanceof WikidataProperty)) {
				continue;
			}

			$ids[$bucketId][] = new \Wikibase\DataModel\Entity\PropertyId($property->getPid());

			$i++;
			if($i % 40 === 0) {
				$bucketId++;
			}
		}

		return $ids;
	}

	private function mapWikidataUri($uri) {
		$matches = [];
		if(preg_match('/<http:\/\/www\.wikidata\.org\/entity\/([PQ]\d+)\w?>/', $uri, $matches)) {
			return $matches[1];
		}
		throw new Mappingfailure();
	}

	private function mapFreebaseUri($uri) {
		return $this->mapMid(substr($uri, 28, -1));
	}

	public function mapMid($mid) {
		if(is_string($mid) && array_key_exists($mid, $this->itemMapping)) {
			return $this->itemMapping[$mid];
		}
		throw new Mappingfailure();
	}

	/**
	 * @return Property
	 */
	public function mapFreebaseProperty($uri, $specialMapping = []) {
		$property = substr($uri, 24, -1);
		if(array_key_exists($property, $specialMapping)) {
			return $specialMapping[$property];
		}
		if(array_key_exists($property, $this->propertyMapping)) {
			return $this->propertyMapping[$property];
		}
		throw new Mappingfailure();
	}

	private function mapValue($value, Property $property) {
		$this->statistics['type-' . $property->getRange()]++;

		switch($property->getRange()) {
			case 'globe-coordinate':
				return $this->mapGlobeCoodinate($value);
			case 'monolingualtext':
				return $this->mapMonolingualText($value);
			case 'quantity':
				return $this->mapNumber($value);
			case 'string':
				return $this->mapString($value);
			case 'time':
				return $this->mapTime($value);
			case 'url':
				return $this->mapUrl($value);
			case 'wikibase-item': // FALLTHROUGH
			case 'wikibase-property':
				$value = $this->mapWikibaseEntity($value);
				$this->statistics['type-wikibase-entity-mapped']++;
				return $value;
			default:
				throw new Exception('Unsupported type ' . $property->getRange());
		}
	}

	private function mapGlobeCoodinate($value) { //TODO: Datum and elevation
		$cvt = $this->cvtProvider->getCVT($value);

		if(
			!array_key_exists('<http://rdf.freebase.com/ns/location.geocode.latitude>', $cvt) ||
			!array_key_exists('<http://rdf.freebase.com/ns/location.geocode.longitude>', $cvt)
		) {
			throw new Mappingfailure('Invalid coordinates ' . json_encode($cvt));
		}
		$latitude = str_replace('"', '', $cvt['<http://rdf.freebase.com/ns/location.geocode.latitude>'][0]);
		$longitude = str_replace('"', '', $cvt['<http://rdf.freebase.com/ns/location.geocode.longitude>'][0]);
		$this->statistics['triple-used'] += 2;
		return '@' . $latitude . '/' . $longitude;
	}

	private function mapMonolingualText($value) {
		$matches = [];
		if(preg_match('/"(.+)"@([\w\-]+)/', $value, $matches)) {
			return $matches[2] . ':"' . str_replace(["\n", '"'], [' ', ' '], $matches[1]) . '"';
		}

		throw new Exception('Unable to parse the text ' . $value);
	}

	private function mapNumber($value) {
		$matches = [];
		if(
			preg_match('/"(.+)"\^\^<http:\/\/www\.w3\.org\/2001\/XMLSchema#decimal>/', $value, $matches) ||
			preg_match('/"([+-]?\d+(\.\d+)?)"/', $value, $matches)
		) {
			$value = $matches[1];
			if($value[0] !== '+' && $value[0] !== '-') {
				$value = '+' . $value;
			}
			return $value;
		}

		throw new Exception('Unable to parse the number ' . $value);
	}

	private function mapString($value) {
		$matches = [];
		if(preg_match('/"(.+)"(@en)?/', $value, $matches)) { //@en is an hack for Freebase that loves to add unneded languages
			return '"' . str_replace(["\n", '"'], [' ', ' '], $matches[1]) . '"';
		}

		throw new Exception('Unable to parse the string ' . $value);
	}

	private function mapTime($value) {
		$matches = [];
		if(preg_match('/"(.+)"\^\^<([^<>]+)>/', $value, $matches)) {
			$value = $matches[1];
			switch($matches[2]) {
				case 'http://www.w3.org/2001/XMLSchema#gYear': // FALLTHROUGH
					if(strpos($value, '-', 1)) {
						throw new Mappingfailure(); //Ignore: wrongly typed BC date
					}
					$value .= '-00';
				case 'http://www.w3.org/2001/XMLSchema#gYearMonth': // FALLTHROUGH
					$value .= '-00';
				case 'http://www.w3.org/2001/XMLSchema#date': // FALLTHROUGH
					$value .= 'T00:00:00Z';
				case 'http://www.w3.org/2001/XMLSchema#dateTime': //check if everything works fine with time (not displayed in the UI...)
					try {
						$parsedValue = $this->isoDateParser->parse($value);
					} catch(ParseException $e) {
						throw new Exception($e->getmessage());
					}
					$this->statistics['time-' . str_replace('http://www.w3.org/2001/XMLSchema#', '', $matches[2])]++;

					//Filter before 1920
					if($parsedValue->getPrecision() > DataValues\TimeValue::PRECISION_YEAR && intval(explode('-', $parsedValue->getTime())[0]) < 1920) {
						throw new Mappingfailure();
					}

					return $parsedValue->getTime() . '/' . $parsedValue->getPrecision();
			}
		}

		throw new Exception('Unable to parse the time ' . $value);
	}

	private function mapUrl($value) {
		$matches = [];
		if(
			preg_match('/"(.+)"/', $value, $matches) ||
			preg_match('/<(.+)>/', $value, $matches)
		) {
			return '"' . $matches[1] . '"';
		}

		throw new Exception('Unable to parse the URL ' . $value);
	}

	private function mapWikibaseEntity($value) {
		if(strpos($value, '<http://rdf.freebase.com/ns') === 0) {
			return $this->mapFreebaseUri($value);
		} elseif(strpos($value, '<http://www.wikidata.org/entity/') === 0) {
			return $this->mapWikidataUri($value);
		}

		throw new Exception('Unable to parse the entity ' . $value);
	}

	//Return the mapped statments
	public function mapFreebaseStatement(Statement $statement) {
		$subject = $this->mapFreebaseUri($statement->getSubject());
		$this->statistics['triple-mapped-subject']++;

		$isValueCvt = $this->cvtProvider->isCVTProperty($statement->getPredicate());
		$predicate = $this->mapFreebaseProperty($statement->getPredicate());
		$this->statistics['triple-mapped-subject-property']++;

		$objects = [];
		$qualifiers = [];
		$source = [];
		$isReviewed = $this->reviewedFacts->isReviewedFact($statement->getSubject(), $statement->getPredicate());
		if($isValueCvt && !$this->propertyExpectCvt($predicate)) {
			$this->statistics['triple-value-cvt']++;

			//CVT management
			$cvt = $this->cvtProvider->getCVT($statement->getObject());

			//Special cases
			$specialMapping = [];
			if($predicate instanceof SpouseProperty) {
				if(array_key_exists('<http://rdf.freebase.com/ns/people.marriage.type_of_union>', $cvt)) {
					switch($cvt['<http://rdf.freebase.com/ns/people.marriage.type_of_union>'][0]) {
						case '<http://rdf.freebase.com/ns/m.04ztj>':
						case '<http://rdf.freebase.com/ns/m.0jgjn>':
						case '<http://rdf.freebase.com/ns/m.0dl5ys>':
						case '<http://rdf.freebase.com/ns/m.03m4r>':
						case '<http://rdf.freebase.com/ns/m.01bl8s>':
						case '<http://rdf.freebase.com/ns/m.075xk9>':
							$predicate = new WikidataProperty('P26', false, 'wikibase-item');
							break;
						case '<http://rdf.freebase.com/ns/m.01g63y>':
							throw new Mappingfailure();
							//TODO: What to do with https://www.freebase.com/m/01g63y Domestic partnership
							/*$predicate = new WikidataProperty('P451', false, 'wikibase-item');
							break;*/
						default:
							throw new Exception('Unknown mariage type: ' . $cvt['<http://rdf.freebase.com/ns/people.marriage.type_of_union>'][0]);
					}
					$this->statistics['triple-used']++;
				} else {
					//By default we concider that it is a union
					$predicate = new WikidataProperty('P26', false, 'wikibase-item');
				}
				$specialMapping['/ns/people.marriage.spouse'] = $predicate;
			}

			foreach($cvt as $cvtPredicate => $values) {
				foreach($values as $value) {
					try {
						$property = $this->mapFreebaseProperty($cvtPredicate, $specialMapping);
						if($property instanceof ValueProperty) {
							$objects[] = $this->mapValue($value, $property);
							$this->statistics['triple-used']++;
						} elseif($property instanceof WikidataProperty) {
							list($pred, $value) = $this->mapQualifierValue($cvtPredicate, $property, $value);
							if($value !== $subject) { //Filter qualifiers that have as value the subject. Useful when the relation is used in both directions in Wikidata
								if($property->isSource()) {
									$source[str_replace('P', 'S', $pred)][] = $value;
								} else {
									$qualifiers[$pred][] = $value;
								}
								$this->statistics['triple-used']++;
							}
						} else {
							throw new Exception('Invalid property as qualifier');
						}

						if($this->reviewedFacts->isReviewedFact($statement->getObject(), $cvtPredicate)) {
							$isReviewed = true; //We should maybe be harder and ask for everything reviewed
						}
					} catch(Mappingfailure $e) { //don't fail for qualifiers
					}
				}
			}

			if(empty($objects) && $predicate instanceof WikidataProperty) {
				if(!array_key_exists($predicate->getPid(), $qualifiers)) { //Find main property
					throw new Mappingfailure();
				}
				$objects = $qualifiers[$predicate->getPid()];
				unset($qualifiers[$predicate->getPid()]);
			}
		} else {
			$objects[] = $this->mapValue($statement->getObject(), $predicate);
		}

		if(!($predicate instanceof WikidataProperty)) {
			throw new Mappingfailure();
		}

		$statements = [];
		foreach($objects as $object) {
			//Special formatting
			if($predicate->getPid() === 'P774') {
				if(preg_match('/^(\d{2})(\d{5})$/', $object, $m)) {
					$object = $m[1] . '-' . $m[2];
				}
			} elseif($predicate->getPid() === 'P274') {
				$object = str_replace(['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'], ['₁', '₂', '₃', '₄', '₅', '₆', '₇', '₈', '₉', '₀'], $object);
			} elseif(in_array($predicate->getPid(), ['P1953', 'P1954', 'P1955']) && !is_numeric($object)) {
				throw new Mappingfailure();
			} elseif(strpos($statement->getPredicate(), '/key/') !== false) {
				$object = $this->unescapeFreebaseKey($object);
			}

			$statements[] = new Statement($subject, $predicate->getPid(), $object, $qualifiers, $source, $isReviewed);
		}

		$this->statistics['triple-used']++;
		$this->statistics['claim-created'] += count($statements);

		return $statements;
	}

	private function mapQualifierValue($predicate, WikidataProperty $property, $value) {
		try {
			return [$property->getPid(), $this->mapValue($value, $property)];
		} catch(Exception $e) {
			if($this->cvtProvider->isCVTProperty($predicate) && !$this->propertyExpectCvt($property)) {
				$cvt = $this->cvtProvider->getCVT($value);

				foreach($cvt as $cvtPredicate => $cvtValues) {
					foreach($cvtValues as $cvtValue) {
						try {
							if($this->mapFreebaseProperty($cvtPredicate) == $property) { //As we are in qualifiers, we take only the main property
								$result = [$property->getPid(), $this->mapValue($cvtValue, $property)];
								$this->statistics['triple-used']++;
								return $result;
							}
						} catch(Mappingfailure $e) {
						}
					}
				}
			}

			throw $e;
		}
	}

	private function propertyExpectCvt(Property $property) {
		return $property->getRange() === 'globe-coordinate';
	}

	private function unescapeFreebaseKey($key) {
		return preg_replace_callback('/\$([0-9A-F]{4})/', function($matches) {
			return json_decode('"\u' . strtolower($matches[1]) . '"');
		}, $key);
	} //TODO: test look for $002B

	/**
	 * @param string $mid an MID like m.abcdef or g.foo
	 * @return boolean
	 */
	public function isFreebaseMapped($mid) {
		return array_key_exists($mid, $this->itemMapping);
	}

	/**
	 * @param string id the property id like /ns/people.person.gender
	 * @return boolean
	 */
	public function isPropertyMapped($id) {
		return array_key_exists($id, $this->propertyMapping);
	}

	public function getStatistics() {
		return $this->statistics;
	}

	public function resetStatistics() {
		$this->statistics = [
			'type-globe-coordinate' => 0,
			'type-monolingualtext' => 0,
			'type-quantity' => 0,
			'type-string' => 0,
			'type-time' => 0,
			'type-url' => 0,
			'type-wikibase-item' => 0,
			'type-wikibase-property' => 0,
			'type-globe-coordinate' => 0,
			'time-gYear' => 0,
			'time-gYearMonth' => 0,
			'time-date' => 0,
			'time-dateTime' => 0,
			'triple-used' => 0,
			'claim-created' => 0,
			'triple-mapped-subject' => 0,
			'triple-mapped-subject-property' => 0,
			'triple-value-cvt' => 0,
			'wikibase-entity-mapped' => 0
		] + $this->statistics;
	}
}
