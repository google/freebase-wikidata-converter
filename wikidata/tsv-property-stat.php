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

$input = fopen($argv[1], 'r');

//Count properties
$count = 0;
$props = [];
while($line = fgets($input)) {
	list($s, $p, $o) = explode("\t", $line);

	if(array_key_exists($p, $props)) {
		$props[$p]++;
	} else {
		$props[$p] = 1;
	}
	
	$count++;
	if($count % 1000000 === 0) {
		echo '.';
	}
}

arsort($props);

foreach($props as $prop => $v) {
	echo '# {{P|' . str_replace('P', '', $prop) . '}} ' . $v . "\n";
}

