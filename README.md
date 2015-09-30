Freebase to Wikidata mapping tools
==================================
This repository provides a set of scripts and dataset to create Wikidata statement from Freebase dumps.

The mapping tools are provided in order to increase the transparency on the publication process of Freebase content, but it is not planned to be maintained in the future (mostly, since the conversion to Wikidata is a one-off process anyway). Do not expect updates or support for this material.

Some of these scripts are in Python and reauires Python 2.7 and others are in PHP and requires PHP 5.5 or HHVM. Use [HHVM](http://hhvm.com/) is strongly recommended for some of them that may be very slow and memory greedy when run with PHP 5.

Command lines examples asumme you are on a POSIX system with HHVM and Python 2.7 installed.

## Wikidata related tools
They are in the `wikidata` directory.


### Convert Wikidata JSON dump into TSV
Download [latest Wikidata JSON dump](http://dumps.wikimedia.org/other/wikidata/).

There are two TSV creation scripts:

* `wikidata-tsv.php` that creates also "substatements" of each Wikidata statement. It is useful to filter from an other TSV files statements that are already in Wikidata. To use it, run `hhvm wikidata-tsv.php WIKIDATA_JSON_DUMP.json OUTPUT.tsv`
* `wikidata-tsv-simple.php` that does not create substatements. It is useful to do statistics or manipulations on the Wikidata content. To use it, run `hhvm wikidata-tsv-simple.php WIKIDATA_JSON_DUMP.json OUTPUT.tsv`

### Outputs the number of statement per property in a TSV file

Run `hhvm tsv-property-stat.php FILE.tsv`

## Mapping between Freebase topic and Wikidata items tools

They are in the `mapping` directory.

### from Wikidata TSV file

Run `python wikidata2pairs.py WIKIDATA_TSV_FILE.tsv MAPPING_FILE.pairs`

### from Samsung mapping

Download [latest Samsung mapping](https://github.com/Samsung/KnowledgeSharingPlatform/tree/master/sameas/freebase-wikidata).

Run `python samsung2pairs.py SAMSUNG_MAPPING.nt MAPPING_FILE.pairs`

### doing reconciliation based on shared ids

Creates a mapping based on Freebase keys and Wikidata external ids.

Run `hhvm freebase-wikidata-reconciliation-keys.php WIKIDATA_TSV_FILE FREEBASE_FACTS.nt MAPPING_FILE.pairs`

## Main conversion tools

They are in the `convert` directory.

### Split Freebase dump

Create smaller dumps from the big freebase dump

Download [the latest freebase dump](https://developers.google.com/freebase/data).

Execute `python explode-dump.py freebase-rdf-latest.gz FITERED_FACTS_FILE.nt TYPES_FILE.tsv LABELS_FILE.tsv`

You could replace some of the output files by `/dev/null` if you do not care about them.

### Main conversion script

Create Wikidata statement from Freebase dump.

Warning running this script requires a lot of RAM (something like 20GB with HHVM).

It has some dependences managed using [Composer](https://getcomposer.org/).

To install them do the usual `curl -sS https://getcomposer.org/installer | php` then `hhvm composer.phar install`.

Run `hhvm convert.php FILTERED_FACTS_FILE.nt REFERENCES_FILE.tsv MAPPINGS_DIRECTORY OUTPUT_DIRECTORY`

This scripts creates in the output directory:

* `freebase-cvt-ids.csv` with the ids of all CVTs (reused if you run again the script with the same target directory)
* `cvt-triples.tsv` with all the CVTs facts (reused if you run again the script with the same target directory)
* `reviewed-facts.tsv` with all the reviewed facts (reused if you run again the script with the same target directory)
* `freebase-mapped.tsv` the created Wikidata statements in TSV format
* `coordinates-mapped.tsv` the created geocoordinates in the TSV format
* `reviewed-mapped.tsv` the created Wikidata reviewed statements in the TSV format

It uses the mapping defined in https://www.wikidata.org/wiki/Wikidata:WikiProject_Freebase/Mapping

It use as mapping between Freebase topics and Wikidata items all the `.pairs` files contained in the `MAPPINGS_DIRECTORY`. It use them in alphabetic order and, when there is a conflict, overrides the mapping. So, you should order your mappings by increasing quality.
If the mapping directory contains a file `wikidata-redirects.tsv` in the format `FROM_QID\tTARGET_QID\n` it is used to resolve Wikidata redirections.

The references file is not released. To run the script without it, just replace it with `/dev/null`.

To create `wikidata-redirects.tsv` you could use this script on Wikimedia Tools Labs:
```
$pdo = new PDO('mysql:host=wikidatawiki.labsdb;dbname=wikidatawiki_p', MY_DB_USERNAME, MY_DB_PASSWORD);
foreach($pdo->query('SELECT rd_title,page_title FROM redirect INNER JOIN page ON rd_from=page_id WHERE rd_namespace=0 AND page_namespace=0') as $row) {
	echo $row['page_title'] . "\t" . $row['rd_title'] . "\n";
}
```
### Filter statements

Allows to filter TSV files. Example of use case: filter statements already in Wikidata.

This script also removes duplicate statements.

Run `python filter.py STATEMENTS.tsv STATEMENTS_TO_FILTER.nt STATEMENT_AFTER_FILTER.nt STATEMENT_FILTERED.nt`

### Missing labels

Creates labels from Freebase when they are missing in Wikidata. For details of the conversion of the language codes, see the source code. It uses the mapping directory in the same way as `convert.php`.

Run `hhvm labels.php WIKIDATA_DUMP.json FREEBASE_LABELS_FILE.tsv MAPPINGS_DIRECTORY OUTPUT_DIRECTORY`

This scripts creates in the output directory:

* `wikidata-labels-languages.tsv` with for each Wikidata entity the list of languages having a label (reused if you run again the script with the same target directory)
* `freebase-new-labels.tsv` the missing labels

### Property statistical mapping

Compares Wikidata and Freebase content in order to suggest additional mapping between Freebase and Wikidata properties. Only works for properties which domain is topic and range is topic or string. It uses the mapping directory in the same way as `convert.php`.

Run `hhvm properties-statistical-mapping.php WIKIDATA_TSV.tsv FREEBASE_FACTS_FILE.nt MAPPINGS_DIRECTORY OUTPUT_CSV_FILE.csv`

## Bot

### Import statement

Small very hacky script to import TSV encoded statement into Wikidata. Warning: contains some Freebase specific code!

Run `python import-statements.py MY_TSV_FILE.tsv`
