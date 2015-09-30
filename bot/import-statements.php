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

require __DIR__ . '/../convert/lib.php';

use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\ApiUser;
use Mediawiki\DataModel\Revision;
use Mediawiki\DataModel\EditInfo;
use Wikibase\Api\WikibaseFactory;
use Wikibase\DataModel\ItemContent;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\Snak;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Entity\BasicEntityIdParser;
use Wikibase\DataModel\Entity\EntityIdValue;
use DataValues\Geo\Parsers\GlobeCoordinateParser;
use DataValues\TimeValue;
use DataValues\DecimalValue;
use DataValues\QuantityValue;
use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use DataValues\DataValue;
use Wikibase\DataModel\Statement\StatementList;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\ReferenceList;
use Wikibase\Api\Service\RevisionSaver;

/**
 * Statement with subject
 */
class FullStatement {
	
	/**
	 * @var EntityId
	 */
	private $subjectId;
	
	/**
	 * @var Statement
	 */
	private $statement;
	
	public function __construct(EntityId $subjectId, Statement $statement) {
		$this->subjectId = $subjectId;
		$this->statement = $statement;
	}

	/**
	 * @return EntityId
	 */
	public function getSubjectId() {
		return $this->subjectId;
	}
	
	/**
	 * @return Statement
	 */
	public function getStatement() {
		return $this->statement;
	}
}


class StatementParser {

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

	public function __construct() {
		$this->entityIdParser = new BasicEntityIdParser();
	}

	/**
	 * @param string $tsvStatement
	 * @return FullStatement
	 */
	public function parseTsvStatement($tsvStatement) {
		$parts = explode("\t", $tsvStatement);
		$partsCount = count($parts);
		if($partsCount < 3 || $partsCount % 2 != 1) {
			throw new InvalidArgumentException("Invalid statement: $tsvStatement");
		}

		list($subject, $property, $object) = $parts;

		$qualifiers = new SnakList();
		$reference = new SnakList();
		for($i = 3; $i < $partsCount; $i += 2) {
			switch($parts[$i]) {
				case 'P':
					$qualifiers->addSnak(new PropertyValueSnak(
						$this->entityIdParser->parse($parts[$i]),
						$this->parseDataValue($parts[$i + 1])
					));
					break;
				case 'S':
					$reference->addSnak(new PropertyValueSnak(
						$this->entityIdParser->parse(str_replace('S', 'P', $parts[$i])),
						$this->parseDataValue($parts[$i + 1])
					));
					break;
				default:
					throw new InvalidArgumentException("Invalid statement: $tsvStatement");
			}
		}

		return new FullStatement(
			$this->entityIdParser->parse($subject),
			new Statement(
				new PropertyValueSnak(
					$this->entityIdParser->parse($property),
					$this->parseDataValue($object)
				),
				$qualifiers,
				new ReferenceList(
					($reference->isEmpty()) ? [new Reference($reference)] : []
				)
			)
		);
	}

	/**
	 * @param string $serialization
	 * @return DataValue
	 */
	private function parseDataValue($serialization) {
		if(preg_match('/^Q\d+$/', $serialization)) {
			return new EntityIdValue(new ItemId($serialization));

		} elseif(preg_match('/^P\d+$/', $serialization)) {
			return new EntityIdValue(new PropertyId($serialization));
		
		} elseif(preg_match('/^@([+\-]?\d+(\.\d+)?)\/([+\-]?\d+(\.\d+)?)$/', $serialization, $m)) {
			$globeCoordinateParser = new GlobeCoordinateParser();
			return $globeCoordinateParser->parse($m[1] . ' ' . $m[2]); //Hacky but works: make the parser guess the precision

		} elseif(preg_match('/^([+-]\d+-\d\d-\d\dT\d\d:\d\d:\d\dZ)\/(\d+)$/', $serialization, $m)) {
			return new TimeValue($m[1], 0, 0, 0, intval($m[2]), 'http://www.wikidata.org/entity/Q1985727');

		} elseif(preg_match('/^[+-]\d+(\.\d+)?$/', $serialization, $m)) {
			$decimalValue = new DecimalValue($serialization);
			return new QuantityValue($decimalValue, '1', $decimalValue, $decimalValue);

		} elseif(preg_match('/^(\w+):"(.*)"$/', $serialization, $m)) {
			return new MonolingualTextValue($m[1], $m[2]);

		} elseif(preg_match('/^"(.*)"$/', $serialization, $m)) {
			return new StringValue($m[1]);

		} else {
			throw new InvalidArgumentException("Unknown DataValue serialization: $serialization"); //TODO: implement the other types of statements
		}
	}
}


/**
 * Add statements to items in a lazy way: save only when you change of item
 */
class StatementSaver {

	/**
	 * @var ItemId
	 */
	private $currentItemId;

	/**
	 * @var string
	 */
	private $currentBaseRevisionId;
	
	/**
	 * @var Statement[]
	 */
	private $statements = [];

	/**
	 * @var RevisionSaver
	 */
	private $revisionServer;

	public function __construct(RevisionSaver $revisionSaver) {
		$this->revisionSaver = $revisionSaver;
	}

	public function __destruct() {
		if($this->currentItemId !== null) {
			$this->saveStatements();
		}
	}

	public function addStatementToEntity(Statement $statement, ItemId $itemId, $baseRevisionId = null) {
		if($this->currentItemId !== null && !$this->currentItemId->equals($itemId)) {
			$this->saveStatements();
		}
		$this->currentItemId = $itemId;
		$this->currentBaseRevisionId = $baseRevisionId;
		$this->statements[] = $statement;
	}

	private function saveStatements() {
		$itemId = $this->currentItemId;
		$this->currentItemId = null;
		$statements = $this->statements;
		$this->statements = [];

		$this->revisionSaver->save(new Revision(
			new ItemContent(new Item(
				$itemId,
				null,
				null,
				new StatementList($statements)
			)),
			null,
			$this->currentBaseRevisionId,
			new EditInfo('Importation from Freebase', false, true)
		));
		//sleep(5); //TOOD: hack to slow down the bot
	}
}

class Bot { //TODO: add support of qualifiers?
	
	/**
	 * @var WikibaseFactory
	 */
	private $wikibaseFactory;
	
	/**
	 * @var StatementParser
	 */
	private $statementParser;

	/**
	 * @var StatementSaver
	 */
	private $statementSaver;

	public function __construct(MediawikiApi $api) {
		$this->wikibaseFactory = new WikibaseFactory($api);
		$this->statementParser = new StatementParser();
		$this->statementSaver = new StatementSaver($this->wikibaseFactory->newRevisionSaver());
	}
	
	public function addStatementFromTsvFile($file) {
		$input = fopen($file, 'r');
		while($line = fgets($input)) {
			try {
				$this->addTsvStatement(trim($line));
			} catch(Exception $e) {
				echo $e->getMessage() . ": $line";
			}
		}
		fclose($input);
	}

	public function addStatementFromPrimarySourcesQuery(array $queryArgs) {
		if(!array_key_exists('offset', $queryArgs)) {
			$queryArgs['offset'] = 0;
		}
		if(!array_key_exists('limit', $queryArgs)) {
			$queryArgs['limit'] = 1000;
		}

		$client = new Client();
		while(true) {
			try {
				$psStatements = $client->get('https://tools.wmflabs.org/wikidata-primary-sources/statements/all', ['query' => $queryArgs])->json();
				
				foreach($psStatements as $psStatement) {
					try {
						$this->addTsvStatement($psStatement['statement']);
						$client->post('https://tools.wmflabs.org/wikidata-primary-sources/statements/' . $psStatement['id'], ['query' => ['state' => 'duplicate', 'user' => 'TptBot']]);
					} catch(Exception $e) {
						echo $e->getMessage() . ': ' . $psStatement['statement'] . "\n";
					}
				}
				
				$queryArgs['offset'] += count($psStatements);
			} catch (ClientException $e) {
				break;
			}
		}
	}

	private function addTsvStatement($statement) {
		//Parse Statement
		$fullStatement = $this->statementParser->parseTsvStatement($statement);
		$subject = $fullStatement->getSubjectId();
		$statement = $fullStatement->getStatement();

		//Get existing statements
		$entityRevision = $this->wikibaseFactory->newRevisionGetter()->getFromId($subject);
		if($entityRevision === false) {
			throw new Exception('Entity does not exists');
		}
		$entity = $entityRevision->getContent()->getData();
		$samePropertyStatements = $entity->getStatements()->getByPropertyId($statement->getMainSnak()->getPropertyId());

		//Looks for existing statements
		if($this->hasClaim($samePropertyStatements, $statement)) {
			return;
		}

		try {
			$subStatement = $this->findSubStatement($samePropertyStatements, $statement);
			$statement->setGuid($subStatement->getGuid());

			if($this->hasMeaningfulReference($subStatement)) {
				throw new Exception('Substatement with meaningful reference');
			}
		} catch(OutOfBoundsException $e) {
			if(!$samePropertyStatements->isEmpty()) {
				throw new Exception('Contradictory statement');
			}
		}

		//Add reference "imported from" "Freebase data dump"
		$statement->addNewReference(new PropertyValueSnak(new PropertyId('P143'), new EntityIdValue(new ItemId('Q15241312'))));

		//Save
		$this->statementSaver->addStatementToEntity($statement, $entity->getId(), $entityRevision->getId());
	}

	private function hasClaim(StatementList $statementList, Statement $statement) {
		foreach($statementList as $statementToMatch) {
			if(
				$this->isSnakMorePrecise($statementToMatch->getMainSnak(), $statement->getMainSnak()) &&
				$this->isSnakMorePrecise($statement->getMainSnak(),$statementToMatch->getMainSnak()) &&
				$this->isSubSnakList($statement->getQualifiers(), $statementToMatch->getQualifiers()) &&
				$this->isSubSnakList($statementToMatch->getQualifiers(), $statement->getQualifiers())
			) {
				return true;
			}
		}
	
		return false;
	}

	private function findSubStatement(StatementList $statementList, Statement $statement) {
		foreach($statementList as $statementToMatch) {
			if(
				$this->isSnakMorePrecise($statement->getMainSnak(), $statementToMatch->getMainSnak()) &&
				$this->isSubSnakList($statement->getQualifiers(), $statementToMatch->getQualifiers())
			) {
				return $statementToMatch;
			}
		}

		throw new OutOfBoundsException();
	}
	
	private function isSubSnakList(SnakList $list, SnakList $container) {
		foreach($list as $snak) {
			if(!$container->hasSnak($snak)) {
				return false;
			}
		}
		return true;
	}

	private function isSnakMorePrecise(Snak $a, Snak $b) {
		if($a instanceof PropertyValueSnak && $b instanceof PropertyValueSnak) {
			return
				$a->getPropertyId()->equals($b->getPropertyId()) &&
				$this->isDataValueMorePrecise($a->getDataValue(), $b->getDataValue());
		}
		return $a->equals($b);
	}

	//TODO: add support of quantity and globe coordinates
	private function isDataValueMorePrecise(DataValue $a, DataValue $b) {
		if($a instanceof TimeValue && $b instanceof TimeValue) {
			return $this->isTimeValueMorePrecise($a, $b);
		}
		return $a->equals($b);
	}

	/**
	 * Returns if $a is a more (or equal) precise Time value than $b
	 * Does not support time part of the timestamp
	 */
	private function isTimeValueMorePrecise(TimeValue $a, TimeValue $b) {
		if($a->getPrecision() < $b->getPrecision()) {
			return false;
		}
		list($yearA, $monthA, $dayA) = $this->explodeTimestamp($a->getTime());
		list($yearB, $monthB, $dayB) = $this->explodeTimestamp($b->getTime());
		return
			$yearA === $yearB &&
			!($b->getPrecision() >= TimeValue::PRECISION_MONTH && $monthA !== $monthB) &&
			!($b->getPrecision() >= TimeValue::PRECISION_DAY && $dayA !== $dayB);
	}

	private function explodeTimestamp($timestamp) {
		preg_match('/^([-+]\d+)-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)Z$/', $timestamp, $m);
		array_shift($m);
		return $m;
	}

	//Very, very hacky
	private function hasMeaningfulReference(Statement $statement) {
		foreach($statement->getReferences() as $reference) {
			foreach($reference->getSnaks() as $snak) {
				if(
					!$snak->equals(new PropertyValueSnak(new PropertyId('P248'), new EntityIdValue(new ItemId('Q36578')))) && //stated in: VIAF
					!$snak->getPropertyId()->equals(new PropertyId('P813')) && //Retrived
					!$snak->getPropertyId()->equals(new PropertyId('P143')) //Imported From
				) {
					return true;
				}
			}
		}
		return false;
	}
}

$api = new MediawikiApi('https://www.wikidata.org/w/api.php');
$api->login(new ApiUser(MY_USERNAME, MY_PASSWORD)); //TODO
$bot = new Bot($api);
$bot->addStatementFromTsvFile($argv[1]);
//$bot->addStatementFromPrimarySourcesQuery(['property' => 'P1771']);
